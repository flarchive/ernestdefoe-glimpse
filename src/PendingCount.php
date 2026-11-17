<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use ErnestDefoe\Glimpse\Enum\Status;
use Illuminate\Contracts\Cache\Repository;

/**
 * How many stories wait for approval, for the moderators' sidebar badge. Cached for a minute, and
 * forgotten whenever a story is posted or moderated, so the forum payload doesn't count on every load.
 */
class PendingCount
{
    private const KEY = 'ernestdefoe-glimpse.pending';

    private const SECONDS = 60;

    public function __construct(private readonly Repository $cache)
    {
    }

    public function get(): int
    {
        $count = $this->cache->remember(self::KEY, self::SECONDS, fn (): int => Story::query()->where('status', Status::Pending->value)->count());

        return is_int($count) ? $count : 0;
    }

    public function forget(): void
    {
        $this->cache->forget(self::KEY);
    }
}
