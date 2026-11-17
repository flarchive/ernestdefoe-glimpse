<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Media;

/**
 * Removes the metadata a phone writes into an MP4 or QuickTime file (the place it was filmed,
 * the device, the owner) without re-encoding it, and reads its duration.
 *
 * Metadata lives in `udta`, `meta` and `uuid` (XMP) boxes, at the top level, in `moov` and in each
 * `trak`. Each one is overwritten in place with a `free` box of the same size full of zeros: no box
 * moves, so the sample offsets in `stco`/`co64` stay right and the file plays exactly as before.
 */
class Mp4Scrubber
{
    private const METADATA = ['udta', 'meta', 'uuid'];

    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'edts'];

    private ?int $durationMs = null;

    /** @return array{durationMs: int|null}|null null when the file isn't a readable MP4; the file is scrubbed in place */
    public function scrub(SeekableFile $file): ?array
    {
        $this->durationMs = null;

        if (! $this->walk($file, 0, $file->size(), 0)) {
            return null;
        }

        return ['durationMs' => $this->durationMs];
    }

    /** Walks the boxes between $start and $end, scrubbing as it goes. False when a box is malformed. */
    private function walk(SeekableFile $file, int $start, int $end, int $depth): bool
    {
        if ($depth > 8) {
            return false;
        }

        $pos = $start;
        while ($pos + 8 <= $end) {
            $header = 8;
            $size = $file->uint32($pos);
            $type = $file->read($pos + 4, 4);

            if ($size === 1) {
                if ($pos + 16 > $end) {
                    return false;
                }
                $size = $file->uint64($pos + 8);
                $header = 16;
            } elseif ($size === 0) {
                // "To the end of the file": only the last box may do this.
                $size = $end - $pos;
            }

            if ($size < $header || $pos + $size > $end) {
                return false;
            }

            if (in_array($type, self::METADATA, true)) {
                // A zero-filled `free` box of the same length, keeping a 64-bit size if it had one.
                $file->overwrite($pos, $header === 16 ? pack('N', 1).'free'.pack('J', $size) : pack('N', $size).'free', $size);
            } elseif (in_array($type, self::CONTAINERS, true)) {
                if (! $this->walk($file, $pos + $header, $pos + $size, $depth + 1)) {
                    return false;
                }
            } elseif ($type === 'mvhd') {
                $this->readDuration($file, $pos + $header, $pos + $size);
            }

            $pos += $size;
        }

        return true;
    }

    private function readDuration(SeekableFile $file, int $start, int $end): void
    {
        $first = $file->read($start, 1);
        $version = $first === '' ? 0 : ord($first);
        // version(1) flags(3), then created/modified (4+4 or 8+8), timescale (4), duration (4 or 8).
        $offset = $version === 1 ? $start + 20 : $start + 12;
        $durationSize = $version === 1 ? 8 : 4;
        if ($offset + 4 + $durationSize > $end) {
            return;
        }

        $scale = $file->uint32($offset);
        $duration = $version === 1 ? $file->uint64($offset + 4) : $file->uint32($offset + 4);
        if ($scale > 0) {
            $this->durationMs = (int) round($duration / $scale * 1000);
        }
    }
}
