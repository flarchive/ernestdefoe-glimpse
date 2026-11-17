<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A flood guard for uploads and new stories: one every few seconds per member (moderators exempt).
 * The daily limit is StoryWriter's.
 */
class Throttle
{
    private const ROUTES = ['glimpse-stories.create', 'glimpse-media.upload'];

    public function __construct(private readonly Config $config)
    {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if (! in_array($request->getAttribute('routeName'), self::ROUTES, true)) {
            return null;
        }

        $actor = RequestUtil::getActor($request);
        $seconds = $this->config->floodSeconds();
        if ($seconds === 0 || $actor->isGuest() || $actor->hasPermission('glimpse.moderate')) {
            return null;
        }

        $since = Carbon::now()->subSeconds($seconds);

        return $request->getAttribute('routeName') === 'glimpse-stories.create'
            ? Story::query()->where('user_id', $actor->id)->where('created_at', '>', $since)->exists()
            : StoryMedia::query()->where('user_id', $actor->id)->where('created_at', '>', $since)->exists();
    }
}
