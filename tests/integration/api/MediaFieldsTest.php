<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 A story's media fields reach everyone who may see it, through every path a story comes back by. Loading
 * media through an endpoint's eagerLoad ran it through StoryMediaResource's owner-only scope, so in 1.0.0
 * one story (Show) and the report queue carried no media type or size for anyone but the author.
 */
class MediaFieldsTest extends GlimpseTestCase
{
    protected bool $follows = false;

    private function video(): int
    {
        $media = $this->database()->table('glimpse_media')->insertGetId([
            'user_id' => 2, 'kind' => 'video', 'mime' => 'video/mp4', 'path' => '2026/11/'.str_repeat('b', 32).'.mp4',
            'width' => 720, 'height' => 1280, 'duration_ms' => 4000, 'size' => 10, 'created_at' => Carbon::now(),
        ]);

        return $this->insert(2, ['kind' => 'video', 'text' => null, 'media_id' => $media]);
    }

    /** @param array<string, mixed> $attributes */
    private function assertMedia(array $attributes, string $where): void
    {
        $this->assertSame(['video/mp4', 720, 1280, 4000], [$attributes['mediaType'], $attributes['width'], $attributes['height'], $attributes['durationMs']], $where);
    }

    #[Test]
    public function every_path_carries_the_media_for_viewers_other_than_the_author(): void
    {
        $id = $this->video();

        foreach ([2 => 'author', 4 => 'member', 5 => 'moderator'] as $as => $who) {
            $this->assertMedia($this->json($this->get("/api/glimpse-stories/$id", $as))['data']['attributes'], "one story, as $who");
            $list = $this->json($this->get('/api/glimpse-stories', $as, ['filter' => ['live' => '1']]));
            $this->assertMedia($list['data'][0]['attributes'], "the list, as $who");
        }

        $reacted = $this->send($this->request('POST', "/api/glimpse-stories/$id/reaction", ['authenticatedAs' => 4, 'json' => ['reaction' => '🔥']]));
        $this->assertMedia($this->json($reacted)['data']['attributes'], 'the reaction answer');

        $this->send($this->request('POST', '/api/glimpse-highlights', ['authenticatedAs' => 2, 'json' => ['data' => [
            'type' => 'glimpse-highlights', 'attributes' => ['title' => 'Clips'],
            'relationships' => ['stories' => ['data' => [['type' => 'glimpse-stories', 'id' => (string) $id]]]],
        ]]]));
        $highlights = $this->json($this->get('/api/glimpse-highlights', 4, ['filter' => ['user' => '2']]));
        $this->assertMedia($this->included($highlights, $id), 'a highlight, as a member');

        $this->database()->table('glimpse_reports')->insert(['story_id' => $id, 'user_id' => 4, 'reason' => 'spam', 'created_at' => Carbon::now()]);
        $this->assertMedia($this->included($this->json($this->get('/api/glimpse-reports', 5)), $id), 'the report queue');
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function included(array $document, int $id): array
    {
        foreach ($document['included'] ?? [] as $resource) {
            if ($resource['type'] === 'glimpse-stories' && $resource['id'] === (string) $id) {
                return $resource['attributes'];
            }
        }
        $this->fail("Story $id is not included.");
    }
}
