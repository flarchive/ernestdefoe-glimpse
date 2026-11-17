<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Access;

use ErnestDefoe\Glimpse\Story;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * What a member may do to a story they can already see (seeing is ScopeStoryVisibility's job).
 */
class StoryPolicy extends AbstractPolicy
{
    public function delete(User $actor, Story $story): ?string
    {
        return $this->isAuthor($actor, $story) || $actor->hasPermission('glimpse.moderate') ? $this->allow() : $this->deny();
    }

    /** Approve, hide and restore. */
    public function moderate(User $actor, Story $story): ?string
    {
        return $actor->hasPermission('glimpse.moderate') ? $this->allow() : $this->deny();
    }

    public function react(User $actor, Story $story): ?string
    {
        return ! $actor->isGuest() && ! $this->isAuthor($actor, $story) && $story->isLive() && $actor->hasPermission('glimpse.react')
            ? $this->allow()
            : $this->deny();
    }

    public function report(User $actor, Story $story): ?string
    {
        return ! $actor->isGuest() && ! $this->isAuthor($actor, $story) ? $this->allow() : $this->deny();
    }

    /** The seen-by list and the counts behind it. */
    public function seeViewers(User $actor, Story $story): ?string
    {
        return $this->isAuthor($actor, $story) || $actor->hasPermission('glimpse.moderate') ? $this->allow() : $this->deny();
    }

    private function isAuthor(User $actor, Story $story): bool
    {
        return ! $actor->isGuest() && $story->user_id === $actor->id;
    }
}
