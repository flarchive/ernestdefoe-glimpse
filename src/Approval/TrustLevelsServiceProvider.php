<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Approval;

use Flarum\Foundation\AbstractServiceProvider;

/** Registered only while ernestdefoe/trust-levels is enabled. */
class TrustLevelsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Approval::class, TrustLevelApproval::class);
    }
}
