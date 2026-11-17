<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use ErnestDefoe\Glimpse\Media\Storage;
use ErnestDefoe\Glimpse\StoryMedia;
use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Uploads: the type comes from the bytes, photos are re-encoded (EXIF and GPS gone), videos keep their
 * picture but lose their metadata, and every cap holds. Real files in tests/fixtures.
 */
class MediaTest extends GlimpseTestCase
{
    protected bool $follows = false;

    private function stored(string $id): string
    {
        $media = StoryMedia::query()->findOrFail((int) $id);

        return (string) $this->app()->getContainer()->make(Storage::class)->disk()->get($media->path);
    }

    #[Test]
    public function a_photo_is_re_encoded_and_loses_its_camera_and_location(): void
    {
        $original = $this->fixture('gps.jpg');
        $this->assertStringContainsString('SecretCam', $original);

        $response = $this->upload(2, $original, 'holiday.php', 'text/html');
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        $this->assertSame(['image', 'image/webp'], [$data['attributes']['kind'], $data['attributes']['mime']]);

        $stored = $this->stored($data['id']);
        $this->assertStringStartsWith('RIFF', $stored);
        $this->assertStringNotContainsString('SecretCam', $stored);
        $this->assertStringNotContainsString('GPS', $stored);

        $media = StoryMedia::query()->findOrFail((int) $data['id']);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f]{32}\.webp$#', $media->path);
        $this->assertStringNotContainsString('holiday', $media->path);
    }

    #[Test]
    public function a_large_photo_is_scaled_down(): void
    {
        $data = $this->json($this->upload(2, $this->jpeg(3000, 2000)))['data']['attributes'];
        $this->assertSame([1440, 960], [$data['width'], $data['height']]);
    }

    #[Test]
    public function an_mp4_loses_its_location_and_title_but_keeps_its_length(): void
    {
        $original = $this->fixture('location.mp4');
        $this->assertStringContainsString('40.7128', $original);

        $response = $this->upload(2, $original, 'clip.mp4', 'video/mp4');
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        $this->assertSame('video/mp4', $data['attributes']['mime']);
        $this->assertSame(2000, $data['attributes']['durationMs']);

        $stored = $this->stored($data['id']);
        $this->assertSame(strlen($original), strlen($stored), 'Nothing moves: sample offsets stay right.');
        $this->assertStringNotContainsString('40.7128', $stored);
        $this->assertStringNotContainsString('Secret title', $stored);
        $this->assertStringContainsString('mdat', $stored);
        // Byte for byte what 1.0.5 stored (it scrubbed a native file handle; now a PSR-7 stream).
        $this->assertSame('fcac20f44576d5c5f066587cb042b6fe1abae1f7641a3b62eed9ec3c14d06e65', hash('sha256', $stored));
    }

    #[Test]
    public function an_mp4_that_does_not_declare_its_length_is_refused(): void
    {
        // A valid ftyp box and then junk: no moov, so no duration to check against the limit.
        $junk = pack('N', 20).'ftypisom'.pack('N', 0).'isom'.pack('N', 16).'mdat'.str_repeat("\0", 8);
        $this->assertSame(422, $this->upload(2, $junk, 'clip.mp4', 'video/mp4')->getStatusCode());
        $this->assertSame(0, StoryMedia::query()->count());
    }

    #[Test]
    public function a_webm_loses_its_tags(): void
    {
        $original = $this->fixture('tagged.webm');
        $this->assertStringContainsString('Webm secret', $original);

        $response = $this->upload(2, $original, 'clip.webm', 'video/webm');
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $stored = $this->stored($this->json($response)['data']['id']);

        $this->assertSame(strlen($original), strlen($stored));
        $this->assertStringNotContainsString('Webm secret', $stored);
        $this->assertSame('a5f266451f1ba388ca57528f77ef978991b7c9937e4445f3465171ad9b4179b7', hash('sha256', $stored));
        $this->assertSame(2000, $this->json($response)['data']['attributes']['durationMs']);
    }

    #[Test]
    public function a_video_longer_than_the_limit_is_refused(): void
    {
        $this->assertSame(422, $this->upload(2, $this->fixture('long.mp4'), 'long.mp4', 'video/mp4')->getStatusCode());
        $this->assertSame(0, StoryMedia::query()->count());
    }

    #[Test]
    public function the_admin_can_allow_longer_videos(): void
    {
        $this->setting('ernestdefoe-glimpse.video_max_seconds', 40);
        $this->assertSame(201, $this->upload(2, $this->fixture('long.mp4'), 'long.mp4', 'video/mp4')->getStatusCode());
    }

    #[Test]
    public function spoofed_files_are_refused_whatever_they_claim_to_be(): void
    {
        $this->assertSame(422, $this->upload(2, $this->fixture('shell.jpg'), 'shell.jpg', 'image/jpeg')->getStatusCode());
        $this->assertSame(422, $this->upload(2, $this->fixture('polyglot.jpg'), 'polyglot.jpg', 'image/jpeg')->getStatusCode());
        $this->assertSame(422, $this->upload(2, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'a.png', 'image/png')->getStatusCode());
        $this->assertSame(422, $this->upload(2, '<html><script>alert(1)</script></html>', 'a.gif', 'image/gif')->getStatusCode());
        // A header claiming a huge picture never reaches the decoder.
        $this->assertSame(422, $this->upload(2, "\x89PNG\r\n\x1A\n\0\0\0\rIHDR".pack('NN', 100000, 100000)."\x08\x02\0\0\0", 'bomb.png', 'image/png')->getStatusCode());
        $this->assertSame(0, StoryMedia::query()->count());
    }

    #[Test]
    public function an_oversized_file_is_refused_before_it_is_read(): void
    {
        $this->setting('ernestdefoe-glimpse.image_max_mb', 1);
        $this->setting('ernestdefoe-glimpse.video_max_mb', 1);
        $big = "\xFF\xD8\xFF".str_repeat('a', 1024 * 1024 + 10);
        $this->assertSame(422, $this->upload(2, $big, 'big.jpg')->getStatusCode());
        $this->assertSame(0, StoryMedia::query()->count());
    }
}
