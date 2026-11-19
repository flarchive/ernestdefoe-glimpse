<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\unit;

use ErnestDefoe\Glimpse\Media\GifFrames;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GifFramesTest extends TestCase
{
    /** A GIF of $frames 1×1 frames on a $width × $height canvas, each with a graphic control extension. */
    public static function gif(int $frames, int $width = 1, int $height = 1): string
    {
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00"."\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00"."\x02\x02\x44\x01\x00";

        return 'GIF89a'.pack('vv', $width, $height)."\x80\x00\x00"."\x00\x00\x00\xFF\xFF\xFF"
            ."\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00"
            .str_repeat($frame, $frames)."\x3B";
    }

    #[Test]
    public function frames_are_counted_from_the_blocks(): void
    {
        $this->assertSame(1, GifFrames::count(self::gif(1)));
        $this->assertSame(12, GifFrames::count(self::gif(12, 2000, 2000)));
        // No trailer: what came before still counts.
        $this->assertSame(3, GifFrames::count(substr(self::gif(3), 0, -1)));
    }

    #[Test]
    public function what_decoders_still_open_is_counted_and_anything_else_is_not_a_gif(): void
    {
        // Cut off in the middle of its second frame, or with a stray empty block before the trailer.
        $this->assertSame(2, GifFrames::count(substr(self::gif(2), 0, -6)));
        $this->assertSame(2, GifFrames::count(substr(self::gif(2), 0, -1)."\x00\x3B"));

        $this->assertNull(GifFrames::count('GIF89a'));
        $this->assertNull(GifFrames::count(substr(self::gif(1), 0, -1)."\x99"));
    }
}
