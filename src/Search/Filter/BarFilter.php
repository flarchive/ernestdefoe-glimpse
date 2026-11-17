<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Search\Filter;

use ErnestDefoe\Glimpse\Story;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * `filter[bar]`: what the ring bar draws. One row per person, their newest live story, for the 30
 * people who posted most recently (minus the actor's mutes), found by joining a derived table of those
 * authors and their newest time. Whether each still has something unseen is the story's
 * `authorHasUnseen` field (UnseenAuthors, one query for the page); the viewer loads a person's stories
 * when their ring is opened.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class BarFilter implements FilterInterface
{
    public const AUTHORS = 30;

    public function getFilterKey(): string
    {
        return 'bar';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $actor = $state->getActor();

        /** @var Builder<Story> $query */
        $query = $state->getQuery();
        LiveFilter::constrain($query, $actor);

        $authors = Story::whereVisibleTo($actor)
            ->select('glimpse_stories.user_id')
            // Unqualified on purpose: this subquery reads one table, so no prefix is needed. The newest
            // story is the highest id (two stories in the same second still give one row).
            ->selectRaw('MAX(created_at) as glimpse_latest, MAX(id) as glimpse_newest')
            ->groupBy('glimpse_stories.user_id')
            // The limit keeps the 30 newest posters, so this order matters. It names the expression, not
            // its alias: ordering a grouped query by a select alias isn't standard SQL.
            ->orderByRaw('MAX(created_at) DESC')
            ->limit(self::AUTHORS);
        LiveFilter::constrain($authors, $actor);

        $query->joinSub($authors->toBase(), 'glimpse_bar', fn (JoinClause $join) => $join
            ->on('glimpse_bar.glimpse_newest', '=', 'glimpse_stories.id'));
    }
}
