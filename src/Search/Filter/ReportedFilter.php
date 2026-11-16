<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * `filter[reported]`: stories with at least one open report, for the moderators' queue.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class ReportedFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'reported';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $state->getQuery()->whereExists(fn (QueryBuilder $report) => $report->selectRaw('1')
            ->from('glimpse_reports')
            ->whereColumn('glimpse_reports.story_id', 'glimpse_stories.id'), 'and', $negate);
    }
}
