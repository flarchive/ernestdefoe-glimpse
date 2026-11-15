<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Api;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\StoryMute;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `glimpse-mutes` type: the people whose stories a member chose not to see in their bar.
 *
 * GET    /api/glimpse-mutes         the actor's own
 * POST   /api/glimpse-mutes         {relationships.mutedUser}
 * DELETE /api/glimpse-mutes/{id}
 *
 * @extends AbstractDatabaseResource<StoryMute>
 */
class StoryMuteResource extends AbstractDatabaseResource
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function type(): string
    {
        return 'glimpse-mutes';
    }

    public function model(): string
    {
        return StoryMute::class;
    }

    /** @param Builder<StoryMute> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = $context->getActor();
        $query->where('glimpse_mutes.user_id', $actor->isGuest() ? 0 : $actor->id);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()->authenticated()->defaultInclude(['mutedUser'])->paginate(50, 100),
            Endpoint\Create::make()->authenticated()->defaultInclude(['mutedUser']),
            Endpoint\Delete::make()->authenticated(),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\DateTime::make('createdAt')->property('created_at'),
            Schema\Relationship\ToOne::make('mutedUser')
                ->type('users')
                ->includable()
                ->requiredOnCreate()
                ->writableOnCreate(),
        ];
    }

    /** Muting twice at once hits the unique key: the same answer as the check below. */
    protected function saveModel(Model $model, BaseContext $context): void
    {
        try {
            parent::saveModel($model, $context);
        } catch (UniqueConstraintViolationException) {
            throw new ValidationException(['mutedUser' => $this->translator->trans('ernestdefoe-glimpse.api.cannot_mute')]);
        }
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $actor = $context->getActor();
        if ($model->muted_user_id === $actor->id
            || StoryMute::query()->where('user_id', $actor->id)->where('muted_user_id', $model->muted_user_id)->exists()) {
            throw new ValidationException(['mutedUser' => $this->translator->trans('ernestdefoe-glimpse.api.cannot_mute')]);
        }

        $model->user_id = $actor->id;
        $model->created_at = Carbon::now();

        return $model;
    }
}
