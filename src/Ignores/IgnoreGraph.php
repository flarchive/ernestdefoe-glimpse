<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Ignores;

use Closure;

/**
 * Who ignores whom. Bound to IgnoreUsersGraph while fof/ignore-users is enabled (extend.php's
 * Conditional), and to NoIgnoreGraph otherwise.
 */
interface IgnoreGraph
{
    public function available(): bool;

    /** A subquery selecting the ids of the people $userId ignores, for whereIn. */
    public function ignoredBy(int $userId): Closure;

    /** A subquery selecting the ids of the people who ignore $userId, for whereIn. */
    public function ignoring(int $userId): Closure;
}
