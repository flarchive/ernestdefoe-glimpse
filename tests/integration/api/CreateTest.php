<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Story;
use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Posting a story: the schema's field rules, the writer's rules, approval, and moderation. */
class CreateTest extends GlimpseTestCase
{
    #[Test]
    public function a_photo_story_goes_live_for_a_day(): void
    {
        $response = $this->post(2, ['kind' => 'photo', 'caption' => '  Golden hour  ', 'linkUrl' => 'https://example.com/a'], $this->uploadId(2));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $attributes = $this->json($response)['data']['attributes'];
        $this->assertSame('live', $attributes['status']);
        $this->assertSame('everyone', $attributes['audience']);
        $this->assertSame('Golden hour', $attributes['caption']);
        $this->assertStringContainsString('/glimpse/media/', $attributes['mediaUrl']);

        $story = Story::query()->findOrFail((int) $this->json($response)['data']['id']);
        $this->assertNotNull($story->expires_at);
        $this->assertEqualsWithDelta(24 * 3600, $story->expires_at->getTimestamp() - Carbon::now()->getTimestamp(), 10);
    }

    #[Test]
    public function field_rules_answer_with_json_api_validation_errors(): void
    {
        foreach ([
            ['kind' => 'poll'],
            ['kind' => 'text', 'text' => str_repeat('a', 201)],
            ['kind' => 'text', 'text' => 'Hi', 'background' => 9],
            ['kind' => 'text', 'text' => 'Hi', 'caption' => str_repeat('b', 201)],
            ['kind' => 'text', 'text' => 'Hi', 'linkUrl' => 'javascript:alert(1)'],
            ['kind' => 'text', 'text' => 'Hi', 'linkUrl' => 'ftp://example.com'],
            ['kind' => 'text', 'text' => 'Hi', 'linkUrl' => 'not a link'],
            ['kind' => 'text', 'text' => 'Hi', 'linkUrl' => 'https://example.com/'.str_repeat('a', 2000)],
            ['kind' => 'text', 'text' => 'Hi', 'audience' => 'friends'],
            ['kind' => 'text', 'text' => '   '],
            ['kind' => 'photo'],
        ] as $attributes) {
            $response = $this->post(2, $attributes);
            $this->assertSame(422, $response->getStatusCode(), json_encode($attributes).' '.$response->getBody());
            $this->assertArrayHasKey('source', $this->json($response)['errors'][0]);
        }
        $this->assertSame(0, Story::query()->count());
    }

    #[Test]
    public function the_link_is_optional_whether_left_out_null_or_empty(): void
    {
        foreach ([
            'left out' => [[], null],
            'null' => [['linkUrl' => null], null],
            'empty' => [['linkUrl' => ''], null],
            'blank' => [['linkUrl' => '   '], null],
            'a link' => [['linkUrl' => 'https://example.com/trail'], 'https://example.com/trail'],
        ] as $case => [$link, $stored]) {
            foreach (['text', 'photo'] as $kind) {
                $attributes = ['kind' => $kind, 'background' => null, ...$link];
                if ($kind === 'text') {
                    $attributes['text'] = 'Hi';
                }
                $response = $this->post(2, $attributes, $kind === 'photo' ? $this->uploadId(2) : null);
                $this->assertSame(201, $response->getStatusCode(), "$kind, link $case: ".$response->getBody());

                $id = (int) $this->json($response)['data']['id'];
                $this->assertSame($stored, $this->json($response)['data']['attributes']['linkUrl'], "$kind, link $case");
                $this->assertSame($stored, Story::query()->findOrFail($id)->link_url, "$kind, link $case");
            }
        }
    }

    #[Test]
    public function media_must_be_your_own_unused_upload_of_the_right_kind(): void
    {
        // Someone else's upload doesn't exist as far as you are concerned.
        $theirs = $this->uploadId(4);
        $this->assertSame(404, $this->post(2, ['kind' => 'photo'], $theirs)->getStatusCode());

        $mine = $this->uploadId(2);
        $this->assertSame(422, $this->post(2, ['kind' => 'video'], $mine)->getStatusCode());
        $this->assertSame(201, $this->post(2, ['kind' => 'photo'], $mine)->getStatusCode());
        // Already used by that story.
        $this->assertSame(422, $this->post(2, ['kind' => 'photo'], $mine)->getStatusCode());
    }

    #[Test]
    public function a_kind_the_admin_turned_off_is_refused(): void
    {
        $this->setting('ernestdefoe-glimpse.kind_text', false);
        $this->assertSame(422, $this->post(2, ['kind' => 'text', 'text' => 'Hi'])->getStatusCode());
    }

