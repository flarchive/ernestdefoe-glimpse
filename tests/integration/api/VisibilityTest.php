<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Story;
use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 The one privacy rule, asked on every path: lists, a single story, the bar, media files.
 * 2 author, 3 follower of 2, 4 stranger, 5 moderator.
 */
class VisibilityTest extends GlimpseTestCase
{
    #[Test]
    public function each_audience_reaches_exactly_its_people(): void
    {
        $everyone = $this->text(2, ['audience' => 'everyone']);
        $members = $this->text(2, ['audience' => 'members']);
        $followers = $this->text(2, ['audience' => 'followers']);

        $this->assertEqualsCanonicalizing([$everyone], $this->ids(null));
        $this->assertEqualsCanonicalizing([$everyone, $members], $this->ids(4));
        $this->assertEqualsCanonicalizing([$everyone, $members, $followers], $this->ids(3));
        $this->assertEqualsCanonicalizing([$everyone, $members, $followers], $this->ids(2));
        $this->assertEqualsCanonicalizing([$everyone, $members, $followers], $this->ids(5));

        // A single story asks the same question.
        $this->assertSame(404, $this->get("/api/glimpse-stories/$followers", 4)->getStatusCode());
        $this->assertSame(404, $this->get("/api/glimpse-stories/$members")->getStatusCode());
        $this->assertSame(200, $this->get("/api/glimpse-stories/$followers", 3)->getStatusCode());
    }

    #[Test]
    public function without_the_view_permission_nothing_shows_but_your_own(): void
    {
        $theirs = $this->insert(4);
        $mine = $this->insert(2);
        $this->database()->table('group_permission')->where('permission', 'glimpse.view')->delete();

        $this->assertSame([], $this->ids(null));
        $this->assertSame([$mine], $this->ids(2));
        $this->assertSame(404, $this->get("/api/glimpse-stories/$theirs", 2)->getStatusCode());
    }

    #[Test]
    public function an_expired_story_is_gone_unless_a_highlight_keeps_it(): void
    {
        $kept = $this->text(2);
        $gone = $this->text(2);
        Story::query()->whereIn('id', [$kept, $gone])->update(['expires_at' => Carbon::now()->subMinute()]);
        $this->database()->table('glimpse_highlights')->insert(['id' => 1, 'user_id' => 2, 'title' => 'Keep', 'position' => 0, 'created_at' => Carbon::now()]);
        $this->database()->table('glimpse_highlight_stories')->insert(['highlight_id' => 1, 'story_id' => $kept, 'position' => 0]);

        // Neither is live any more...
        $this->assertSame([], $this->ids(4));
        // ...but the kept one can still be opened from its highlight, and the other can't.
        $this->assertSame(200, $this->get("/api/glimpse-stories/$kept", 4)->getStatusCode());
        $this->assertSame(404, $this->get("/api/glimpse-stories/$gone", 4)->getStatusCode());
    }

    #[Test]
    public function muted_people_leave_your_bar_but_their_stories_still_open(): void
    {
        $id = $this->text(2);
        $mute = $this->send($this->request('POST', '/api/glimpse-mutes', ['authenticatedAs' => 4, 'json' => ['data' => ['type' => 'glimpse-mutes', 'relationships' => ['mutedUser' => ['data' => ['type' => 'users', 'id' => '2']]]]]]));
        $this->assertSame(201, $mute->getStatusCode(), (string) $mute->getBody());

        $this->assertSame([], $this->ids(4, ['bar' => '1']));
        $this->assertSame([], $this->ids(4));
        $this->assertSame(200, $this->get("/api/glimpse-stories/$id", 4)->getStatusCode());
        $this->assertSame([$id], $this->ids(3, ['bar' => '1']));

        // Muting yourself, or twice, is refused; unmuting works.
        $self = $this->send($this->request('POST', '/api/glimpse-mutes', ['authenticatedAs' => 4, 'json' => ['data' => ['type' => 'glimpse-mutes', 'relationships' => ['mutedUser' => ['data' => ['type' => 'users', 'id' => '4']]]]]]));
        $this->assertSame(422, $self->getStatusCode());
        $muteId = $this->json($mute)['data']['id'];
        $this->assertSame(204, $this->send($this->request('DELETE', "/api/glimpse-mutes/$muteId", ['authenticatedAs' => 4]))->getStatusCode());
        $this->assertSame([$id], $this->ids(4, ['bar' => '1']));
    }

    #[Test]
    public function the_bar_shows_the_thirty_most_recent_posters(): void
    {
        $users = [];
        for ($i = 10; $i < 42; $i++) {
            $users[] = ['id' => $i, 'username' => "member$i", 'email' => "member$i@machine.local", 'is_email_confirmed' => 1, 'password' => 'x'];
        }
        $this->database()->table('users')->insert($users);
        foreach (range(10, 41) as $n => $id) {
            $this->insert($id, ['created_at' => Carbon::now()->subMinutes(100 - $n)]);
        }

        $response = $this->get('/api/glimpse-stories', 4, ['filter' => ['bar' => '1'], 'page' => ['limit' => 100]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $authors = array_unique(array_map(fn (array $row): int => (int) $row['relationships']['user']['data']['id'], $this->json($response)['data']));
        $this->assertCount(30, $authors);
        // The two oldest posters are the ones left out.
        $this->assertNotContains(10, $authors);
        $this->assertNotContains(11, $authors);
    }

    #[Test]
    public function media_files_are_served_only_to_people_who_may_see_the_story(): void
    {
        $media = $this->uploadId(2);
        // Before it's posted: its uploader can preview it, nobody else.
        $this->assertSame(200, $this->get("/glimpse/media/$media", 2)->getStatusCode());
        $this->assertSame(403, $this->get("/glimpse/media/$media", 4)->getStatusCode());

        $response = $this->post(2, ['kind' => 'photo', 'audience' => 'members'], $media);
        $this->assertSame(201, $response->getStatusCode());

        $this->assertSame(403, $this->get("/glimpse/media/$media")->getStatusCode());
        $file = $this->get("/glimpse/media/$media", 4);
        $this->assertSame(200, $file->getStatusCode());
        $this->assertSame('image/webp', $file->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('nosniff', $file->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('private', $file->getHeaderLine('Cache-Control'));

        // Byte ranges, which Safari needs to play video.
        $range = $this->send($this->request('GET', "/glimpse/media/$media", ['authenticatedAs' => 4])->withHeader('Range', 'bytes=0-9'));
        $this->assertSame(206, $range->getStatusCode());
        $this->assertSame('10', $range->getHeaderLine('Content-Length'));
        $this->assertSame(10, strlen((string) $range->getBody()));
        $this->assertMatchesRegularExpression('#^bytes 0-9/\d+$#', $range->getHeaderLine('Content-Range'));

        $this->assertSame(404, $this->get('/glimpse/media/999', 4)->getStatusCode());
    }

    #[Test]
    public function a_notification_about_a_story_vanishes_with_the_story(): void
    {
        $id = $this->text(2, ['caption' => 'Hi @stranger']);
        $notifications = fn () => $this->json($this->get('/api/notifications', 4))['data'];
        $this->assertCount(1, $notifications());

        Story::query()->where('id', $id)->update(['expires_at' => Carbon::now()->subMinute()]);
        $this->assertCount(0, $notifications());
    }
}
