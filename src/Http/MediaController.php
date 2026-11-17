<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Http;

use ErnestDefoe\Glimpse\Ids;
use ErnestDefoe\Glimpse\Media\Storage;
use ErnestDefoe\Glimpse\Story;
use ErnestDefoe\Glimpse\StoryMedia;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\Http\Exception\RouteNotFoundException;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `GET /glimpse/media/{id}`: a story's photo or video, only for someone who may see a story using it
 * (the visibility rule), or who uploaded it (the preview before posting). Never cached by shared
 * caches, so hiding or deleting a story takes its file away from everyone who hasn't loaded it (a
 * browser that already has it may keep its private copy for up to an hour). Supports byte ranges, which
 * Safari needs to play video.
 */
class MediaController implements RequestHandlerInterface
{
    public function __construct(private readonly Storage $storage)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $media = $this->mediaFor($request);

        $disk = $this->storage->disk();
        $source = $disk->readStream($media->path);
        if (! is_resource($source)) {
            throw new RouteNotFoundException();
        }

        $size = $disk->size($media->path);
        [$start, $end] = $this->range($request->getHeaderLine('Range'), $size) ?? [0, $size - 1];
        $partial = $start > 0 || $end < $size - 1;

        $headers = [
            'Content-Type' => $media->mime,
            'Content-Length' => (string) ($end - $start + 1),
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => 'inline',
            // Never let a browser guess another type than the one the upload was sniffed and stored as.
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($partial) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return new Response($partial ? $this->slice($source, $start, $end) : new Stream($source), $partial ? 206 : 200, $headers);
    }

    /** The upload in the route, if the actor uploaded it or may see a story that uses it. */
    private function mediaFor(ServerRequestInterface $request): StoryMedia
    {
        $actor = RequestUtil::getActor($request);
        $parameters = $request->getAttribute('routeParameters');
        $media = StoryMedia::query()->find(Ids::one(is_array($parameters) ? ($parameters['id'] ?? null) : null));
        if ($media === null) {
            throw new RouteNotFoundException();
        }

        $own = ! $actor->isGuest() && $media->user_id === $actor->id;
        if (! $own && ! Story::whereVisibleTo($actor)->where('glimpse_stories.media_id', $media->id)->exists()) {
            throw new PermissionDeniedException();
        }

        return $media;
    }

    /**
     * Bytes $start to $end of the file, through a temporary stream (in memory up to 2 MB).
     *
     * @param resource $source
     */
    private function slice($source, int $start, int $end): Stream
    {
        $this->skip($source, $start);
        $body = new Stream('php://temp', 'w+b');
        for ($left = $end - $start + 1; $left > 0 && ! feof($source);) {
            $chunk = fread($source, min(65536, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body->write($chunk);
            $left -= strlen($chunk);
        }
        fclose($source);
        $body->rewind();

        return $body;
    }

    /** Moves a stream to $offset: a seek where the driver allows it, otherwise reading past the bytes. */
    private function skip(mixed $stream, int $offset): void
    {
        if ($offset < 1 || ! is_resource($stream)) {
            return;
        }
        if (stream_get_meta_data($stream)['seekable'] && fseek($stream, $offset) === 0) {
            return;
        }
        for ($left = $offset; $left > 0 && ! feof($stream);) {
            $chunk = fread($stream, min(65536, $left));
            if ($chunk === false || $chunk === '') {
                return;
            }
            $left -= strlen($chunk);
        }
    }

    /** @return array{0: int, 1: int}|null a single satisfiable byte range, or null for the whole file */
    private function range(string $header, int $size): ?array
    {
        if ($size < 1 || preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return null;
        }

        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]);
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }

        return $start <= $end && $start < $size ? [$start, $end] : null;
    }
}
