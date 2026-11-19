<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Event;

use ErnestDefoe\Glimpse\Story;
use Flarum\User\User;

/** A story went live: posted by someone who needs no approval, or approved by a moderator. Glimpse queues its notifications from this; other extensions may listen too. */
final readonly class Published
{
    public function __construct(public Story $story, public User $actor)
    {
    }
}
