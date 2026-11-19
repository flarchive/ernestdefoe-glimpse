<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Job;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Config;
use ErnestDefoe\Glimpse\Enum\Status;
use ErnestDefoe\Glimpse\Follows\FollowGraph;
use ErnestDefoe\Glimpse\Ignores\IgnoreGraph;
use ErnestDefoe\Glimpse\Ids;
use ErnestDefoe\Glimpse\Notification\NewStoryBlueprint;
use ErnestDefoe\Glimpse\Notification\StoryMentionedBlueprint;
use ErnestDefoe\Glimpse\Story;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Notification\NotificationSyncer;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Tells people about a story that just went live, off the request:
 *
 * - everyone its caption mentions (alert + email), if they can see it;
 * - with the admin's switch on, the author's followers (alert), at most the admin's cap, and only for
 *   the author's first story in a day, so a burst of stories is one notification, not twenty.
 *   Followers are found set-wise with the view permission, never one at a time.
 */
class SendStoryNotifications extends AbstractJob
{
    public function __construct(private readonly int $storyId)
    {
        parent::__construct();
    }

    public function handle(NotificationSyncer $notifications, Config $config, FollowGraph $follows, IgnoreGraph $ignores): void
    {
        // The mentioned members' groups too: the visibility rule reads their permissions.
        $story = Story::query()->with(['user', 'mentionedUsers.groups'])->find($this->storyId);
        if ($story === null || $story->status !== Status::Live || $story->user === null) {
            return;
        }

        $author = $story->user;

        $mentioned = $this->whoCanSee($story, $story->mentionedUsers);
        if ($mentioned !== []) {
            $notifications->sync(new StoryMentionedBlueprint($story, $author), $mentioned);
        }

        if (! $config->notifyFollowers() || ! $follows->available() || $this->postedToday($story)) {
            return;
        }

        // One sync for every recipient: core's syncer deletes this story's alerts for anyone not in the
        // list it is given, so syncing batch by batch would take back each batch's alerts with the next.
        $recipients = $this->followers($author, $mentioned, $config, $follows, $ignores)
            ->orderBy('users.id')
            ->limit($config->notifyCap())
            ->get()
            ->all();
        if ($recipients !== []) {
            $notifications->sync(new NewStoryBlueprint($story, $author), array_values($recipients));
        }
    }

    /**
     * The author's followers who may view stories at all and weren't already told (mentioned), found
     * set-wise: no permission lookup per person.
     *
     * @param list<User> $mentioned
     * @return Builder<User>
     */
    private function followers(User $author, array $mentioned, Config $config, FollowGraph $follows, IgnoreGraph $ignores): Builder
    {
        $skip = array_map(fn (User $user): int => $user->id, $mentioned);
        $viewerGroups = Ids::of(Permission::query()->where('permission', 'glimpse.view')->pluck('group_id'));

        $followers = User::query()
            ->whereIn('users.id', $follows->followersOf($author->id))
            ->whereNotIn('users.id', $skip === [] ? [0] : $skip)
            ->where('users.is_email_confirmed', true);

        // Followers the author ignores aren't told (fof/ignore-users, with the admin's switch on).
        if ($ignores->available() && $config->ignoreHidesMine()) {
            $followers->whereNotIn('users.id', $ignores->ignoredBy($author->id));
        }

        // Unless guests or all members may view stories, only members of a group that may.
        if (array_intersect($viewerGroups, [Group::GUEST_ID, Group::MEMBER_ID]) === []) {
            $followers->whereIn('users.id', fn (QueryBuilder $q) => $q->select('user_id')->from('group_user')
                ->whereIn('group_id', array_merge([Group::ADMINISTRATOR_ID], $viewerGroups)));
        }

        return $followers;
    }

    /**
     * The mentioned members who can see the story. A caption names at most ten people, so this asks
     * the visibility rule about each of them, the one place it is decided.
     *
     * @param iterable<User> $users
     * @return list<User>
     */
    private function whoCanSee(Story $story, iterable $users): array
    {
        $recipients = [];
        foreach ($users as $user) {
            if (Story::whereVisibleTo($user)->whereKey($story->id)->exists()) {
                $recipients[] = $user;
            }
        }

        return $recipients;
    }

    private function postedToday(Story $story): bool
    {
        return Story::query()
            ->where('user_id', $story->user_id)
            ->where('id', '!=', $story->id)
            ->where('status', Status::Live->value)
            ->where('approved_at', '>', Carbon::now()->subDay())
            ->exists();
    }
}
