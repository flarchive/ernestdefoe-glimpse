<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Api;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Story;
use ErnestDefoe\Glimpse\StoryReport;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `glimpse-reports` type.
 *
 * POST   /api/glimpse-reports         {reason, detail?, relationships.story}: any member who can see it
 * GET    /api/glimpse-reports         moderators: every report, newest first, with story and reporter
 * DELETE /api/glimpse-reports/{id}    moderators: dismiss
 *
 * @extends AbstractDatabaseResource<StoryReport>
 */
class StoryReportResource extends AbstractDatabaseResource
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function type(): string
    {
        return 'glimpse-reports';
    }

    public function model(): string
    {
        return StoryReport::class;
    }

    /** @param Builder<StoryReport> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = $context->getActor();
        if (! $actor->hasPermission('glimpse.moderate')) {
            $query->where('glimpse_reports.user_id', $actor->isGuest() ? 0 : $actor->id);
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->can('glimpse.moderate')
                ->defaultInclude(['story', 'story.user', 'user'])
                ->eagerLoad(['story.media'])
                ->defaultSort('-createdAt')
                ->paginate(20, 50),
            Endpoint\Create::make()
                ->authenticated(),
            Endpoint\Delete::make()
                ->authenticated()
                ->can('glimpse.moderate'),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('reason')
                ->requiredOnCreate()
                ->writableOnCreate()
                ->in(StoryReport::REASONS),
            Schema\Str::make('detail')
                ->nullable()
                ->writableOnCreate()
                ->maxLength(500),
            Schema\DateTime::make('createdAt')->property('created_at'),
            Schema\Relationship\ToOne::make('story')
                ->type('glimpse-stories')
                ->includable()
                ->requiredOnCreate()
                ->writableOnCreate(),
            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
        ];
    }

    /** @return list<SortColumn> */
    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt'),
        ];
    }

    /** A second report racing the first one hits the unique key: the same answer as the check above. */
    protected function saveModel(Model $model, BaseContext $context): void
    {
        try {
            parent::saveModel($model, $context);
        } catch (UniqueConstraintViolationException) {
            throw new ValidationException(['reason' => $this->translator->trans('ernestdefoe-glimpse.api.already_reported')]);
        }
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $actor = $context->getActor();
        $story = Story::query()->find($model->story_id);
        if ($story === null) {
            throw new ValidationException(['story' => $this->translator->trans('ernestdefoe-glimpse.api.story_gone')]);
        }
        $actor->assertCan('report', $story);

        if (StoryReport::query()->where('story_id', $story->id)->where('user_id', $actor->id)->exists()) {
            throw new ValidationException(['reason' => $this->translator->trans('ernestdefoe-glimpse.api.already_reported')]);
        }

        $model->user_id = $actor->id;
        $model->created_at = Carbon::now();

        return $model;
    }
}
