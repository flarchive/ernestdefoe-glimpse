<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use PHPUnit\Framework\Attributes\Test;

/** With fof/ignore-users and the admin's switch: an author who ignores you hides their stories from you. */
class IgnoreTest extends GlimpseTestCase
{
    protected bool $follows = false;

    protected array $alsoEnable = ['fof-ignore-users'];

    #[Test]
    public function an_author_who_ignores_you_hides_their_stories_from_you(): void
    {
        $id = $this->insert(2);
        $this->database()->table('ignored_user')->insert(['user_id' => 2, 'ignored_user_id' => 4, 'ignored_at' => \Carbon\Carbon::now()]);

        $this->assertSame([], $this->ids(4));
        $this->assertSame(404, $this->get("/api/glimpse-stories/$id", 4)->getStatusCode());
        $this->assertSame([$id], $this->ids(3));
        $this->assertSame([$id], $this->ids(5), 'Moderators are never hidden from.');
    }

    #[Test]
    public function the_admin_can_turn_it_off(): void
    {
        $this->setting('ernestdefoe-glimpse.ignore_hides', false);
        $id = $this->insert(2);
        $this->database()->table('ignored_user')->insert(['user_id' => 2, 'ignored_user_id' => 4, 'ignored_at' => \Carbon\Carbon::now()]);

        $this->assertSame([$id], $this->ids(4));
    }

    #[Test]
    public function without_a_follow_extension_the_following_filter_matches_nothing(): void
    {
        $this->insert(2);
        $this->assertSame([], $this->ids(3, ['live' => '1', 'following' => '1']));
    }
}
