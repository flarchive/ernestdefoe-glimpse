<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Console;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Cleanup;
use ErnestDefoe\Glimpse\Ids;
use ErnestDefoe\Glimpse\Story;
use ErnestDefoe\Glimpse\StoryMedia;
use Flarum\Console\AbstractCommand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Symfony\Component\Console\Input\InputOption;

/**
 * Deletes what has run its course, in chunks, with whole-set DELETEs (never one row at a time):
 *
 * - stories past their expiry that no highlight keeps: Cleanup deletes their views, reports,
 *   mentions and highlight links, the stories and their media rows in one transaction, then the files;
 * - uploads no story ever used, once they are a day old.
 *
 * Scheduled hourly. Safe to run twice or at the same time as itself.
 */
class PruneCommand extends AbstractCommand
{
    private const CHUNK = 500;

    public function __construct(private readonly Cleanup $cleanup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('glimpse:prune')
            ->setDescription('Delete expired stories and unused uploads, with their files.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be deleted, and delete nothing.');
    }

    protected function fire(): int
    {
        $dry = (bool) $this->input->getOption('dry-run');
        $stories = 0;
        $uploads = 0;

        // chunkById pages by id, so deleting the chunk it just read never skips a row.
        $this->expired()->select('id')->chunkById(self::CHUNK, function ($chunk) use ($dry, &$stories): void {
            $stories += $chunk->count();
            if (! $dry) {
                $this->cleanup->stories(Ids::of($chunk->pluck('id')));
            }
        });

        $this->unused()->select('id')->chunkById(self::CHUNK, function ($chunk) use ($dry, &$uploads): void {
            $uploads += $chunk->count();
            if (! $dry) {
                $this->cleanup->uploads(Ids::of($chunk->pluck('id')));
            }
        });

        $this->info(($dry ? 'Would delete' : 'Deleted')." {$stories} expired stories and {$uploads} unused uploads.");

        return 0;
    }

    /** @return Builder<Story> */
    private function expired(): Builder
    {
        return Story::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->whereNotExists(fn (QueryBuilder $kept) => $kept->select('glimpse_highlight_stories.story_id')
                ->from('glimpse_highlight_stories')
                ->whereColumn('glimpse_highlight_stories.story_id', 'glimpse_stories.id'));
    }

    /** @return Builder<StoryMedia> */
    private function unused(): Builder
    {
        return StoryMedia::query()
            ->where('created_at', '<', Carbon::now()->subDay())
            ->whereNotExists(fn (QueryBuilder $used) => $used->select('glimpse_stories.media_id')
                ->from('glimpse_stories')
                ->whereColumn('glimpse_stories.media_id', 'glimpse_media.id'));
    }
}
