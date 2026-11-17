<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use ErnestDefoe\Glimpse\Media\Storage;
use Illuminate\Database\ConnectionInterface;

/**
 * Deletes stories and uploads with everything that hangs off them, set-wise.
 *
 * The foreign keys cascade on MySQL and PostgreSQL, but SQLite only enforces them when a forum turns
 * that on, so the dependent rows are deleted here explicitly: the same result on every database. Rows
 * go in one transaction; files go only after it commits.
 */
class Cleanup
{
    public function __construct(private readonly ConnectionInterface $db, private readonly Storage $storage)
    {
    }

    /** @param list<int> $storyIds */
    public function stories(array $storyIds): void
    {
        if ($storyIds === []) {
            return;
        }

        $mediaIds = Ids::of(Story::query()->whereIn('id', $storyIds)->pluck('media_id'));
        $paths = $this->paths($mediaIds);

        $this->db->transaction(function () use ($storyIds, $mediaIds): void {
            StoryView::query()->whereIn('story_id', $storyIds)->delete();
            StoryReport::query()->whereIn('story_id', $storyIds)->delete();
            // Pivot tables: no model of their own.
            foreach (['glimpse_mentions', 'glimpse_highlight_stories'] as $pivot) {
                $this->db->table($pivot)->whereIn('story_id', $storyIds)->delete();
            }
            Story::query()->whereIn('id', $storyIds)->delete();
            StoryMedia::query()->whereIn('id', $mediaIds)->delete();
        });

        $this->storage->delete($paths);
    }

    /** @param list<int> $mediaIds uploads no story uses */
    public function uploads(array $mediaIds): void
    {
        $paths = $this->paths($mediaIds);
        StoryMedia::query()->whereIn('id', $mediaIds)->delete();
        $this->storage->delete($paths);
    }

    /**
     * A member's files, listed before their account is deleted: on MySQL and PostgreSQL the foreign
     * keys remove the rows with the account, and the paths with them.
     *
     * @return list<string>
     */
    public function memberFiles(int $userId): array
    {
        return array_values(array_filter(StoryMedia::query()->where('user_id', $userId)->pluck('path')->all(), is_string(...)));
    }

    /**
     * Everything a member posted, once their account is deleted.
     *
     * @param list<string> $files from memberFiles(), taken before the account went
     */
    public function member(int $userId, array $files): void
    {
        $this->stories(Ids::of(Story::query()->where('user_id', $userId)->pluck('id')));
        $this->uploads(Ids::of(StoryMedia::query()->where('user_id', $userId)->pluck('id')));
        $this->storage->delete($files);
        $this->db->transaction(function () use ($userId): void {
            $highlights = Ids::of(Highlight::query()->where('user_id', $userId)->pluck('id'));
            $this->db->table('glimpse_highlight_stories')->whereIn('highlight_id', $highlights)->delete();
            Highlight::query()->whereIn('id', $highlights)->delete();
            StoryMute::query()->where('user_id', $userId)->orWhere('muted_user_id', $userId)->delete();
            StoryView::query()->where('user_id', $userId)->delete();
            StoryReport::query()->where('user_id', $userId)->delete();
            $this->db->table('glimpse_mentions')->where('user_id', $userId)->delete();
        });
    }

    /**
     * @param list<int> $mediaIds
     * @return list<string>
     */
    private function paths(array $mediaIds): array
    {
        return $mediaIds === [] ? [] : array_values(array_filter(StoryMedia::query()->whereIn('id', $mediaIds)->pluck('path')->all(), is_string(...)));
    }
}
