<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Access;

use ErnestDefoe\Glimpse\Highlight;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * A highlight is its owner's own collection: only they rename, reorder, refill or delete it.
 * Moderators act on the stories inside (StoryPolicy), never on someone's highlights.
 */
class HighlightPolicy extends AbstractPolicy
{
    public function update(User $actor, Highlight $highlight): ?string
    {
        return $this->owns($actor, $highlight) ? $this->allow() : $this->deny();
    }

    public function delete(User $actor, Highlight $highlight): ?string
    {
        return $this->owns($actor, $highlight) ? $this->allow() : $this->deny();
    }

    private function owns(User $actor, Highlight $highlight): bool
    {
        return ! $actor->isGuest() && $highlight->user_id === $actor->id;
    }
}
