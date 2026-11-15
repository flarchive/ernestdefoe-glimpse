<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Follows;

use Closure;

/**
 * Who follows whom. Bound to FollowUsersGraph when ianm/follow-users is enabled (see extend.php's
 * Conditional), and to NoFollowGraph otherwise, so nothing else asks which extensions are on.
 */
interface FollowGraph
{
    /** Whether following exists on this forum at all (the Followers audience, the Following filter). */
    public function available(): bool;

    /** A subquery selecting the ids of the people $userId follows, for whereIn. */
    public function followedBy(int $userId): Closure;

    /** A subquery selecting the ids of $userId's followers, for whereIn. */
    public function followersOf(int $userId): Closure;
}
