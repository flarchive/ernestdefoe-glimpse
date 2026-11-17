<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Media;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Cleanup;
use ErnestDefoe\Glimpse\Config;
use ErnestDefoe\Glimpse\Ids;
use ErnestDefoe\Glimpse\MemberLock;
use ErnestDefoe\Glimpse\StoryMedia;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Exceptions\RuntimeException as ImageException;
use Intervention\Image\ImageManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Turns an upload into a StoryMedia row and a clean file.
 *
 * - The size is checked before anything is read, against the upload's real size.
 * - The type comes from the bytes (Sniffer); the file name and the browser's claimed type are ignored.
 * - Photos are decoded and re-encoded (WebP; an animated GIF stays a GIF) with Intervention Image,
 *   which drops EXIF and GPS and anything hidden after the picture, and scaled down to fit 1440×2560.
 * - Videos are MP4 or WebM, kept as they are apart from their metadata, which is scrubbed
 *   (Mp4Scrubber / WebmScrubber), and refused when longer than the admin's limit.
 */
class Uploader
{
    public const MAX_WIDTH = 1440;

    public const MAX_HEIGHT = 2560;

    /** Pixels a header may claim before the decoder is trusted with it. */
    private const MAX_PIXELS = 40_000_000;

    /** Uploads a member may hold that no story uses yet, counting the one being made: enough to compose, not to fill a disk. */
    public const MAX_UNUSED = 10;

    /** Frames an animated GIF may have, however small. */
    private const MAX_FRAMES = 300;

    public function __construct(
        private readonly Storage $storage,
        private readonly Config $config,
        private readonly ImageManager $images,
        private readonly TranslatorInterface $translator,
        private readonly Mp4Scrubber $mp4,
        private readonly WebmScrubber $webm,
        private readonly StreamFactory $streams,
        private readonly MemberLock $locks,
        private readonly Cleanup $cleanup,
    ) {
    }

    public function upload(User $actor, UploadedFileInterface $file): StoryMedia
    {
        // Under the member's lock, so uploads sent at once can't pass the cap together. Two minutes:
        // a large video takes a while to copy and scrub, and the lock must not lapse during it.
        return $this->locks->run('upload', $actor->id, 'file', function () use ($actor, $file): StoryMedia {
            $this->makeRoom($actor);

            return $this->store($actor, $file);
        }, 120);
    }

    /**
     * Keeps at most MAX_UNUSED uploads a member hasn't shared: the create sheet uploads every pick, so
     * the oldest unused ones make way (with their files) instead of the member being refused.
     */
    private function makeRoom(User $actor): void
    {
        $stale = Ids::of(StoryMedia::query()
            ->where('user_id', $actor->id)
            ->whereNotExists(fn (QueryBuilder $used) => $used->select('glimpse_stories.media_id')
                ->from('glimpse_stories')
                ->whereColumn('glimpse_stories.media_id', 'glimpse_media.id'))
            ->orderByDesc('id')
            ->skip(self::MAX_UNUSED - 1)
            ->limit(100)
            ->pluck('id'));
        if ($stale !== []) {
            $this->cleanup->uploads($stale);
        }
    }

    private function store(User $actor, UploadedFileInterface $file): StoryMedia
    {
        $type = $this->check($file);

        $media = new StoryMedia();
        $media->user_id = $actor->id;
        $media->kind = $type['kind'];
        $media->created_at = Carbon::now();

        $stream = $file->getStream();
        $stream->rewind();
        $type['kind'] === 'image'
            ? $this->storeImage($media, $stream->getContents(), $type['format'] === 'gif')
            : $this->storeVideo($media, $stream, $type['format']);

        try {
            $media->save();
        } catch (\Throwable $e) {
            // The file is already stored: don't leave it behind with no row pointing at it.
            $this->storage->delete([$media->path]);

            throw $e;
        }

        return $media;
    }

