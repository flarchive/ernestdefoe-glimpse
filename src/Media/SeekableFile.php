<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Media;

/**
 * Reads and overwrites bytes at an offset in an open file, so a video is scrubbed where it lies
 * instead of being held in memory (uploads go up to the admin's video limit, 200 MB at most).
 */
final class SeekableFile
{
    /** @param resource $handle opened for reading and writing */
    public function __construct(private $handle)
    {
    }

    public function size(): int
    {
        $stat = fstat($this->handle);

        return is_array($stat) ? $stat['size'] : 0;
    }

    /** Up to $length bytes from $pos (fewer at the end of the file). */
    public function read(int $pos, int $length): string
    {
        if ($length < 1 || fseek($this->handle, $pos) !== 0) {
            return '';
        }
        $bytes = fread($this->handle, $length);

        return is_string($bytes) ? $bytes : '';
    }

    /** Writes $head at $pos, then zeros up to $length bytes in all, in small pieces. */
    public function overwrite(int $pos, string $head, int $length): void
    {
        fseek($this->handle, $pos);
        fwrite($this->handle, $head);
        for ($left = $length - strlen($head); $left > 0; $left -= 65536) {
            fwrite($this->handle, str_repeat("\0", min(65536, $left)));
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
