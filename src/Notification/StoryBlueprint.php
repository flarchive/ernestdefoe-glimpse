<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Notification;

use ErnestDefoe\Glimpse\Story;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * A notification about a story. Its subject is the story, so core's notification list drops it as soon
 * as the reader can no longer see the story (it expired, was hidden, or its audience shut them out).
 */
abstract class StoryBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public readonly Story $story, public readonly User $from)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->story;
    }

    public function getFromUser(): ?User
    {
        return $this->from;
    }

    public function getData(): mixed
    {
        return null;
    }

    public static function getSubjectModel(): string
    {
        return Story::class;
    }
}
