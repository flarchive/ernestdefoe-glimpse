<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Media;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Glimpse's own disk (`ernestdefoe-glimpse`): storage/glimpse by default, outside the public folder,
 * so a file is only ever served through Http\MediaController after the visibility check. A forum can
 * point the disk at any Flarum storage driver. Files get random names; no part of a path comes from
 * the upload.
 */
class Storage
{
    public const DISK = 'ernestdefoe-glimpse';

    private const PATH = '#^\d{4}/\d{2}/[0-9a-f]{32}\.(webp|gif|mp4|webm)$#';

    public function __construct(private readonly Factory $filesystems)
    {
    }

    public function disk(): Filesystem
    {
        return $this->filesystems->disk(self::DISK);
    }

    /** @param string|StreamInterface $contents bytes, or a stream (written as one, never read into memory) */
    public function put(string|StreamInterface $contents, string $extension): string
    {
        $path = date('Y/m').'/'.bin2hex(random_bytes(16)).'.'.$extension;
        // A disk that couldn't write says so with false: stop here, before a row points at nothing.
        if ($this->disk()->put($path, $contents) !== true) {
            throw new RuntimeException("Glimpse could not write $path to its media disk.");
        }

        return $path;
    }

    /** @param iterable<mixed> $paths only paths this class wrote are touched */
    public function delete(iterable $paths): void
    {
        $valid = [];
        foreach ($paths as $path) {
            if (is_string($path) && preg_match(self::PATH, $path) === 1) {
                $valid[] = $path;
            }
        }

        if ($valid !== []) {
            $this->disk()->delete($valid);
        }
    }
}
