<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use ErnestDefoe\Glimpse\Approval\Approval;
use ErnestDefoe\Glimpse\Follows\FollowGraph;
use ErnestDefoe\Glimpse\Follows\NoFollowGraph;
use ErnestDefoe\Glimpse\Ignores\IgnoreGraph;
use ErnestDefoe\Glimpse\Ignores\NoIgnoreGraph;
use Flarum\Foundation\AbstractServiceProvider;

/**
 * The defaults for the pieces other extensions can change. The providers registered in extend.php's
 * Conditional blocks run after this one and replace them while their extension is enabled.
 */
class GlimpseServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(FollowGraph::class, NoFollowGraph::class);
        $this->container->singleton(IgnoreGraph::class, NoIgnoreGraph::class);
        $this->container->singleton(Approval::class, Approval::class);
    }
}
