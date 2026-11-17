<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use PHPUnit\Framework\Attributes\Test;

/** "New story" notifications to followers: off by default, once a day, never to people the author ignores. */
class NotifyTest extends GlimpseTestCase
{
    protected array $alsoEnable = ['fof-ignore-users'];

    private function told(int $userId): int
    {
        return $this->database()->table('notifications')->where('user_id', $userId)->where('type', 'glimpseNewStory')->count();
    }

    #[Test]
    public function followers_are_not_told_unless_the_admin_turns_it_on(): void
    {
        $this->text(2);
        $this->assertSame(0, $this->told(3));
    }

    #[Test]
    public function followers_are_told_once_a_day_and_never_when_the_author_ignores_them(): void
    {
        $this->setting('ernestdefoe-glimpse.notify_followers', true);
        $this->database()->table('user_followers')->insert(['user_id' => 4, 'followed_user_id' => 2, 'subscription' => 'follow']);
        $this->database()->table('ignored_user')->insert(['user_id' => 2, 'ignored_user_id' => 4, 'ignored_at' => Carbon::now()]);

        $this->text(2);
        $this->text(2);

        $this->assertSame(1, $this->told(3), 'One alert for a burst of stories.');
        $this->assertSame(0, $this->told(4), 'The author ignores them.');
    }

    #[Test]
    public function every_follower_up_to_the_cap_keeps_their_alert_past_one_batch(): void
    {
        // More followers than one database batch (200): each alert must survive the next batch.
        $this->setting('ernestdefoe-glimpse.notify_followers', true);
        $this->setting('ernestdefoe-glimpse.notify_cap', 260);
        $users = [];
        $follows = [];
        for ($id = 100; $id < 400; $id++) {
            $users[] = ['id' => $id, 'username' => "fan$id", 'email' => "fan$id@machine.local", 'is_email_confirmed' => 1, 'password' => 'x'];
            $follows[] = ['user_id' => $id, 'followed_user_id' => 2, 'subscription' => 'follow'];
        }
        $this->database()->table('users')->insert($users);
        $this->database()->table('user_followers')->insert($follows);

        $this->text(2);

        $live = $this->database()->table('notifications')->where('type', 'glimpseNewStory')->where('is_deleted', false)->count();
        $this->assertSame(260, $live, 'The cap, all still standing (301 followers in all).');
        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'glimpseNewStory')->where('is_deleted', true)->count());
    }
}
