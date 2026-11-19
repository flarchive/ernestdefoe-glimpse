<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Media;

use Psr\Http\Message\StreamInterface;
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

    /** @return array{durationMs: int|null}|null null when the stream isn't a readable WebM; the stream is scrubbed in place */
    public function scrub(StreamInterface $stream): ?array
    {
        $file = new StreamBytes($stream);
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

        return $this->segment($file, $segment['dataStart'], $segmentEnd);
    }

    /**
     * Walks the Segment's children: voids Tags and Attachments, reads Info.
     *
     * @return array{durationMs: int|null}|null
     */
    private function segment(StreamBytes $file, int $start, int $segmentEnd): ?array
    {
        $durationMs = null;
        $pos = $start;

        while ($pos < $segmentEnd) {
            if (! $file->nextElement()) {
                return null;
            }
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
                if ($durationMs === false) {
                    return null;
                }
            }

            $pos = $childEnd;
        }

        return ['durationMs' => $durationMs];
    }

    /**
     * Reads the duration from Info, and voids its Title on the way. False when an element inside Info
     * is malformed or claims to run past it: such a file is refused, so a Title can't hide behind it
     * and a crafted size can't make the void write past the element.
     */
    private function duration(StreamBytes $file, int $start, int $end): int|false|null
    {
        $scale = 1_000_000;
        $duration = null;
        $pos = $start;

        while ($pos < $end) {
            if (! $file->nextElement()) {
                return false;
            }
            $el = $this->element($file, $pos, $end);
            if ($el === null || $el['size'] === null || $el['dataStart'] + $el['size'] > $end) {
                return false;
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

        $ms = $duration === null || $scale < 1 ? null : round($duration * $scale / 1_000_000);

        if ($ms === null) {
            return null;
        }

        // Infinite or beyond an int: -1, which the uploader refuses (a cast would be undefined).
        return is_finite($ms) && abs($ms) < 9.0e18 ? (int) $ms : -1;
    }

    /**
     * Replaces $length bytes at $pos with a Void element (id 0xEC) of exactly that length.
     */
    private function void(StreamBytes $file, int $pos, int $length): void
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
    private function element(StreamBytes $file, int $pos, int $end): ?array
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
