<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Media;

/**
 * Counts a GIF's frames by walking its blocks, without decoding a pixel: a decoder turns every frame
 * into a full-canvas image, so the uploader weighs canvas × frames before it lets one start.
 */
final class GifFrames
{
    /** The number of frames, or null when a block is neither a GIF block nor the end of the file. */
    public static function count(string $bytes): ?int
    {
        $length = strlen($bytes);
        if ($length < 13) {
            return null;
        }
        // Header (6) and logical screen descriptor (7), then the global color table if there is one.
        $pos = 13 + self::colorTable(ord($bytes[10]));
        $frames = 0;

        while ($pos < $length) {
            switch ($bytes[$pos]) {
                case "\x3B": // Trailer.
                    return $frames;
                case "\x21": // Extension: label, then sub-blocks.
                    $pos = self::skipSubBlocks($bytes, $pos + 2);
                    break;
                case "\x2C": // Image descriptor (10 bytes), local color table, LZW code size, sub-blocks.
                    // A frame cut off at the end still counts: decoders show what arrived of it.
                    $frames++;
                    if ($pos + 10 > $length) {
                        return $frames;
                    }
                    $pos = self::skipSubBlocks($bytes, $pos + 10 + self::colorTable(ord($bytes[$pos + 9])) + 1);
                    break;
                case "\x00": // A stray empty block between frames, which some encoders write.
                    $pos++;
                    break;
                default:
                    return null;
            }
            if ($pos === null) {
                return $frames;
            }
        }

        // No trailer: decoders still show what came before it.
        return $frames;
    }

    /** Bytes of the color table a packed field announces. */
    private static function colorTable(int $packed): int
    {
        return ($packed & 0x80) !== 0 ? 3 * (2 << ($packed & 0x07)) : 0;
    }

    /** The position after a run of sub-blocks ending in a zero-length one, or null past the end. */
    private static function skipSubBlocks(string $bytes, int $pos): ?int
    {
        $length = strlen($bytes);
        while ($pos < $length) {
            $size = ord($bytes[$pos]);
            $pos += 1 + $size;
            if ($size === 0) {
                return $pos;
            }
        }

        return null;
    }
}
