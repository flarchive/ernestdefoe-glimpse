<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Access;

use ErnestDefoe\Glimpse\Config;
use ErnestDefoe\Glimpse\Ignores\IgnoreGraph;
use ErnestDefoe\Glimpse\Story;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * With fof/ignore-users (registered only while it's enabled) and the admin's switch on: an author who
 * ignores you hides their stories from you. Moderators are never hidden from.
 */
class HideFromIgnored
{
    public function __construct(private readonly Config $config, private readonly IgnoreGraph $ignores)
    {
    }

    /** @param Builder<Story> $query */
    public function __invoke(User $actor, Builder $query): void
    {
        if ($actor->isGuest() || ! $this->config->ignoreHidesMine() || $actor->hasPermission('glimpse.moderate')) {
            return;
        }

        $query->whereNotIn('glimpse_stories.user_id', $this->ignores->ignoring($actor->id));
    }
}
