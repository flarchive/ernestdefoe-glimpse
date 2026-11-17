<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use Flarum\Api\Client;
use Flarum\User\Guest;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 Every write endpoint declares ->authenticated(): a guest is refused (401) and nothing is written. A Create
 * refuses before any lookup; an Update/Delete finds its record first, so one a guest can't see is a 404. Sent through Flarum's API client, so the endpoint's own check is what answers (over HTTP a
 * session-less write already stops at the CSRF check).
 */
class GuestWriteTest extends GlimpseTestCase
{
    protected bool $follows = false;

    /** @param array<string, mixed> $body */
    private function asGuest(string $method, string $path, array $body = []): int
    {
        $client = $this->app()->getContainer()->make(Client::class)->withActor(new Guest())->withErrorHandling()->withBody($body);

        return $client->send($method, $path)->getStatusCode();
    }

    #[Test]
    public function a_guest_report_is_refused_before_any_story_query(): void
    {
        $story = $this->text(2);
        $this->send($this->request('GET', '/api')); // warm settings and permissions

        $storyQueries = 0;
        $this->database()->listen(function ($query) use (&$storyQueries): void {
            $storyQueries += str_contains($query->sql, 'glimpse_stories') ? 1 : 0;
        });

        $status = $this->asGuest('POST', '/glimpse-reports', ['data' => [
            'type' => 'glimpse-reports',
            'attributes' => ['reason' => 'spam'],
            'relationships' => ['story' => ['data' => ['type' => 'glimpse-stories', 'id' => (string) $story]]],
        ]]);

        $this->assertSame(401, $status);
        $this->assertSame(0, $storyQueries, 'The story was never looked up.');
        $this->assertSame(0, $this->database()->table('glimpse_reports')->count());
    }

    #[Test]
    public function every_write_endpoint_refuses_a_guest(): void
    {
        $story = $this->text(2);
        $highlight = $this->json($this->send($this->request('POST', '/api/glimpse-highlights', ['authenticatedAs' => 2, 'json' => ['data' => ['type' => 'glimpse-highlights', 'attributes' => ['title' => 'Mine']]]])))['data']['id'];
        $this->database()->table('glimpse_mutes')->insert(['user_id' => 2, 'muted_user_id' => 4, 'created_at' => \Carbon\Carbon::now()]);
        $mute = $this->database()->table('glimpse_mutes')->value('id');
        $this->database()->table('glimpse_reports')->insert(['story_id' => $story, 'user_id' => 4, 'reason' => 'spam', 'created_at' => \Carbon\Carbon::now()]);
        $report = $this->database()->table('glimpse_reports')->value('id');

        $writes = [
            ['POST', '/glimpse-stories', ['data' => ['type' => 'glimpse-stories', 'attributes' => ['kind' => 'text', 'text' => 'Hi']]]],
            ['PATCH', "/glimpse-stories/$story", ['data' => ['type' => 'glimpse-stories', 'id' => (string) $story, 'attributes' => ['status' => 'hidden']]]],
            ['DELETE', "/glimpse-stories/$story", []],
            ['POST', '/glimpse-stories/seen', ['ids' => [$story]]],
            ['POST', "/glimpse-stories/$story/reaction", ['reaction' => '🔥']],
            ['POST', '/glimpse-media', []],
            ['POST', '/glimpse-highlights', ['data' => ['type' => 'glimpse-highlights', 'attributes' => ['title' => 'Theirs']]]],
            ['PATCH', "/glimpse-highlights/$highlight", ['data' => ['type' => 'glimpse-highlights', 'id' => (string) $highlight, 'attributes' => ['title' => 'Taken']]]],
            ['DELETE', "/glimpse-highlights/$highlight", []],
            ['POST', '/glimpse-mutes', ['data' => ['type' => 'glimpse-mutes', 'relationships' => ['mutedUser' => ['data' => ['type' => 'users', 'id' => '2']]]]]],
            // Mutes and reports are scoped to their own member, so a guest's lookup finds nothing: not found.
            ['DELETE', "/glimpse-mutes/$mute", [], 404],
            ['DELETE', "/glimpse-reports/$report", [], 404],
        ];
        foreach ($writes as $write) {
            [$method, $path, $body] = $write;
            $this->assertSame($write[3] ?? 401, $this->asGuest($method, $path, $body), "$method $path");
        }

        $this->assertSame(1, $this->database()->table('glimpse_stories')->count());
        $this->assertSame('live', $this->database()->table('glimpse_stories')->value('status'));
        $this->assertSame('Mine', $this->database()->table('glimpse_highlights')->value('title'));
        $this->assertSame(1, $this->database()->table('glimpse_mutes')->count());
        $this->assertSame(1, $this->database()->table('glimpse_reports')->count());
        $this->assertSame(0, $this->database()->table('glimpse_views')->count());
    }
}
