<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\unit;

use ErnestDefoe\Glimpse\Ids;
use ErnestDefoe\Glimpse\Media\Mp4Scrubber;
use ErnestDefoe\Glimpse\Media\Sniffer;
use ErnestDefoe\Glimpse\Media\WebmScrubber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScrubberTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../fixtures/'.$name);
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
        $clean = (new Mp4Scrubber())->scrub($original);

        $this->assertNotNull($clean);
        $this->assertSame(strlen($original), strlen($clean['bytes']));
        $this->assertSame(2000, $clean['durationMs']);
        $this->assertStringNotContainsString('40.7128', $clean['bytes']);
        $this->assertStringNotContainsString('udta', $clean['bytes']);
        $this->assertStringContainsString('free', $clean['bytes']);
        // Everything before the first metadata box is untouched.
        $this->assertSame(substr($original, 0, 32), substr($clean['bytes'], 0, 32));
    }

    #[Test]
    public function a_malformed_mp4_is_refused(): void
    {
        $this->assertNull((new Mp4Scrubber())->scrub(pack('N', 99999).'ftypisom'));
        $this->assertNull((new Mp4Scrubber())->scrub(pack('N', 4).'moov'));
    }

    #[Test]
    public function a_webm_keeps_its_size_and_loses_its_title_and_tags(): void
    {
        $original = $this->fixture('tagged.webm');
        $clean = (new WebmScrubber())->scrub($original);

        $this->assertNotNull($clean);
        $this->assertSame(strlen($original), strlen($clean['bytes']));
        $this->assertSame(2000, $clean['durationMs']);
        $this->assertStringNotContainsString('Webm secret', $clean['bytes']);
        $this->assertNull((new WebmScrubber())->scrub("\x1A\x45\xDF\xA3".str_repeat("\xFF", 20)));
    }

    #[Test]
    public function ids_are_clean_positive_integers(): void
    {
        $this->assertSame([3, 7], Ids::of([3, '7', 'x', -2, '0', null, 3, '4.5', ['1']]));
        $this->assertSame(0, Ids::one('12abc'));
    }
}
