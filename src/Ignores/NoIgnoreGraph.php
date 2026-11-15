<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Ignores;

use Closure;
use Illuminate\Database\Query\Builder;

/** Without an ignore extension: nobody ignores anybody (an empty id list, should anything ask). */
class NoIgnoreGraph implements IgnoreGraph
{
    public function available(): bool
    {
        return false;
    }

    public function ignoredBy(int $userId): Closure
    {
        return fn (Builder $query) => $query->select('id')->from('users')->whereRaw('1 = 0');
    }

    public function ignoring(int $userId): Closure
    {
        return $this->ignoredBy($userId);
    }
}
