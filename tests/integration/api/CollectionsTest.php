<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Highlights and reports. */
class CollectionsTest extends GlimpseTestCase
{
    protected bool $follows = false;

    /** @param list<int> $stories */
    private function highlight(int $as, string $title, array $stories): \Psr\Http\Message\ResponseInterface
    {
        return $this->send($this->request('POST', '/api/glimpse-highlights', ['authenticatedAs' => $as, 'json' => ['data' => [
            'type' => 'glimpse-highlights',
            'attributes' => ['title' => $title],
            'relationships' => ['stories' => ['data' => array_map(fn (int $id): array => ['type' => 'glimpse-stories', 'id' => (string) $id], $stories)]],
        ]]]));
    }

    #[Test]
    public function a_highlight_holds_only_your_own_stories_in_order(): void
    {
        $a = $this->text(2);
        $b = $this->text(2);
        $theirs = $this->text(4);

        $response = $this->highlight(2, 'Trips', [$b, $theirs, $a]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $list = $this->json($this->get('/api/glimpse-highlights', 3, ['filter' => ['user' => '2']]));
        $this->assertSame('Trips', $list['data'][0]['attributes']['title']);
        $this->assertSame([(string) $b, (string) $a], array_column($list['data'][0]['relationships']['stories']['data'], 'id'));
    }

    #[Test]
    public function a_highlight_shows_each_viewer_only_the_stories_they_may_see(): void
    {
        $public = $this->text(2);
        $members = $this->text(2, ['audience' => 'members']);
        $this->highlight(2, 'Mixed', [$public, $members]);

        $guest = $this->json($this->get('/api/glimpse-highlights', null, ['filter' => ['user' => '2']]));
        $this->assertSame([(string) $public], array_column($guest['data'][0]['relationships']['stories']['data'], 'id'));
    }

    #[Test]
    public function only_its_owner_changes_or_deletes_a_highlight_and_limits_hold(): void
    {
        $id = $this->json($this->highlight(2, 'Mine', [$this->text(2)]))['data']['id'];
        $rename = fn (int $as) => $this->send($this->request('PATCH', "/api/glimpse-highlights/$id", ['authenticatedAs' => $as, 'json' => ['data' => ['type' => 'glimpse-highlights', 'id' => $id, 'attributes' => ['title' => 'Renamed']]]]));
        $this->assertSame(403, $rename(4)->getStatusCode());
        $this->assertSame(200, $rename(2)->getStatusCode());
        $this->assertSame(403, $this->send($this->request('DELETE', "/api/glimpse-highlights/$id", ['authenticatedAs' => 4]))->getStatusCode());
        $this->assertSame(422, $this->highlight(2, str_repeat('x', 41), [])->getStatusCode());
        $this->assertSame(204, $this->send($this->request('DELETE', "/api/glimpse-highlights/$id", ['authenticatedAs' => 2]))->getStatusCode());
    }

    #[Test]
    public function only_the_owner_may_change_or_delete_a_highlight_not_a_guest_member_or_moderator(): void
    {
        $id = $this->json($this->highlight(2, 'Mine', [$this->text(2)]))['data']['id'];
        $rename = fn (?int $as) => $this->send($this->request('PATCH', "/api/glimpse-highlights/$id", ($as ? ['authenticatedAs' => $as] : []) + ['json' => ['data' => ['type' => 'glimpse-highlights', 'id' => $id, 'attributes' => ['title' => 'Taken']]]]));
        $delete = fn (?int $as) => $this->send($this->request('DELETE', "/api/glimpse-highlights/$id", $as ? ['authenticatedAs' => $as] : []));

        // A guest write is refused before any endpoint runs (no session, so no CSRF token), and the policy says no too.
        $this->assertSame(400, $rename(null)->getStatusCode(), 'guest rename');
        $this->assertSame(400, $delete(null)->getStatusCode(), 'guest delete');
        $highlight = \ErnestDefoe\Glimpse\Highlight::query()->findOrFail($id);
        $this->assertFalse((new \Flarum\User\Guest())->can('update', $highlight));
        $this->assertFalse((new \Flarum\User\Guest())->can('delete', $highlight));
        foreach ([4 => 'member', 5 => 'moderator', 1 => 'admin'] as $as => $who) {
            $this->assertSame(403, $rename($as)->getStatusCode(), "$who rename");
            $this->assertSame(403, $delete($as)->getStatusCode(), "$who delete");
        }
        $this->assertSame('Mine', $this->database()->table('glimpse_highlights')->where('id', $id)->value('title'));

        // Refilling someone else's highlight with your own story is refused the same way.
        $mine = $this->text(4);
        $refill = $this->send($this->request('PATCH', "/api/glimpse-highlights/$id", ['authenticatedAs' => 4, 'json' => ['data' => [
            'type' => 'glimpse-highlights', 'id' => $id,
            'relationships' => ['stories' => ['data' => [['type' => 'glimpse-stories', 'id' => (string) $mine]]]],
        ]]]));
        $this->assertSame(403, $refill->getStatusCode());
        $this->assertSame(0, $this->database()->table('glimpse_highlight_stories')->where('story_id', $mine)->count());

        $this->assertSame(200, $rename(2)->getStatusCode());
        $this->assertSame(204, $delete(2)->getStatusCode());
        $this->assertSame(0, $this->database()->table('glimpse_highlights')->where('id', $id)->count());
    }

    #[Test]
    public function the_highlight_limit_holds(): void
    {
        $this->setting('ernestdefoe-glimpse.highlights_max', 1);
        $this->assertSame(201, $this->highlight(2, 'One', [])->getStatusCode());
        $this->assertSame(422, $this->highlight(2, 'Two', [])->getStatusCode());
    }

    #[Test]
    public function a_story_is_reported_once_per_person_and_only_moderators_read_reports(): void
    {
        $id = $this->text(2);
        $report = fn (int $as) => $this->send($this->request('POST', '/api/glimpse-reports', ['authenticatedAs' => $as, 'json' => ['data' => [
            'type' => 'glimpse-reports',
            'attributes' => ['reason' => 'spam', 'detail' => 'An ad'],
            'relationships' => ['story' => ['data' => ['type' => 'glimpse-stories', 'id' => (string) $id]]],
        ]]]));

        $this->assertSame(201, $report(4)->getStatusCode());
        $this->assertSame(422, $report(4)->getStatusCode());
        $this->assertSame(403, $report(2)->getStatusCode(), 'Not your own story.');

        $this->assertSame(403, $this->get('/api/glimpse-reports', 4)->getStatusCode());
        $reports = $this->json($this->get('/api/glimpse-reports', 5));
        $this->assertCount(1, $reports['data']);
        $this->assertSame([$id], $this->ids(5, ['reported' => '1']));

        $reportId = $reports['data'][0]['id'];
        $this->assertSame(403, $this->send($this->request('DELETE', "/api/glimpse-reports/$reportId", ['authenticatedAs' => 4]))->getStatusCode());
        $this->assertSame(204, $this->send($this->request('DELETE', "/api/glimpse-reports/$reportId", ['authenticatedAs' => 5]))->getStatusCode());
    }

    #[Test]
    public function only_moderators_can_filter_stories_by_whether_they_have_reports(): void
    {
        $reported = $this->text(2);
        $clean = $this->text(2);
        $this->database()->table('glimpse_reports')->insert(['story_id' => $reported, 'user_id' => 3, 'reason' => 'spam', 'created_at' => \Carbon\Carbon::now()]);

        // Members, the author and guests learn nothing either way round.
        foreach ([4 => 'member', 2 => 'author', 3 => 'reporter', null => 'guest'] as $as => $who) {
            $as = $as === '' ? null : $as;
            $this->assertSame([], $this->ids($as, ['reported' => '1']), $who);
            $this->assertSame([], $this->ids($as, ['-reported' => '1']), "$who, negated");
        }

        $this->assertSame([$reported], $this->ids(5, ['reported' => '1']));
        $this->assertSame([$clean], $this->ids(5, ['-reported' => '1']));
    }

    #[Test]
    public function a_story_you_cannot_see_is_reported_as_missing_exactly_like_one_that_never_existed(): void
    {
        $report = fn (int $id): \Psr\Http\Message\ResponseInterface => $this->send($this->request('POST', '/api/glimpse-reports', ['authenticatedAs' => 4, 'json' => ['data' => [
            'type' => 'glimpse-reports',
            'attributes' => ['reason' => 'spam'],
            'relationships' => ['story' => ['data' => ['type' => 'glimpse-stories', 'id' => (string) $id]]],
        ]]]));

        // The error names the id the client sent, so compare with that id taken out.
        $body = fn (\Psr\Http\Message\ResponseInterface $response, int $id): string => str_replace("glimpse-stories.$id]", 'glimpse-stories.?]', (string) $response->getBody());
        $missing = $report(999999);
        $this->assertSame(404, $missing->getStatusCode());
        $expected = $body($missing, 999999);

        $hidden = [
            'hidden' => $this->insert(2, ['status' => 'hidden']),
            'pending' => $this->insert(2, ['status' => 'pending', 'approved_at' => null]),
            'followers only' => $this->insert(2, ['audience' => 'followers']),
            'expired' => $this->insert(2, ['expires_at' => \Carbon\Carbon::now()->subMinute()]),
        ];
        foreach ($hidden as $what => $id) {
            $response = $report($id);
            $this->assertSame(404, $response->getStatusCode(), $what);
            $this->assertSame($expected, $body($response, $id), "A $what story answers exactly like a missing one");
            $this->assertSame(0, $this->database()->table('glimpse_reports')->where('story_id', $id)->count(), $what);
        }

        // Reported first by someone else, still invisible to this member: still missing, never "already reported".
        $this->database()->table('glimpse_reports')->insert(['story_id' => $hidden['hidden'], 'user_id' => 3, 'reason' => 'spam', 'created_at' => \Carbon\Carbon::now()]);
        $this->assertSame($expected, $body($report($hidden['hidden']), $hidden['hidden']));

        $this->assertSame(201, $report($this->text(2, ['audience' => 'members']))->getStatusCode(), 'A story they can see still works.');
    }
}