    #[Test]
    public function groups_without_the_permission_cannot_post(): void
    {
        $this->database()->table('group_permission')->where('permission', 'glimpse.create')->delete();
        $this->assertSame(403, $this->post(2, ['kind' => 'text', 'text' => 'Hi'])->getStatusCode());
        $this->assertSame(403, $this->upload(2, $this->jpeg())->getStatusCode());
    }

    #[Test]
    public function the_daily_limit_holds(): void
    {
        $this->setting('ernestdefoe-glimpse.daily_limit', 2);
        $this->text(2);
        $this->text(2);
        $response = $this->post(2, ['kind' => 'text', 'text' => 'Third']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('/data/attributes/kind', $this->json($response)['errors'][0]['source']['pointer']);
    }

    #[Test]
    public function new_members_wait_for_approval_and_their_time_starts_when_approved(): void
    {
        $this->setting('ernestdefoe-glimpse.approval_posts', 5);
        $id = $this->text(2);

        $story = Story::query()->findOrFail($id);
        $this->assertSame('pending', $story->status->value);
        $this->assertNull($story->expires_at);

        // Invisible to everyone but its author and moderators.
        $this->assertSame(404, $this->get("/api/glimpse-stories/$id", 4)->getStatusCode());
        $this->assertSame(200, $this->get("/api/glimpse-stories/$id", 2)->getStatusCode());
        $this->assertSame([$id], $this->ids(5, ['status' => 'pending']));

        // The author can't approve their own; a moderator can.
        $approve = fn (int $as) => $this->send($this->request('PATCH', "/api/glimpse-stories/$id", ['authenticatedAs' => $as, 'json' => ['data' => ['type' => 'glimpse-stories', 'id' => (string) $id, 'attributes' => ['status' => 'live']]]]));
        $this->assertSame(403, $approve(2)->getStatusCode());
        $this->assertSame(200, $approve(5)->getStatusCode());

        $story->refresh();
        $this->assertSame('live', $story->status->value);
        $this->assertNotNull($story->expires_at);
        $this->assertSame(200, $this->get("/api/glimpse-stories/$id", 4)->getStatusCode());
    }

    #[Test]
    public function moderators_skip_approval(): void
    {
        $this->setting('ernestdefoe-glimpse.approval_posts', 5);
        $id = $this->text(5);
        $this->assertSame('live', Story::query()->findOrFail($id)->status->value);
    }

    #[Test]
    public function a_moderator_can_hide_and_restore_and_the_author_or_a_moderator_can_delete(): void
    {
        $id = $this->text(2);
        $patch = fn (int $as, string $status) => $this->send($this->request('PATCH', "/api/glimpse-stories/$id", ['authenticatedAs' => $as, 'json' => ['data' => ['type' => 'glimpse-stories', 'id' => (string) $id, 'attributes' => ['status' => $status]]]]));

        $this->assertSame(200, $patch(5, 'hidden')->getStatusCode());
        $this->assertSame(404, $this->get("/api/glimpse-stories/$id", 4)->getStatusCode());
        $this->assertSame(200, $this->get("/api/glimpse-stories/$id", 2)->getStatusCode());
        $this->assertSame(200, $patch(5, 'live')->getStatusCode());
        $this->assertSame(200, $this->get("/api/glimpse-stories/$id", 4)->getStatusCode());

        $this->assertSame(403, $this->send($this->request('DELETE', "/api/glimpse-stories/$id", ['authenticatedAs' => 4]))->getStatusCode());
        $this->assertSame(204, $this->send($this->request('DELETE', "/api/glimpse-stories/$id", ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(0, Story::query()->count());
    }

    #[Test]
    public function mentions_link_only_real_people_who_are_not_the_author(): void
    {
        $id = $this->text(2, ['caption' => 'Thanks @follower and @stranger, @nobody and me @author']);
        $mentioned = $this->database()->table('glimpse_mentions')->where('story_id', $id)->orderBy('user_id')->pluck('user_id')->all();
        $this->assertSame([3, 4], array_map(intval(...), $mentioned));

        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 3)->where('type', 'glimpseMentioned')->count());
    }

    #[Test]
    public function the_flood_guard_spaces_out_uploads_and_stories(): void
    {
        $this->setting('ernestdefoe-glimpse.flood_seconds', 30);
        $this->uploadId(2);
        $this->assertSame(429, $this->upload(2, $this->jpeg())->getStatusCode());
        // Moderators aren't held.
        $this->uploadId(5);
        $this->uploadId(5);
    }
}
