<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Media;

/**
 * What an upload really is, from its first bytes: never from its name or the type the browser
 * claimed. Only the formats listed here are accepted at all; no SVG, no HTML, nothing a browser runs.
 */
final class Sniffer
{
    /** @return array{kind: 'image'|'video', format: 'jpeg'|'png'|'gif'|'webp'|'mp4'|'webm'}|null */
    public static function sniff(string $head): ?array
    {
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => ['kind' => 'image', 'format' => 'jpeg'],
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => ['kind' => 'image', 'format' => 'png'],
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => ['kind' => 'image', 'format' => 'gif'],
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => ['kind' => 'image', 'format' => 'webp'],
            // ISO base media (MP4, and the QuickTime files phones record): a box size, then "ftyp".
            substr($head, 4, 4) === 'ftyp' && ! self::isAudioBrand(substr($head, 8, 4)) => ['kind' => 'video', 'format' => 'mp4'],
            // Matroska with the "webm" doc type.
            str_starts_with($head, "\x1A\x45\xDF\xA3") && str_contains(substr($head, 0, 64), 'webm') => ['kind' => 'video', 'format' => 'webm'],
            default => null,
        };
    }

    private static function isAudioBrand(string $brand): bool
    {
        return in_array($brand, ['M4A ', 'M4B ', 'M4P ', 'F4A ', 'F4B '], true);
    }
}
