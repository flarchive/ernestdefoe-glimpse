<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Media;

use Psr\Http\Message\StreamInterface;

/**
 * Reads and overwrites bytes at an offset of a seekable PSR-7 stream, so a video is scrubbed where it
 * lies instead of being held in memory (uploads go up to the admin's video limit, 200 MB at most).
 */
final class StreamBytes
{
    /**
     * Boxes or elements a scrub may visit in all. Real videos have a few hundred at most; a file of
     * thousands of tiny empty ones exists only to keep the server busy, and is refused.
     */
    public const MAX_ELEMENTS = 10_000;

    private int $elements = 0;

    public function __construct(private readonly StreamInterface $stream)
    {
    }

    /** Counts one more box or element; false once there have been too many to be a real video. */
    public function nextElement(): bool
    {
        return ++$this->elements <= self::MAX_ELEMENTS;
    }

    public function size(): int
    {
        return (int) $this->stream->getSize();
    }

    /** Up to $length bytes from $pos (fewer at the end of the stream, none past it). */
    public function read(int $pos, int $length): string
    {
        if ($length < 1 || $pos < 0) {
            return '';
        }
        $this->stream->seek($pos);
        // A stream may return fewer bytes than asked for before its end.
        $bytes = '';
        while (strlen($bytes) < $length && ! $this->stream->eof()) {
            $piece = $this->stream->read($length - strlen($bytes));
            if ($piece === '') {
                break;
            }
            $bytes .= $piece;
        }

        return $bytes;
    }

    /**
     * Writes $head at $pos, then zeros up to $length bytes in all, in small pieces. Never past the end:
     * scrubbing replaces bytes, it never grows the stream.
     */
    public function overwrite(int $pos, string $head, int $length): void
    {
        $length = min($length, $this->size() - $pos);
        if ($pos < 0 || $length < 1) {
            return;
        }
        $head = substr($head, 0, $length);
        $this->stream->seek($pos);
        $this->stream->write($head);
        for ($left = $length - strlen($head); $left > 0; $left -= 65536) {
            $this->stream->write(str_repeat("\0", min(65536, $left)));
        }
    }

    public function uint32(int $pos): int
    {
        $value = unpack('N', str_pad($this->read($pos, 4), 4, "\0"));

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }

    public function uint64(int $pos): int
    {
        $value = unpack('J', str_pad($this->read($pos, 8), 8, "\0"));

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }
}
