<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Listener;

use ErnestDefoe\Glimpse\Event\Published;
use ErnestDefoe\Glimpse\Job\SendStoryNotifications;
use Illuminate\Contracts\Queue\Queue;

/** Hands the notifications for a story that just went live to the queue. */
class QueueNotifications
{
    public function __construct(private readonly Queue $queue)
    {
    }

    public function handle(Published $event): void
    {
        $this->queue->push(new SendStoryNotifications($event->story->id));
    }
}
