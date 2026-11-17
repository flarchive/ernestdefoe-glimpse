<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use PHPUnit\Framework\Attributes\Test;

/** The ring bar: one row per person (their newest story), and whether they have anything unseen. */
class BarTest extends GlimpseTestCase
{
    /** @return array<int, array<string, mixed>> the bar's rows keyed by author id */
    private function bar(?int $as): array
    {
        $rows = [];
        foreach ($this->json($this->get('/api/glimpse-stories', $as, ['filter' => ['bar' => '1']]))['data'] as $row) {
            $rows[(int) $row['relationships']['user']['data']['id']] = $row['attributes'] + ['id' => (int) $row['id']];
        }

        return $rows;
    }

    #[Test]
    public function one_row_per_author_their_newest_story(): void
    {
        $this->insert(2, ['created_at' => Carbon::now()->subMinutes(10)]);
        $newest = $this->insert(2, ['created_at' => Carbon::now()->subMinute()]);
        $other = $this->insert(4);

        $bar = $this->bar(3);
        $this->assertCount(2, $bar);
        $this->assertSame($newest, $bar[2]['id']);
        $this->assertSame($other, $bar[4]['id']);
    }

    #[Test]
    public function two_stories_in_the_same_second_are_still_one_ring(): void
    {
        $now = Carbon::now();
        $this->insert(2, ['created_at' => $now]);
        $second = $this->insert(2, ['created_at' => $now]);

        $bar = $this->bar(3);
        $this->assertCount(1, $bar);
        $this->assertSame($second, $bar[2]['id']);
    }

    #[Test]
    public function an_author_is_unseen_until_every_story_they_have_is_seen(): void
    {
        $first = $this->insert(2, ['created_at' => Carbon::now()->subMinutes(5)]);
        $second = $this->insert(2);
        $this->insert(4);

        $this->assertTrue($this->bar(3)[2]['authorHasUnseen']);
        $this->postJson('/api/glimpse-stories/seen', 3, ['ids' => [$second]]);
        $this->assertTrue($this->bar(3)[2]['authorHasUnseen'], 'The older one is still new.');
        $this->postJson('/api/glimpse-stories/seen', 3, ['ids' => [$first]]);
        $this->assertFalse($this->bar(3)[2]['authorHasUnseen']);
        $this->assertTrue($this->bar(3)[4]['authorHasUnseen']);

        // Guests see nothing as seen; the flag is only on the bar.
        $this->assertTrue($this->bar(null)[2]['authorHasUnseen']);
        $this->assertArrayNotHasKey('authorHasUnseen', $this->json($this->get("/api/glimpse-stories/$first", 3))['data']['attributes']);
    }

    #[Test]
    public function a_followers_only_story_never_counts_for_someone_who_cannot_see_it(): void
    {
        $this->insert(2);
        $hidden = $this->insert(2, ['audience' => 'followers']);
        $this->postJson('/api/glimpse-stories/seen', 4, ['ids' => $this->ids(4)]);

        // The stranger has seen all they can see: the followers-only story mustn't keep the ring lit.
        $this->assertFalse($this->bar(4)[2]['authorHasUnseen']);
        $this->assertNotContains($hidden, $this->ids(4));
    }

    #[Test]
    public function a_highlights_stories_carry_the_viewers_seen_marks(): void
    {
        $id = $this->insert(2);
        $this->database()->table('glimpse_highlights')->insert(['id' => 1, 'user_id' => 2, 'title' => 'Keep', 'position' => 0, 'created_at' => Carbon::now()]);
        $this->database()->table('glimpse_highlight_stories')->insert(['highlight_id' => 1, 'story_id' => $id, 'position' => 0]);
        $this->postJson('/api/glimpse-stories/seen', 3, ['ids' => [$id]]);

        $included = $this->json($this->get('/api/glimpse-highlights', 3, ['filter' => ['user' => '2']]))['included'];
        $story = array_values(array_filter($included, fn (array $row): bool => $row['type'] === 'glimpse-stories'))[0];
        $this->assertTrue($story['attributes']['isSeen']);
    }
}
