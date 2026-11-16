<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Approval;

use ErnestDefoe\Glimpse\Ids;
use Flarum\User\User;

/** With ernestdefoe/trust-levels: a member below the admin's trust level is also still new. */
class TrustLevelApproval extends Approval
{
    protected function isNew(User $actor): bool
    {
        $level = $this->config->approvalTrustLevel();

        return parent::isNew($actor) || ($level > 0 && Ids::one($actor->getAttribute('trust_level')) < $level);
    }
}
