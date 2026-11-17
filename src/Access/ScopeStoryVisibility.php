<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Access;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Enum\Audience;
use ErnestDefoe\Glimpse\Enum\Status;
use ErnestDefoe\Glimpse\Follows\FollowGraph;
use ErnestDefoe\Glimpse\Story;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * 🚨 The one privacy rule, registered with Extend\ModelVisibility so every read goes through it: the
 * API (lists, a single story, highlights, the seen-by list), media files, and core's notification list.
 *
 * - Moderators see every story.
 * - An author sees all of their own.
 * - Everyone else needs `glimpse.view`, and sees a story when it is live (approved, not hidden) and
 *   either unexpired or kept in one of its author's highlights, and its audience lets them in:
 *   everyone; members (signed in); followers (the FollowGraph; without a follow extension, nobody else).
 *
 * flarum/suspend and fof/ignore-users add their own scopes while they're enabled (HideSuspendedAuthors,
 * HideFromIgnored), so this rule never asks which extensions are on.
 */
class ScopeStoryVisibility
{
    public function __construct(private readonly FollowGraph $follows)
    {
    }

    /** @param Builder<Story> $query */
    public function __invoke(User $actor, Builder $query): void
    {
        if ($actor->hasPermission('glimpse.moderate')) {
            return;
        }

        $query->where(function (Builder $q) use ($actor): void {
            if ($actor->hasPermission('glimpse.view')) {
                $q->where(fn (Builder $public) => $this->publicly($public, $actor));
            } else {
                $q->whereRaw('1 = 0');
            }

            if (! $actor->isGuest()) {
                $q->orWhere('glimpse_stories.user_id', $actor->id);
            }
        });
    }

    /** @param Builder<Story> $query */
    private function publicly(Builder $query, User $actor): void
    {
        $now = Carbon::now();

        $query->where('glimpse_stories.status', Status::Live->value)
            ->where(function (Builder $q) use ($now): void {
                $q->where('glimpse_stories.expires_at', '>', $now)
                    ->orWhereExists(fn (QueryBuilder $kept) => $kept->selectRaw('1')
                        ->from('glimpse_highlight_stories')
                        ->whereColumn('glimpse_highlight_stories.story_id', 'glimpse_stories.id'));
            })
            ->where(function (Builder $q) use ($actor): void {
                $q->where('glimpse_stories.audience', Audience::Everyone->value);

                if ($actor->isGuest()) {
                    return;
                }

                $q->orWhere('glimpse_stories.audience', Audience::Members->value);

                if ($this->follows->available()) {
                    $q->orWhere(fn (Builder $f) => $f->where('glimpse_stories.audience', Audience::Followers->value)
                        ->whereIn('glimpse_stories.user_id', $this->follows->followedBy($actor->id)));
                }
            });
    }
}
