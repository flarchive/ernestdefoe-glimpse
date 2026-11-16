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
    public function you_cannot_report_what_you_cannot_see(): void
    {
        $id = $this->insert(2, ['status' => 'hidden']);
        $response = $this->send($this->request('POST', '/api/glimpse-reports', ['authenticatedAs' => 4, 'json' => ['data' => [
            'type' => 'glimpse-reports',
            'attributes' => ['reason' => 'spam'],
            'relationships' => ['story' => ['data' => ['type' => 'glimpse-stories', 'id' => (string) $id]]],
        ]]]));
        $this->assertSame(404, $response->getStatusCode());
    }
}
