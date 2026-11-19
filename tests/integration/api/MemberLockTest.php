<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 The daily story limit and the highlight limit are counted and saved under a per-member lock. A request
 * that finds the lock taken never waits: it gets a "try again" validation error, and nothing is written.
 * The lock is let go after every save, whether it worked or was refused.
 */
class MemberLockTest extends GlimpseTestCase
{
    protected bool $follows = false;

    private function hold(string $name, int $userId): \Illuminate\Contracts\Cache\Lock
    {
        $this->send($this->request('GET', '/api')); // boot the app
        $store = $this->app()->getContainer()->make(Repository::class)->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $lock = $store->lock("ernestdefoe-glimpse.$name.$userId", 10);
        $this->assertTrue($lock->get());

        return $lock;
    }

    private function highlight(int $as, string $title): \Psr\Http\Message\ResponseInterface
    {
        return $this->send($this->request('POST', '/api/glimpse-highlights', ['authenticatedAs' => $as, 'json' => ['data' => ['type' => 'glimpse-highlights', 'attributes' => ['title' => $title]]]]));
    }

    private function busy(\Psr\Http\Message\ResponseInterface $response, string $pointer): void
    {
        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->json($response)['errors'][0];
        // The test forum's catalogue doesn't load extension locales, so the key itself may come back.
        $this->assertContains($error['detail'], ['You just did the same thing a moment ago. Please try again.', 'ernestdefoe-glimpse.api.busy']);
        $this->assertSame("/data/attributes/$pointer", $error['source']['pointer']);
    }

    #[Test]
    public function a_story_posted_while_the_members_lock_is_held_is_told_to_try_again(): void
    {
        $this->setting('ernestdefoe-glimpse.daily_limit', 1); // before the app boots: settings are cached
        $lock = $this->hold('story', 2);
        $this->busy($this->post(2, ['kind' => 'text', 'text' => 'Hello', 'background' => 1]), 'kind');
        $this->assertSame(0, $this->database()->table('glimpse_stories')->count());

        $this->assertSame(201, $this->post(4, ['kind' => 'text', 'text' => 'Hi', 'background' => 1])->getStatusCode(), 'Another member is not held up.');

        $lock->release();
        $this->assertSame(201, $this->post(2, ['kind' => 'text', 'text' => 'Hello', 'background' => 1])->getStatusCode());

        // Let go after a refused save too: at the limit, the next one is refused for the limit, not the lock.
        $limit = $this->post(2, ['kind' => 'text', 'text' => 'Again', 'background' => 1]);
        $this->assertSame(422, $limit->getStatusCode());
        $this->assertStringContainsString('daily_limit', (string) $limit->getBody());
        $this->assertStringContainsString('daily_limit', (string) $this->post(2, ['kind' => 'text', 'text' => 'Again', 'background' => 1])->getBody());
    }

    #[Test]
    public function a_highlight_created_while_the_members_lock_is_held_is_told_to_try_again(): void
    {
        $lock = $this->hold('highlight', 2);
        $this->busy($this->highlight(2, 'Trips'), 'title');
        $this->assertSame(0, $this->database()->table('glimpse_highlights')->count());

        $lock->release();
        $this->assertSame(201, $this->highlight(2, 'Trips')->getStatusCode());
        $this->assertSame(201, $this->highlight(2, 'Food')->getStatusCode(), 'Let go after the first save.');
    }
}
