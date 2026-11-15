<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 The bar and the Stories page cost the same number of queries for 1 author or 30, for a guest
 * and for a member, with seen marks, reactions, follows, media and mentions in play.
 */
class QueryCountTest extends GlimpseTestCase
{
    private function seed(int $authors, int $from = 100): void
    {
        $now = Carbon::now();
        $users = [];
        for ($id = $from; $id < $from + $authors; $id++) {
            $users[] = ['id' => $id, 'username' => "poster$id", 'email' => "poster$id@machine.local", 'is_email_confirmed' => 1, 'password' => 'x'];
        }
        $this->database()->table('users')->insert($users);

        for ($id = $from; $id < $from + $authors; $id++) {
            $mediaId = $this->database()->table('glimpse_media')->insertGetId(['user_id' => $id, 'kind' => 'image', 'mime' => 'image/webp', 'path' => '2026/11/'.str_pad(dechex($id), 32, '0', STR_PAD_LEFT).'.webp', 'width' => 10, 'height' => 10, 'size' => 10, 'created_at' => $now]);
            $photo = $this->insert($id, ['kind' => 'photo', 'text' => null, 'media_id' => $mediaId]);
            $text = $this->insert($id, ['caption' => 'hi @follower']);
            $this->database()->table('glimpse_mentions')->insert(['story_id' => $text, 'user_id' => 3]);
            $this->database()->table('glimpse_views')->insert(['story_id' => $photo, 'user_id' => 3, 'viewed_at' => $now, 'reaction' => '❤️']);
            $this->database()->table('user_followers')->insert(['user_id' => 3, 'followed_user_id' => $id, 'subscription' => 'follow']);
        }
    }

    private function queries(string $path, array $query, ?int $as): int
    {
        $request = $this->request('GET', $path, $as ? ['authenticatedAs' => $as] : [])->withQueryParams($query);
        $this->send($request); // warm: settings, permissions

        $count = 0;
        $this->database()->listen(function () use (&$count): void {
            $count++;
        });
        $response = $this->send($request);
        $this->assertSame(200, $response->getStatusCode(), substr((string) $response->getBody(), 0, 1000));

        return $count;
    }

    #[Test]
    public function the_bar_and_the_page_cost_the_same_for_one_author_or_thirty(): void
    {
        $bar = ['filter' => ['bar' => '1'], 'page' => ['limit' => 30, 'total' => 0]];
        $page = ['filter' => ['live' => '1'], 'page' => ['limit' => 24, 'total' => 0]];

        $this->seed(1);
        $before = [];
        foreach ([null, 3, 4] as $as) {
            $before[] = [$this->queries('/api/glimpse-stories', $bar, $as), $this->queries('/api/glimpse-stories', $page, $as)];
        }

        $this->seed(29, 101);
        foreach ([null, 3, 4] as $i => $as) {
            $this->assertSame($before[$i][0], $this->queries('/api/glimpse-stories', $bar, $as), 'The bar for 30 authors costs what one does, as '.($as ?? 'guest'));
            $this->assertSame($before[$i][1], $this->queries('/api/glimpse-stories', $page, $as), 'A page of 24 costs what a page of 2 does, as '.($as ?? 'guest'));
        }
    }

    #[Test]
    public function the_forum_payload_adds_no_query_and_the_waiting_count_is_cached(): void
    {
        // Warm, the moderators' waiting count comes from the cache: no query for anyone.
        $this->assertSame($this->queries('/api', [], 4), $this->queries('/api', [], 5));
    }

    #[Test]
    public function highlights_and_reports_cost_the_same_for_one_story_or_many(): void
    {
        $this->seed(1);
        $one = $this->database()->table('glimpse_stories')->pluck('id')->all();
        $highlight = fn (array $ids, int $highlightId) => [
            $this->database()->table('glimpse_highlights')->insert(['id' => $highlightId, 'user_id' => 100, 'title' => 'H', 'position' => 0, 'created_at' => Carbon::now()]),
            $this->database()->table('glimpse_highlight_stories')->insert(array_map(fn ($id) => ['highlight_id' => $highlightId, 'story_id' => $id, 'position' => 0], $ids)),
        ];
        $highlight($one, 1);
        $fewQueries = $this->queries('/api/glimpse-highlights', ['filter' => ['user' => '100']], 3);

        $now = Carbon::now();
        $media = [];
        for ($i = 0; $i < 12; $i++) {
            $mediaId = $this->database()->table('glimpse_media')->insertGetId(['user_id' => 100, 'kind' => 'image', 'mime' => 'image/webp', 'path' => '2026/11/'.str_pad(dechex(1000 + $i), 32, '0', STR_PAD_LEFT).'.webp', 'width' => 10, 'height' => 10, 'size' => 10, 'created_at' => $now]);
            $media[] = $this->insert(100, ['kind' => 'photo', 'text' => null, 'media_id' => $mediaId]);
        }
        $this->database()->table('glimpse_highlight_stories')->insert(array_map(fn ($id) => ['highlight_id' => 1, 'story_id' => $id, 'position' => 1], $media));
        $this->assertSame($fewQueries, $this->queries('/api/glimpse-highlights', ['filter' => ['user' => '100']], 3));

        $this->database()->table('glimpse_reports')->insert(['story_id' => $media[0], 'user_id' => 4, 'reason' => 'spam', 'created_at' => $now]);
        $oneReport = $this->queries('/api/glimpse-reports', [], 5);
        foreach (array_slice($media, 1) as $storyId) {
            $this->database()->table('glimpse_reports')->insert(['story_id' => $storyId, 'user_id' => 4, 'reason' => 'spam', 'created_at' => $now]);
        }
        $this->assertSame($oneReport, $this->queries('/api/glimpse-reports', [], 5));
    }
}
