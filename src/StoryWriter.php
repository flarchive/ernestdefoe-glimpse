<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Enum\Audience;
use ErnestDefoe\Glimpse\Enum\Status;
use ErnestDefoe\Glimpse\Event\Published;
use ErnestDefoe\Glimpse\Event\Removed;
use ErnestDefoe\Glimpse\Follows\FollowGraph;
use ErnestDefoe\Glimpse\Approval\Approval;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The rules for writing a story that the API schema can't express on one field: the kind must be
 * switched on and match its media, the media must be the actor's own unused upload, the daily limit,
 * whether it waits for approval, and what happens when a moderator approves, hides or deletes it.
 * Field-level rules (lengths, allowed values, URL shape) live in Api\StoryResource.
 */
class StoryWriter
{
    public function __construct(
        private readonly Config $config,
        private readonly FollowGraph $follows,
        private readonly Approval $approval,
        private readonly PendingCount $pending,
        private readonly TranslatorInterface $translator,
        private readonly Dispatcher $events,
        private readonly Cleanup $cleanup,
    ) {
    }

    /** Fills in what the server decides on a new story, after the schema set what the member sent. */
    public function prepare(Story $story, User $actor): void
    {
        $actor->assertCan('glimpse.create');

        $kind = $story->kind;
        if (! $this->config->allows($kind)) {
            $this->fail('kind', 'kind_off');
        }

        if ($kind->needsMedia()) {
            $media = $story->media_id === null ? null : StoryMedia::query()
                ->where('id', $story->media_id)
                ->where('user_id', $actor->id)
                ->where('kind', $kind->mediaKind())
                ->whereNotExists(fn (QueryBuilder $used) => $used->selectRaw('1')->from('glimpse_stories')->whereColumn('glimpse_stories.media_id', 'glimpse_media.id'))
                ->first();
            if ($media === null) {
                $this->fail('media', 'media_missing');
            }
            $story->text = null;
            $story->background = null;
        } else {
            if (trim((string) $story->text) === '') {
                $this->fail('text', 'text_missing');
            }
            $story->media_id = null;
            $story->background ??= 0;
        }

        if ($story->getAttribute('audience') === null) {
            $story->audience = $this->config->defaultAudience();
        }
        if ($story->audience === Audience::Followers && ! $this->follows->available()) {
            $this->fail('audience', 'followers_off');
        }

        $story->user_id = $actor->id;
        $story->created_at = Carbon::now();

        if ($this->approval->holds($actor)) {
            $story->status = Status::Pending;
        } else {
            $this->goLive($story);
        }
    }

    /** After a new story is saved: its mentions, and the news if it went straight up. */
    public function created(Story $story, User $actor): void
    {
        $this->syncMentions($story, $actor);

        if ($story->status === Status::Live) {
            $this->events->dispatch(new Published($story, $actor));
        } else {
            $this->pending->forget();
        }
    }

    /** A moderator's decision: approve a waiting story, hide one, or restore a hidden one. */
    public function moderate(Story $story, Status $to, User $actor): void
    {
        $actor->assertCan('moderate', $story);
        $from = $story->status;

        if ($to === Status::Pending || $to === $from) {
            return;
        }
        $story->afterSave(fn () => $this->pending->forget());

        if ($to === Status::Hidden) {
            $story->status = Status::Hidden;
            $story->hidden_at = Carbon::now();
            $story->hidden_user_id = $actor->id;

            return;
        }

        $story->hidden_at = null;
        $story->hidden_user_id = null;
        if ($from === Status::Pending) {
            $this->goLive($story);
            $story->afterSave(fn () => $this->events->dispatch(new Published($story, $actor)));
        } else {
            $story->status = Status::Live;
        }
    }

    /** Deletes a story with its media, the file going only once the rows are gone. */
    public function delete(Story $story, User $actor): void
    {
        $actor->assertCan('delete', $story);

        $this->cleanup->stories([$story->id]);
        if ($story->status === Status::Pending) {
            $this->pending->forget();
        }

        $this->events->dispatch(new Removed($story, $actor));
    }

    private function goLive(Story $story): void
    {
        $now = Carbon::now();
        $story->status = Status::Live;
        $story->approved_at = $now;
        $story->expires_at = $now->copy()->addHours($this->config->lifetimeHours());
    }

    /** The members a caption names with @username, if they can see the story. */
    private function syncMentions(Story $story, User $actor): void
    {
        if (preg_match_all('/(?<![\w@])@([A-Za-z0-9_-]{1,30})/u', (string) $story->caption, $matches) < 1) {
            return;
        }

        $ids = User::whereVisibleTo($actor)
            ->whereIn('username', array_slice(array_unique($matches[1]), 0, 10))
            ->where('id', '!=', $actor->id)
            ->pluck('id')
            ->all();

        $story->mentionedUsers()->sync($ids);
    }

    /** The daily limit, counted under the member's lock right before the save (StoryResource::saveModel). */
    public function assertUnderDailyLimit(int $userId): void
    {
        $today = Story::query()->where('user_id', $userId)->where('created_at', '>', Carbon::now()->subDay())->count();
        if ($today >= $this->config->dailyLimit()) {
            $this->fail('kind', 'daily_limit', ['{count}' => $this->config->dailyLimit()]);
        }
    }

    /** A second story claimed the same upload at the same moment (the unique key caught it): the same answer as the check. */
    public function mediaTaken(): never
    {
        $this->fail('media', 'media_missing');
    }

    /** @param array<string, int|string> $params */
    private function fail(string $field, string $key, array $params = []): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-glimpse.api.'.$key, $params)]);
    }
}
