<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Enum;

enum Status: string
{
    /** Waiting for a moderator (a new member's story). */
    case Pending = 'pending';
    case Live = 'live';
    /** Hidden by a moderator: seen only by its author and moderators. */
    case Hidden = 'hidden';
}
