<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Api;

use ErnestDefoe\Glimpse\Ids;
use ErnestDefoe\Glimpse\Story;
use ErnestDefoe\Glimpse\StoryView;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\OffsetPagination;
use Tobyz\JsonApiServer\Pagination\Pagination;

/**
 * The `glimpse-views` type: the seen-by list, for a story's author and moderators only.
 *
 * GET /api/glimpse-views?filter[story]=<id>   newest first, 20 at a time, with each viewer.
 *
 * @extends AbstractDatabaseResource<StoryView>
 */
class StoryViewResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'glimpse-views';
    }

    public function model(): string
    {
        return StoryView::class;
    }

    /**
     * Rows are keyed by (story, viewer), so the JSON:API id is both.
     *
     * @param object $model
     * @param BaseContext $context
     */
    public function getId(object $model, BaseContext $context): string
    {
        return $model instanceof StoryView ? $model->story_id.'-'.$model->user_id : '';
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->defaultInclude(['user'])
                ->eagerLoad(['user.groups'])
                ->paginate(20, 50)
                ->query(fn (Builder $query, ?Pagination $pagination, Context $context, array $filters): Context => $this->forStory($query, $pagination, $context, $filters)),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\DateTime::make('viewedAt')->property('viewed_at'),
            Schema\Str::make('reaction')->nullable(),
            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
        ];
    }

    /**
     * @param Builder<StoryView> $query
     * @param array<mixed> $filters
     */
    private function forStory(Builder $query, ?Pagination $pagination, Context $context, array $filters): Context
    {
        $actor = $context->getActor();
        $story = Story::whereVisibleTo($actor)->find(Ids::one($filters['story'] ?? null));

        if ($story === null || ! $actor->can('seeViewers', $story)) {
            throw new PermissionDeniedException();
        }

        $query->where('glimpse_views.story_id', $story->id)
            ->where('glimpse_views.user_id', '!=', $story->user_id)
            ->orderByDesc('glimpse_views.viewed_at')
            ->orderByDesc('glimpse_views.user_id');

        if ($pagination instanceof OffsetPagination) {
            $pagination->apply($query);
        }

        return $context->withQuery($query);
    }
}
