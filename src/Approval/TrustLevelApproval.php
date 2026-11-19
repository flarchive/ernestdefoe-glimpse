<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Approval;

use ErnestDefoe\Glimpse\Ids;
use Flarum\User\User;

/** With ernestdefoe/trust-levels: a member below the admin's trust level is also still new. */
class TrustLevelApproval extends Approval
{
    /** Trust Levels' own state model, from the release that moved the level off the users table. */
    private const STATE = 'ErnestDefoe\\TrustLevels\\TrustState';

    protected function isNew(User $actor): bool
    {
        $level = $this->config->approvalTrustLevel();

        return parent::isNew($actor) || ($level > 0 && $this->level($actor) < $level);
    }

    /**
     * The member's trust level, read the way the installed Trust Levels stores it. This class is only
     * registered while Trust Levels is enabled (Extend\Conditional in extend.php); class_exists tells
     * its versions apart: newer ones keep the level in their own table (no row = level 0), older ones
     * in users.trust_level.
     */
    private function level(User $actor): int
    {
        $state = self::STATE;
        if (class_exists($state)) {
            $row = $state::of($actor);

            return is_object($row) && isset($row->level) && is_numeric($row->level) ? (int) $row->level : 0;
        }

        return Ids::one($actor->getAttribute('trust_level'));
    }
}
