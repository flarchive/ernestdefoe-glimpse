<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Follows;

use Closure;
use Illuminate\Database\Query\Builder;

/** Without a follow extension: nobody follows anybody (an empty id list, should anything ask). */
class NoFollowGraph implements FollowGraph
{
    public function available(): bool
    {
        return false;
    }

    public function followedBy(int $userId): Closure
    {
        return fn (Builder $query) => $query->select('id')->from('users')->whereIn('id', []);
    }

    public function followersOf(int $userId): Closure
    {
        return $this->followedBy($userId);
    }
}
