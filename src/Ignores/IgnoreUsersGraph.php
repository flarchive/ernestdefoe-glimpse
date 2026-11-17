<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Ignores;

use Closure;
use Illuminate\Database\Query\Builder;

/** fof/ignore-users: its `ignored_user` table (user_id ignores ignored_user_id), through the query builder. */
class IgnoreUsersGraph implements IgnoreGraph
{
    public function available(): bool
    {
        return true;
    }

    public function ignoredBy(int $userId): Closure
    {
        return fn (Builder $query) => $query->select('ignored_user_id')->from('ignored_user')->where('user_id', $userId);
    }

    public function ignoring(int $userId): Closure
    {
        return fn (Builder $query) => $query->select('user_id')->from('ignored_user')->where('ignored_user_id', $userId);
    }
}
