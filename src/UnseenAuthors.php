<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use Carbon\Carbon;
use Closure;
use ErnestDefoe\Glimpse\Enum\Status;
use Flarum\User\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * "Does this author have a live story the viewer hasn't seen?", for the ring bar, answered for every
 * author on the page in one query.
 *
 * The field getter queues the author and returns a closure; Flarum resolves deferred values after every
 * other getter, so the first closure loads all the queued authors at once. State is keyed by the
 * request (never by a model, and never shared between viewers).
 */
class UnseenAuthors
{
    /** @var WeakMap<ServerRequestInterface, array{queued: array<int, true>, unseen: array<int, true>|null}> */
    private WeakMap $requests;

    public function __construct()
    {
        $this->requests = new WeakMap();
    }

    /** @return Closure(): bool */
    public function defer(ServerRequestInterface $request, User $actor, int $authorId): Closure
    {
        $state = $this->requests[$request] ?? ['queued' => [], 'unseen' => null];
        $state['queued'][$authorId] = true;
        $this->requests[$request] = $state;

        return function () use ($request, $actor, $authorId): bool {
            $state = $this->requests[$request] ?? ['queued' => [$authorId => true], 'unseen' => null];
            if ($state['unseen'] === null) {
                $state['unseen'] = $this->load($actor, array_keys($state['queued']));
                $this->requests[$request] = $state;
            }

            return isset($state['unseen'][$authorId]);
        };
    }

    /**
     * @param list<int> $authorIds
     * @return array<int, true>
     */
    private function load(User $actor, array $authorIds): array
    {
        // A guest marks nothing seen: everything is new to them.
        if ($actor->isGuest()) {
            return array_fill_keys($authorIds, true);
        }

        $ids = Ids::of(Story::whereVisibleTo($actor)
            ->whereIn('glimpse_stories.user_id', $authorIds)
            ->where('glimpse_stories.status', Status::Live->value)
            ->where('glimpse_stories.expires_at', '>', Carbon::now())
            ->whereNotExists(fn (QueryBuilder $view) => $view->selectRaw('1')
                ->from('glimpse_views')
                ->whereColumn('glimpse_views.story_id', 'glimpse_stories.id')
                ->where('glimpse_views.user_id', $actor->id))
            ->distinct()
            ->pluck('glimpse_stories.user_id'));

        return array_fill_keys($ids, true);
    }
}
