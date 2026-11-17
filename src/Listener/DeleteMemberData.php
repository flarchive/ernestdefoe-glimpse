<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Listener;

use ErnestDefoe\Glimpse\Cleanup;
use Flarum\User\Event\Deleting;

/**
 * When a member is deleted, their stories, uploads (with the files), highlights, mutes, views and
 * reports go too, once the account really is gone.
 */
class DeleteMemberData
{
    public function __construct(private readonly Cleanup $cleanup)
    {
    }

    public function handle(Deleting $event): void
    {
        $id = $event->user->id;
        $files = $this->cleanup->memberFiles($id);
        $event->user->afterDelete(fn () => $this->cleanup->member($id, $files));
    }
}
