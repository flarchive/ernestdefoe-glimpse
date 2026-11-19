<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Search\Filter;

use ErnestDefoe\Glimpse\Follows\FollowGraph;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * `filter[following]`: stories by people the actor follows. For a guest, or on a forum without a follow
 * extension, nothing matches.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class FollowingFilter implements FilterInterface
{
    public function __construct(private readonly FollowGraph $follows)
    {
    }

    public function getFilterKey(): string
    {
        return 'following';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $actor = $state->getActor();

        if ($actor->isGuest() || ! $this->follows->available()) {
            $state->getQuery()->whereKey([]);

            return;
        }

        $state->getQuery()->whereIn('glimpse_stories.user_id', $this->follows->followedBy($actor->id), 'and', $negate);
    }
}
