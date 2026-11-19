<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Enum;

/** Who may see a story, besides its author and moderators. */
enum Audience: string
{
    /** Everyone allowed to view stories, guests included when they have the permission. */
    case Everyone = 'everyone';
    /** Signed-in members only. */
    case Members = 'members';
    /** The author's followers (ianm/follow-users). Without it, the author alone. */
    case Followers = 'followers';
}
