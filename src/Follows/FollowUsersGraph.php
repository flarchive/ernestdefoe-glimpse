<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Follows;

use Closure;
use Illuminate\Database\Query\Builder;

/** ianm/follow-users: its `user_followers` table (user_id follows followed_user_id), through the query builder. */
class FollowUsersGraph implements FollowGraph
{
    public function available(): bool
    {
        return true;
    }

    public function followedBy(int $userId): Closure
    {
        return fn (Builder $query) => $query->select('followed_user_id')->from('user_followers')->where('user_id', $userId);
    }

    public function followersOf(int $userId): Closure
    {
        return fn (Builder $query) => $query->select('user_id')->from('user_followers')->where('followed_user_id', $userId);
    }
}
