<?php

declare(strict_types=1);

namespace ErnestDefoe\TrustLevels;

use Flarum\User\User;

/** Trust Levels' TrustState, as far as Glimpse uses it: of($user)->level (no row = level 0). Loaded only by TrustLevelApprovalTest, in its own process. */
class TrustState
{
    public int $level = 0;

    public static function of(User $user): self
    {
        $state = new self();
        $state->level = (int) $user->getAttribute('state_level');

        return $state;
    }
}
