<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Search;

use ErnestDefoe\Glimpse\Flags;
use ErnestDefoe\Glimpse\Story;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\Search\Filter\FilterManager;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * `GET /api/glimpse-stories`: the stories the actor may see, with what they mean to the actor (Flags), and the
 * author and media eager-loaded, so a page costs the same few queries whatever its length.
 * Filters: bar, live, author, status.
 */
class StorySearcher extends AbstractSearcher
{
    /** @param array<callable> $mutators */
    public function __construct(FilterManager $filters, array $mutators, private readonly Flags $flags)
    {
        parent::__construct($filters, $mutators);
    }

    /** @return Builder<Story> */
    public function getQuery(User $actor): Builder
    {
        return $this->flags->apply(
            Story::whereVisibleTo($actor)->select('glimpse_stories.*')->with(['user.groups', 'media']),
            $actor
        );
    }
}
