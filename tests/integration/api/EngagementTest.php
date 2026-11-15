<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use ErnestDefoe\Glimpse\Story;
use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Seen marks, reactions and the seen-by list. */
class EngagementTest extends GlimpseTestCase
{
    protected bool $follows = false;

    #[Test]
    public function seen_marks_are_batched_idempotent_and_never_for_what_you_cannot_see(): void
    {
        $theirs = $this->text(2);
        $members = $this->text(2, ['audience' => 'members']);
        $mine = $this->text(4);
        $pending = $this->insert(2, ['status' => 'pending', 'expires_at' => null]);

        $seen = fn (int $as, array $ids) => $this->postJson('/api/glimpse-stories/seen', $as, ['ids' => $ids]);
        $this->assertSame(204, $seen(4, [$theirs, $members, $mine, $pending, 999, 'x'])->getStatusCode());
        $this->assertSame(204, $seen(4, [$theirs])->getStatusCode());

        $rows = $this->database()->table('glimpse_views')->where('user_id', 4)->orderBy('story_id')->pluck('story_id')->all();
        $this->assertSame([$theirs, $members], array_map(intval(...), $rows));
        $this->assertSame(1, Story::query()->findOrFail($theirs)->view_count);
        $this->assertSame(0, Story::query()->findOrFail($mine)->view_count);

        $flags = $this->json($this->get("/api/glimpse-stories/$theirs", 4))['data']['attributes'];
        $this->assertTrue($flags['isSeen']);
        $this->assertFalse($this->json($this->get("/api/glimpse-stories/$theirs", 3))['data']['attributes']['isSeen']);
    }

    #[Test]
    public function reactions_are_set_changed_and_cleared_and_counted(): void
    {
        $id = $this->text(2);
        $react = fn (int $as, ?string $reaction) => $this->postJson("/api/glimpse-stories/$id/reaction", $as, ['reaction' => $reaction]);

        $response = $react(3, '🔥');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('🔥', $this->json($response)['data']['attributes']['myReaction']);
        $react(4, '❤️');
        $react(3, '😂');
        $this->assertSame(2, Story::query()->findOrFail($id)->reaction_count);
        // Reacting counts as seeing it.
        $this->assertSame(2, Story::query()->findOrFail($id)->view_count);

        $react(3, null);
        $this->assertSame(1, Story::query()->findOrFail($id)->reaction_count);

        $this->assertSame(422, $react(3, '<script>')->getStatusCode());
        $this->assertSame(403, $react(2, '❤️')->getStatusCode(), 'Not on your own story.');
    }

    #[Test]
    public function the_admins_reaction_list_is_the_only_one_accepted(): void
    {
        $this->setting('ernestdefoe-glimpse.reactions', '👍 <b> 👎');
        $id = $this->insert(2);

        $this->assertSame(['👍', '👎'], $this->json($this->get('/api', 3))['data']['attributes']['glimpseReactions']);
        $this->assertSame(422, $this->postJson("/api/glimpse-stories/$id/reaction", 3, ['reaction' => '❤️'])->getStatusCode());
        $this->assertSame(200, $this->postJson("/api/glimpse-stories/$id/reaction", 3, ['reaction' => '👎'])->getStatusCode());
    }

    #[Test]
    public function only_the_author_and_moderators_see_who_saw_it(): void
    {
        $id = $this->text(2);
        $this->postJson('/api/glimpse-stories/seen', 3, ['ids' => [$id]]);
        $this->postJson("/api/glimpse-stories/$id/reaction", 4, ['reaction' => '❤️']);

        $list = fn (?int $as) => $this->get('/api/glimpse-views', $as, ['filter' => ['story' => (string) $id], 'include' => 'user']);
        $this->assertSame(403, $list(3)->getStatusCode());
        $this->assertSame(401, $list(null)->getStatusCode());

        $rows = $this->json($list(2))['data'];
        $this->assertCount(2, $rows);
        $this->assertSame('❤️', $rows[0]['attributes']['reaction']);
        $this->assertSame(200, $list(5)->getStatusCode());

        // The counts ride on the story for the same people.
        $this->assertArrayHasKey('viewCount', $this->json($this->get("/api/glimpse-stories/$id", 2))['data']['attributes']);
        $this->assertArrayNotHasKey('viewCount', $this->json($this->get("/api/glimpse-stories/$id", 3))['data']['attributes']);
    }
}
