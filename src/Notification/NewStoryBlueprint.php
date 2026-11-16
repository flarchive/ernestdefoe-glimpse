<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Notification;

/** "X posted a story." Alert only, to followers, when the admin turned it on. */
class NewStoryBlueprint extends StoryBlueprint
{
    public static function getType(): string
    {
        return 'glimpseNewStory';
    }
}
