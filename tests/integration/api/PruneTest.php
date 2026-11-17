<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Media\Storage;
use ErnestDefoe\Glimpse\Story;
use ErnestDefoe\Glimpse\StoryMedia;
use ErnestDefoe\Glimpse\Tests\integration\GlimpseTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** The hourly prune: expired stories and unused uploads go, with their files; highlights keep theirs. */
class PruneTest extends GlimpseTestCase
{
    protected bool $follows = false;

    private function prune(bool $dry = false): string
    {
        $command = $this->app()->getContainer()->make(\ErnestDefoe\Glimpse\Console\PruneCommand::class);
        $output = new BufferedOutput();
        $command->run(new ArrayInput($dry ? ['--dry-run' => true] : []), $output);

        return $output->fetch();
    }

    #[Test]
    public function expired_stories_and_old_unused_uploads_go_with_their_files(): void
    {
        $disk = $this->app()->getContainer()->make(Storage::class)->disk();

        $expiredMedia = $this->uploadId(2);
        $keptMedia = $this->uploadId(2);
        $liveMedia = $this->uploadId(2);
        $unused = $this->uploadId(2);
        $fresh = $this->uploadId(2);

        $expired = (int) $this->json($this->post(2, ['kind' => 'photo'], $expiredMedia))['data']['id'];
        $kept = (int) $this->json($this->post(2, ['kind' => 'photo'], $keptMedia))['data']['id'];
        $live = (int) $this->json($this->post(2, ['kind' => 'photo'], $liveMedia))['data']['id'];
        $this->postJson('/api/glimpse-stories/seen', 3, ['ids' => [$expired]]);

        Story::query()->whereIn('id', [$expired, $kept])->update(['expires_at' => Carbon::now()->subHour()]);
        StoryMedia::query()->where('id', $unused)->update(['created_at' => Carbon::now()->subDays(2)]);
        $this->database()->table('glimpse_highlights')->insert(['id' => 1, 'user_id' => 2, 'title' => 'Keep', 'position' => 0, 'created_at' => Carbon::now()]);
        $this->database()->table('glimpse_highlight_stories')->insert(['highlight_id' => 1, 'story_id' => $kept, 'position' => 0]);

        $paths = StoryMedia::query()->pluck('path', 'id');

        $this->assertStringContainsString('Would delete 1 expired stories and 1 unused uploads', $this->prune(true));
        $this->assertSame(3, Story::query()->count());

        $this->assertStringContainsString('Deleted 1 expired stories and 1 unused uploads', $this->prune());
        $this->assertEqualsCanonicalizing([$kept, $live], Story::query()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([(int) $keptMedia, (int) $liveMedia, (int) $fresh], StoryMedia::query()->pluck('id')->all());
        $this->assertSame(0, $this->database()->table('glimpse_views')->where('story_id', $expired)->count());

        $this->assertFalse($disk->exists($paths[(int) $expiredMedia]));
        $this->assertFalse($disk->exists($paths[(int) $unused]));
        $this->assertTrue($disk->exists($paths[(int) $keptMedia]));

        // Twice is harmless.
        $this->assertStringContainsString('Deleted 0 expired stories and 0 unused uploads', $this->prune());
    }

    #[Test]
    public function deleting_a_story_takes_its_file_and_deleting_a_member_takes_all_of_theirs(): void
    {
        $disk = $this->app()->getContainer()->make(Storage::class)->disk();
        $media = $this->uploadId(2);
        $id = (int) $this->json($this->post(2, ['kind' => 'photo'], $media))['data']['id'];
        $path = StoryMedia::query()->findOrFail((int) $media)->path;

        $this->send($this->request('DELETE', "/api/glimpse-stories/$id", ['authenticatedAs' => 2]));
        $this->assertFalse($disk->exists($path));
        $this->assertSame(0, StoryMedia::query()->count());

        $other = $this->uploadId(3);
        $otherPath = StoryMedia::query()->findOrFail((int) $other)->path;
        $this->assertSame(204, $this->send($this->request('DELETE', '/api/users/3', ['authenticatedAs' => 1]))->getStatusCode());
        $this->assertFalse($disk->exists($otherPath));
        $this->assertSame(0, StoryMedia::query()->count());
    }
}
