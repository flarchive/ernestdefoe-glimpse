<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * Runs a count-then-save for one member at a time (the daily story limit, the highlight limit), so two
 * requests at once can't both slip under a limit. Never waits: a request that finds the lock taken is
 * told to try again (a validation error, not a blocked PHP worker or a 500).
 */
class MemberLock
{
    public function __construct(private readonly Repository $cache, private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(string $name, int $userId, string $field, callable $work): mixed
    {
        // Every cache store Flarum ships (file, array, redis) can lock; one that can't still checks the limit.
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            return $work();
        }

        $lock = $store->lock("ernestdefoe-glimpse.$name.$userId", 10);
        if ($lock->get() !== true) {
            throw new ValidationException([$field => $this->translator->trans('ernestdefoe-glimpse.api.busy')]);
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
