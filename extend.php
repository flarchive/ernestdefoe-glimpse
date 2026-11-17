<?php

declare(strict_types=1);

/*
 * Glimpse: Stories for Flarum.
 *
 * Short photo, video and text stories that disappear after a day, shown as a bar of avatar rings and
 * played full screen. Every read goes through one visibility rule (Access\ScopeStoryVisibility),
 * every list through Flarum's search system (Search\StorySearcher), and every write through the
 * JSON:API resources in Api\. Uploads are sniffed, re-encoded or scrubbed (Media\Uploader) and served
 * only after the visibility check (Http\MediaController).
 *
 * 🚨 String literals only below, never one of our class constants read at load time: it would
 * autoload during boot and fatal every request while a deploy swaps composer's autoload map.
 */

use ErnestDefoe\Glimpse\Access\HideFromIgnored;
use ErnestDefoe\Glimpse\Access\HideSuspendedAuthors;
use ErnestDefoe\Glimpse\Access\HighlightPolicy;
use ErnestDefoe\Glimpse\Access\ScopeStoryVisibility;
use ErnestDefoe\Glimpse\Access\StoryPolicy;
use ErnestDefoe\Glimpse\Api;
use ErnestDefoe\Glimpse\Approval\TrustLevelsServiceProvider;
use ErnestDefoe\Glimpse\Console\PruneCommand;
use ErnestDefoe\Glimpse\Event\Published;
use ErnestDefoe\Glimpse\Follows\FollowsServiceProvider;
use ErnestDefoe\Glimpse\GlimpseServiceProvider;
use ErnestDefoe\Glimpse\Highlight;
use ErnestDefoe\Glimpse\Ignores\IgnoresServiceProvider;
use ErnestDefoe\Glimpse\Http\MediaController;
use ErnestDefoe\Glimpse\Listener\DeleteMemberData;
use ErnestDefoe\Glimpse\Listener\QueueNotifications;
use ErnestDefoe\Glimpse\Notification\NewStoryBlueprint;
use ErnestDefoe\Glimpse\Notification\StoryMentionedBlueprint;
use ErnestDefoe\Glimpse\Search;
use ErnestDefoe\Glimpse\Story;
use ErnestDefoe\Glimpse\Throttle;
use ErnestDefoe\Glimpse\Widget\StoriesBlock;
use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Search\Database\DatabaseSearchDriver;
use Flarum\User\Event\Deleting as UserDeleting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The viewer, the create sheet and the pages are chunks in here, fetched when first needed.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/glimpse', 'glimpse.stories')
        ->route('/glimpse/queue', 'glimpse.queue')
        ->route('/glimpse/{id:\d+}', 'glimpse.story')
        ->route('/u/{username}/glimpse', 'user.glimpse'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-glimpse', __DIR__.'/views'),

    // No file extension at the end: nginx serves *.webp/*.mp4 paths from disk and PHP would never see it.
    (new Extend\Routes('forum'))
        ->get('/glimpse/media/{id:\d+}', 'glimpse.media', MediaController::class),

    (new Extend\Filesystem())
        ->disk('ernestdefoe-glimpse', fn (Paths $paths): array => [
            'root' => $paths->storage.'/glimpse',
            'visibility' => 'private',
        ]),

    new Extend\ApiResource(Api\StoryResource::class),
    new Extend\ApiResource(Api\StoryMediaResource::class),
    new Extend\ApiResource(Api\StoryViewResource::class),
    new Extend\ApiResource(Api\HighlightResource::class),
    new Extend\ApiResource(Api\StoryReportResource::class),
    new Extend\ApiResource(Api\StoryMuteResource::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    (new Extend\ModelVisibility(Story::class))
        ->scope(ScopeStoryVisibility::class),

    (new Extend\Policy())
        ->modelPolicy(Story::class, StoryPolicy::class)
        ->modelPolicy(Highlight::class, HighlightPolicy::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(Story::class, Search\StorySearcher::class)
        ->addFilter(Search\StorySearcher::class, Search\Filter\BarFilter::class)
        ->addFilter(Search\StorySearcher::class, Search\Filter\LiveFilter::class)
        ->addFilter(Search\StorySearcher::class, Search\Filter\AuthorFilter::class)
        ->addFilter(Search\StorySearcher::class, Search\Filter\StatusFilter::class)
        ->addFilter(Search\StorySearcher::class, Search\Filter\ReportedFilter::class)
        ->addFilter(Search\StorySearcher::class, Search\Filter\FollowingFilter::class),

    (new Extend\ServiceProvider())
        ->register(GlimpseServiceProvider::class),

    // Other extensions, each wired in only while it is enabled. Nothing else checks what's installed.
    (new Extend\Conditional())
        ->whenExtensionEnabled('ianm-follow-users', fn (): array => [
            (new Extend\ServiceProvider())->register(FollowsServiceProvider::class),
        ])
        ->whenExtensionEnabled('flarum-suspend', fn (): array => [
            (new Extend\ModelVisibility(Story::class))->scope(HideSuspendedAuthors::class),
        ])
        ->whenExtensionEnabled('fof-ignore-users', fn (): array => [
            (new Extend\ServiceProvider())->register(IgnoresServiceProvider::class),
            (new Extend\ModelVisibility(Story::class))->scope(HideFromIgnored::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-trust-levels', fn (): array => [
            (new Extend\ServiceProvider())->register(TrustLevelsServiceProvider::class),
        ])
        // The ring bar as a Page Builder block.
        ->whenExtensionEnabled('ernestdefoe-page-builder', fn (): array => [
            new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(StoriesBlock::class),
        ]),

    (new Extend\Notification())
        ->type(StoryMentionedBlueprint::class, ['alert', 'email'])
        ->type(NewStoryBlueprint::class, ['alert']),

    (new Extend\Event())
        ->listen(Published::class, QueueNotifications::class)
        ->listen(UserDeleting::class, DeleteMemberData::class),

    (new Extend\ThrottleApi())
        ->set('glimpse', Throttle::class),

    (new Extend\Settings())
        ->default('ernestdefoe-glimpse.lifetime_hours', 24)
        ->default('ernestdefoe-glimpse.kind_photo', true)
        ->default('ernestdefoe-glimpse.kind_video', true)
        ->default('ernestdefoe-glimpse.kind_text', true)
        ->default('ernestdefoe-glimpse.image_max_mb', 8)
        ->default('ernestdefoe-glimpse.video_max_mb', 20)
        ->default('ernestdefoe-glimpse.video_max_seconds', 15)
        ->default('ernestdefoe-glimpse.slide_seconds', 6)
        ->default('ernestdefoe-glimpse.bar_placement', 'index')
        ->default('ernestdefoe-glimpse.bar_order', 'smart')
        ->default('ernestdefoe-glimpse.default_audience', 'everyone')
        ->default('ernestdefoe-glimpse.ignore_hides', true)
        ->default('ernestdefoe-glimpse.reactions', '❤️ 😂 😮 😢 👏 🔥')
        ->default('ernestdefoe-glimpse.reply_via', 'auto')
        ->default('ernestdefoe-glimpse.approval_posts', 0)
        ->default('ernestdefoe-glimpse.approval_days', 0)
        ->default('ernestdefoe-glimpse.approval_trust_level', 0)
        ->default('ernestdefoe-glimpse.notify_followers', false)
        ->default('ernestdefoe-glimpse.notify_cap', 500)
        ->default('ernestdefoe-glimpse.daily_limit', 20)
        ->default('ernestdefoe-glimpse.flood_seconds', 3)
        ->default('ernestdefoe-glimpse.highlights_max', 20)
        ->default('ernestdefoe-glimpse.highlight_stories_max', 100),

    (new Extend\Console())
        ->command(PruneCommand::class)
        ->schedule(PruneCommand::class, function (ScheduledEvent $event): void {
            $event->hourly()->withoutOverlapping();
        }),
];
