<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Media;

/**
 * Removes the metadata of a WebM file (Tags, Attachments, and the Info element's Title) without
 * re-encoding it, and reads its duration from the Info element.
 *
 * Each removed element is overwritten in place with an EBML Void element of the same length full of
 * zeros, so nothing moves and the SeekHead and Cues stay right. Elements after a Cluster of unknown
 * size (a live recording) can't be reached; recorders don't write tags there.
 */
class WebmScrubber
{
    private const SEGMENT = 0x18538067;

    private const CLUSTER = 0x1F43B675;

    private const INFO = 0x1549A966;

    private const TIMECODE_SCALE = 0x2AD7B1;

    private const DURATION = 0x4489;

    private const TITLE = 0x7BA9;

    /** Tags and Attachments. */
    private const METADATA = [0x1254C367, 0x1941A469];

    /** @return array{durationMs: int|null}|null null when the file isn't a readable WebM; the file is scrubbed in place */
    public function scrub(SeekableFile $file): ?array
    {
        $end = $file->size();
        $header = $this->element($file, 0, $end);
        if ($header === null || $header['size'] === null) {
            return null;
        }

        $segment = $this->element($file, $header['dataStart'] + $header['size'], $end);
        if ($segment === null || $segment['id'] !== self::SEGMENT) {
            return null;
        }

        $segmentEnd = $segment['size'] === null ? $end : min($end, $segment['dataStart'] + $segment['size']);
        $durationMs = null;
        $pos = $segment['dataStart'];

        while ($pos < $segmentEnd) {
            $child = $this->element($file, $pos, $segmentEnd);
            if ($child === null) {
                break;
            }
            if ($child['size'] === null) {
                // An unknown-size Cluster: nothing after it can be found without parsing every block.
                if ($child['id'] === self::CLUSTER) {
                    break;
                }

                return null;
            }

            $childEnd = $child['dataStart'] + $child['size'];
            if ($childEnd > $segmentEnd) {
                return null;
            }

            if (in_array($child['id'], self::METADATA, true)) {
                $this->void($file, $pos, $childEnd - $pos);
            } elseif ($child['id'] === self::INFO) {
                $durationMs = $this->duration($file, $child['dataStart'], $childEnd);
            }

            $pos = $childEnd;
        }

        return ['durationMs' => $durationMs];
    }

    /** Reads the duration from Info, and voids its Title on the way. */
    private function duration(SeekableFile $file, int $start, int $end): ?int
    {
        $scale = 1_000_000;
        $duration = null;
        $pos = $start;

        while ($pos < $end) {
            $el = $this->element($file, $pos, $end);
            if ($el === null || $el['size'] === null) {
                break;
            }
            if ($el['id'] === self::TIMECODE_SCALE && $el['size'] >= 1 && $el['size'] <= 8) {
                $scale = (int) hexdec(bin2hex($file->read($el['dataStart'], $el['size'])));
            } elseif ($el['id'] === self::DURATION && ($el['size'] === 4 || $el['size'] === 8)) {
                $float = unpack($el['size'] === 4 ? 'G' : 'E', $file->read($el['dataStart'], $el['size']));
                $duration = is_array($float) && is_float($float[1] ?? null) ? $float[1] : null;
            } elseif ($el['id'] === self::TITLE) {
                $this->void($file, $pos, $el['dataStart'] + $el['size'] - $pos);
            }
            $pos = $el['dataStart'] + $el['size'];
        }

        return $duration === null || $scale < 1 ? null : (int) round($duration * $scale / 1_000_000);
    }

    /**
     * Replaces $length bytes at $pos with a Void element (id 0xEC) of exactly that length.
     */
    private function void(SeekableFile $file, int $pos, int $length): void
    {
        if ($length < 2) {
            return;
        }
        // 1 byte of id, then a size vint as long as fits (at most 8 bytes), then zeros.
        $sizeLength = min(8, $length - 1);
        $payload = $length - 1 - $sizeLength;
        $vint = '';
        for ($i = $sizeLength - 1; $i >= 0; $i--) {
            $vint .= chr(($payload >> (8 * $i)) & 0xFF);
        }
        $vint[0] = chr((ord($vint[0]) | (0x80 >> ($sizeLength - 1))) & 0xFF);

        $file->overwrite($pos, "\xEC".$vint, $length);
    }

    /** @return array{id: int, size: int|null, dataStart: int}|null size null = unknown */
    private function element(SeekableFile $file, int $pos, int $end): ?array
    {
        $id = $this->vint($file->read($pos, min(8, $end - $pos)), true);
        if ($id === null) {
            return null;
        }
        $sizeAt = $pos + $id['length'];
        $size = $this->vint($file->read($sizeAt, min(8, $end - $sizeAt)), false);
        if ($size === null) {
            return null;
        }

        return ['id' => $id['value'], 'size' => $size['unknown'] ? null : $size['value'], 'dataStart' => $sizeAt + $size['length']];
    }

    /**
     * A variable-length integer at the start of $bytes (the next ≤ 8 bytes of the file, fewer at its end).
     *
     * @return array{value: int, length: int, unknown: bool}|null
     */
    private function vint(string $bytes, bool $keepMarker): ?array
    {
        if ($bytes === '') {
            return null;
        }
        $first = ord($bytes[0]);
        $length = 1;
        while ($length <= 8 && ($first & (0x80 >> ($length - 1))) === 0) {
            $length++;
        }
        if ($length > 8 || $length > strlen($bytes) || ($keepMarker && $length > 4)) {
            return null;
        }

        $value = $keepMarker ? $first : $first & (0xFF >> $length);
        $allOnes = $value === (0xFF >> $length);
        for ($i = 1; $i < $length; $i++) {
            $byte = ord($bytes[$i]);
            $value = ($value << 8) | $byte;
            $allOnes = $allOnes && $byte === 0xFF;
        }

        return ['value' => $value, 'length' => $length, 'unknown' => ! $keepMarker && $allOnes];
    }
}
