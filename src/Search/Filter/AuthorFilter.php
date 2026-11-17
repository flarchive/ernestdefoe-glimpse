<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * `filter[author]=<user id>`: one person's stories (a ring, a profile).
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class AuthorFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'author';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $ids = array_map(intval(...), $this->asIntArray($value));

        $state->getQuery()->whereIn('glimpse_stories.user_id', $ids, 'and', $negate);
    }
}