    /**
     * The upload's real type, after the size checks: the overall cap before anything is read, the cap
     * for its kind once the first bytes say what it is.
     *
     * @return array{kind: 'image'|'video', format: 'jpeg'|'png'|'gif'|'webp'|'mp4'|'webm'}
     */
    private function check(UploadedFileInterface $file): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            $this->fail('upload_failed');
        }

        $size = (int) $file->getSize();
        if ($size < 1 || $size > max($this->config->imageMaxBytes(), $this->config->videoMaxBytes())) {
            $this->fail('too_large');
        }

        $stream = $file->getStream();
        $stream->rewind();
        $type = Sniffer::sniff($stream->read(64));
        if ($type === null) {
            $this->fail('bad_type');
        }

        if ($size > ($type['kind'] === 'image' ? $this->config->imageMaxBytes() : $this->config->videoMaxBytes())) {
            $this->fail('too_large');
        }

        return $type;
    }

    private function storeImage(StoryMedia $media, string $bytes, bool $gif): void
    {
        [$encoded, $width, $height, $stayedGif] = $this->image($bytes, $gif);
        $media->mime = $stayedGif ? 'image/gif' : 'image/webp';
        $media->width = $width;
        $media->height = $height;
        $media->path = $this->storage->put($encoded, $stayedGif ? 'gif' : 'webp');
        $media->size = strlen($encoded);
    }

    /**
     * Copied in pieces to a php://temp stream (in memory up to 2 MB, spilled to a temporary file past
     * that), scrubbed there and streamed to storage, so a video is never held in memory.
     *
     * @param 'jpeg'|'png'|'gif'|'webp'|'mp4'|'webm' $format
     */
    private function storeVideo(StoryMedia $media, StreamInterface $upload, string $format): void
    {
        $scratch = $this->streams->createStream();
        try {
            while (! $upload->eof()) {
                $scratch->write($upload->read(1048576));
            }
            $this->storeScrubbed($media, $scratch, $format);
        } finally {
            $scratch->close();
        }
    }

    /** @param 'jpeg'|'png'|'gif'|'webp'|'mp4'|'webm' $format */
    private function storeScrubbed(StoryMedia $media, StreamInterface $video, string $format): void
    {
        $clean = $format === 'mp4' ? $this->mp4->scrub($video) : $this->webm->scrub($video);
        // An MP4 always declares its length (moov/mvhd): one that doesn't isn't a video we can check.
        // A WebM straight from a browser's recorder declares none; it is still capped by size, and the
        // viewer never plays past the admin's limit.
        // A declared length below zero (a 64-bit MP4 duration past PHP's int range, or a negative WebM
        // float) isn't a video we can check either.
        if ($clean === null || ($format === 'mp4' && $clean['durationMs'] === null) || ($clean['durationMs'] ?? 0) < 0) {
            $this->fail('bad_type');
        }
        if ($clean['durationMs'] !== null && $clean['durationMs'] > $this->config->videoMaxSeconds() * 1000 + 500) {
            $this->fail('too_long', ['{max}' => $this->config->videoMaxSeconds()]);
        }

        $media->mime = $format === 'mp4' ? 'video/mp4' : 'video/webm';
        $media->duration_ms = $clean['durationMs'];
        $media->size = (int) $video->getSize();
        $video->rewind();
        $media->path = $this->storage->put($video, $format === 'mp4' ? 'mp4' : 'webm');
    }

    /** @return array{0: string, 1: int, 2: int, 3: bool} the encoded bytes, width, height, and whether it stayed a GIF */
    private function image(string $bytes, bool $gif): array
    {
        // Dimensions from the header before decoding, so a small file claiming to be huge never reaches the decoder.
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            $this->fail('bad_type');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            $this->fail('too_many_pixels', ['{max}' => intdiv(self::MAX_PIXELS, 1_000_000)]);
        }
        // The decoder turns every GIF frame into an image: count them before it starts.
        if ($gif) {
            $frames = GifFrames::count($bytes);
            if ($frames === null || $frames < 1) {
                $this->fail('bad_type');
            }
            if ($frames > self::MAX_FRAMES) {
                $this->fail('too_many_frames');
            }
        }

        try {
            $image = $this->images->read($bytes);
        } catch (ImageException) {
            // Not a picture the decoder can read, whatever its first bytes said.
            $this->fail('bad_type');
        }

        $animated = $gif && $image->isAnimated();
        if (! $animated) {
            $image->orient();
        }
        $image->scaleDown(self::MAX_WIDTH, self::MAX_HEIGHT);

        $encoded = $animated
            ? (string) $image->encode(new GifEncoder())
            : (string) $image->encode(new WebpEncoder(quality: 82));

        return [$encoded, $image->width(), $image->height(), $animated];
    }

    /** @param array<string, int|string> $params */
    private function fail(string $key, array $params = []): never
    {
        throw new ValidationException(['file' => $this->translator->trans('ernestdefoe-glimpse.api.'.$key, $params)]);
    }
}
