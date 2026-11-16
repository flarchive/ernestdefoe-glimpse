<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Follows;

use Flarum\Foundation\AbstractServiceProvider;

/** Registered only while ianm/follow-users is enabled: swaps in the real follow graph. */
class FollowsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(FollowGraph::class, FollowUsersGraph::class);
    }
}
