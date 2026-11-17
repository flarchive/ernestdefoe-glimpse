<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Access;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Story;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * With flarum/suspend (registered only while it's enabled): a suspended author's stories are hidden
 * while the suspension lasts, from everyone but moderators and the author.
 */
class HideSuspendedAuthors
{
    /** @param Builder<Story> $query */
    public function __invoke(User $actor, Builder $query): void
    {
        if ($actor->hasPermission('glimpse.moderate')) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->whereNotIn('glimpse_stories.user_id', fn (QueryBuilder $suspended) => $suspended->select('id')
                ->from('users')
                ->where('suspended_until', '>', Carbon::now()))
            ->when(! $actor->isGuest(), fn (Builder $own) => $own->orWhere('glimpse_stories.user_id', $actor->id)));
    }
}
