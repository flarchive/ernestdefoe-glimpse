<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Search\Filter;

use ErnestDefoe\Glimpse\Enum\Status;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * `filter[status]=pending|hidden|live`: the moderators' queue (others only ever match their own).
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class StatusFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'status';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $status = Status::tryFrom($this->asString($value)) ?? Status::Pending;

        $state->getQuery()->where('glimpse_stories.status', $negate ? '!=' : '=', $status->value);
    }
}
