<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Seen marks and reactions. Both write in bulk, idempotently, inside a transaction, and recount the
 * story's totals with one aggregate UPDATE, so two people reacting at once can never miscount.
 */
class Engagement
{
    public const SEEN_BATCH = 50;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Config $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Marks stories seen by the actor. Ids they can't see, and their own stories, are ignored.
     *
     * @param list<int> $ids
     */
    public function markSeen(User $actor, array $ids): void
    {
        if ($actor->isGuest() || $ids === []) {
            return;
        }

        $visible = Ids::of(Story::whereVisibleTo($actor)
            ->whereIn('glimpse_stories.id', array_slice(Ids::of($ids), 0, self::SEEN_BATCH))
            ->where('glimpse_stories.user_id', '!=', $actor->id)
            ->pluck('glimpse_stories.id'));

        if ($visible === []) {
            return;
        }

        $now = Carbon::now();
        $this->db->transaction(function () use ($visible, $actor, $now): void {
            StoryView::query()->insertOrIgnore(array_map(
                fn (int $id): array => ['story_id' => $id, 'user_id' => $actor->id, 'viewed_at' => $now],
                $visible
            ));
            $this->recount($visible);
        });
    }

    /** Sets or clears (null) the actor's reaction to a story they may react to. */
    public function react(User $actor, Story $story, ?string $reaction): void
    {
        $actor->assertCan('react', $story);

        if ($reaction !== null && ! in_array($reaction, $this->config->reactions(), true)) {
            throw new ValidationException(['reaction' => $this->translator->trans('ernestdefoe-glimpse.api.bad_reaction')]);
        }

        $now = Carbon::now();
        $this->db->transaction(function () use ($actor, $story, $reaction, $now): void {
            StoryView::query()->insertOrIgnore(['story_id' => $story->id, 'user_id' => $actor->id, 'viewed_at' => $now]);
            StoryView::query()
                ->where('story_id', $story->id)
                ->where('user_id', $actor->id)
                ->update(['reaction' => $reaction, 'reacted_at' => $reaction === null ? null : $now]);
            $this->recount([$story->id]);
        });
    }

    /** @param list<int> $ids */
    private function recount(array $ids): void
    {
        Story::query()->whereIn('id', $ids)->update([
            'view_count' => $this->count(false),
            'reaction_count' => $this->count(true),
        ]);
    }

    /**
     * A correlated count of a story's views, as a subquery the UPDATE above compiles (with the table
     * prefix): Eloquent's own relation count, the query withCount() uses.
     */
    private function count(bool $reactions): Builder
    {
        $query = (new Story())->views()->getRelationExistenceCountQuery(StoryView::query(), Story::query())->toBase();
        if ($reactions) {
            $query->whereNotNull('glimpse_views.reaction');
        }

        return $query;
    }
}
