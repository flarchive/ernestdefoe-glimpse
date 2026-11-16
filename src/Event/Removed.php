<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Event;

use ErnestDefoe\Glimpse\Story;
use Flarum\User\User;

/** A story was deleted by its author or a moderator. Dispatched for other extensions (XP, logs) to follow. */
final readonly class Removed
{
    public function __construct(public Story $story, public User $actor)
    {
    }
}
