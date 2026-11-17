<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Search\Filter;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Enum\Status;
use ErnestDefoe\Glimpse\Story;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * `filter[live]`: approved, unhidden, unexpired stories, without the people the actor muted.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class LiveFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'live';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        /** @var Builder<Story> $query */
        $query = $state->getQuery();
        self::constrain($query, $state->getActor());
    }

    /** @param Builder<Story> $query */
    public static function constrain(Builder $query, User $actor): void
    {
        $query->where('glimpse_stories.status', Status::Live->value)
            ->where('glimpse_stories.expires_at', '>', Carbon::now());

        if (! $actor->isGuest()) {
            $query->whereNotIn('glimpse_stories.user_id', fn (QueryBuilder $muted) => $muted->select('muted_user_id')
                ->from('glimpse_mutes')
                ->where('user_id', $actor->id));
        }
    }
}
