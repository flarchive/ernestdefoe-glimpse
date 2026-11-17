<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Ignores;

use Flarum\Foundation\AbstractServiceProvider;

/** Registered only while fof/ignore-users is enabled: swaps in the real ignore graph. */
class IgnoresServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(IgnoreGraph::class, IgnoreUsersGraph::class);
    }
}
