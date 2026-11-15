<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Enum;

/** What a story shows. */
enum Kind: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Text = 'text';

    public function needsMedia(): bool
    {
        return $this !== self::Text;
    }

    /** The media kind an upload must be for this story kind. */
    public function mediaKind(): ?string
    {
        return match ($this) {
            self::Photo => 'image',
            self::Video => 'video',
            self::Text => null,
        };
    }
}
