<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\unit;

use ErnestDefoe\Glimpse\Ids;
use ErnestDefoe\Glimpse\Media\Mp4Scrubber;
use ErnestDefoe\Glimpse\Media\Sniffer;
use ErnestDefoe\Glimpse\Media\WebmScrubber;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScrubberTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../fixtures/'.$name);
    }

    /** @return array{0: array{durationMs: int|null}|null, 1: string} the result and the scrubbed bytes */
    private function scrub(Mp4Scrubber|WebmScrubber $scrubber, string $bytes): array
    {
        $stream = (new StreamFactory())->createStream($bytes);
        $result = $scrubber->scrub($stream);
        $stream->rewind();

        return [$result, $stream->getContents()];
    }

    #[Test]
    public function a_webm_title_claiming_to_run_past_its_info_is_refused_and_nothing_is_written(): void
    {
        // A Title that says it holds 1 MB, inside a 33-byte file: voiding it by its own size would
        // write a megabyte of zeros (or, with a bigger claim, gigabytes) and grow the upload.
        $title = "\x7B\xA9"."\x10\x10\x00\x00".'x';
        $info = "\x15\x49\xA9\x66".chr(0x80 | strlen($title)).$title;
        $bytes = "\x1A\x45\xDF\xA3\x87\x42\x82\x84webm"."\x18\x53\x80\x67".chr(0x80 | strlen($info)).$info;

        [$clean, $after] = $this->scrub(new WebmScrubber(), $bytes);
        $this->assertNull($clean);
        $this->assertSame($bytes, $after);
    }

    #[Test]
    public function every_fixture_scrubs_to_exactly_the_bytes_it_did_before_streams(): void
    {
        // SHA-256 of each fixture's scrubbed bytes from 1.0.5, which scrubbed a native file handle.
        $expected = [
            'location.mp4' => [2000, 'fcac20f44576d5c5f066587cb042b6fe1abae1f7641a3b62eed9ec3c14d06e65'],
            'long.mp4' => [30000, 'a18b1aa20c4c39a26d3f70a758b72c24efc6688798dd839b49c6268a01d0a91c'],
            'tagged.webm' => [2000, 'a5f266451f1ba388ca57528f77ef978991b7c9937e4445f3465171ad9b4179b7'],
        ];
        foreach ($expected as $name => [$durationMs, $sha256]) {
            [$clean, $bytes] = $this->scrub(str_ends_with($name, '.mp4') ? new Mp4Scrubber() : new WebmScrubber(), $this->fixture($name));
            $this->assertSame(['durationMs' => $durationMs], $clean, $name);
            $this->assertSame($sha256, hash('sha256', $bytes), $name);
        }
    }

    #[Test]
    public function the_sniffer_knows_only_what_the_bytes_say(): void
    {
        $this->assertSame(['kind' => 'image', 'format' => 'jpeg'], Sniffer::sniff($this->fixture('gps.jpg')));
        $this->assertSame(['kind' => 'video', 'format' => 'mp4'], Sniffer::sniff($this->fixture('location.mp4')));
        $this->assertSame(['kind' => 'video', 'format' => 'webm'], Sniffer::sniff($this->fixture('tagged.webm')));
        $this->assertNull(Sniffer::sniff($this->fixture('shell.jpg')));
        $this->assertNull(Sniffer::sniff('<svg xmlns="http://www.w3.org/2000/svg"/>'));
        // An audio-only MP4 is not a video.
        $this->assertNull(Sniffer::sniff(pack('N', 20).'ftypM4A '.str_repeat("\0", 8)));
    }

    #[Test]
    public function an_mp4_keeps_its_size_and_boxes_and_loses_its_metadata(): void
    {
        $original = $this->fixture('location.mp4');
        [$clean, $bytes] = $this->scrub(new Mp4Scrubber(), $original);

        $this->assertNotNull($clean);
        $this->assertSame(strlen($original), strlen($bytes));
        $this->assertSame(2000, $clean['durationMs']);
        $this->assertStringNotContainsString('40.7128', $bytes);
        $this->assertStringNotContainsString('udta', $bytes);
        $this->assertStringContainsString('free', $bytes);
        // Everything before the first metadata box is untouched.
        $this->assertSame(substr($original, 0, 32), substr($bytes, 0, 32));
    }

    #[Test]
    public function a_malformed_mp4_is_refused(): void
    {
        $this->assertNull($this->scrub(new Mp4Scrubber(), pack('N', 99999).'ftypisom')[0]);
        $this->assertNull($this->scrub(new Mp4Scrubber(), pack('N', 4).'moov')[0]);
    }

    #[Test]
    public function a_webm_keeps_its_size_and_loses_its_title_and_tags(): void
    {
        $original = $this->fixture('tagged.webm');
        [$clean, $bytes] = $this->scrub(new WebmScrubber(), $original);

        $this->assertNotNull($clean);
        $this->assertSame(strlen($original), strlen($bytes));
        $this->assertSame(2000, $clean['durationMs']);
        $this->assertStringNotContainsString('Webm secret', $bytes);
        $this->assertNull($this->scrub(new WebmScrubber(), "\x1A\x45\xDF\xA3".str_repeat("\xFF", 20))[0]);
    }

    #[Test]
    public function ids_are_clean_positive_integers(): void
    {
        $this->assertSame([3, 7], Ids::of([3, '7', 'x', -2, '0', null, 3, '4.5', ['1']]));
        $this->assertSame(0, Ids::one('12abc'));
    }
}
