<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Api;

use Carbon\Carbon;
use ErnestDefoe\Glimpse\Config;
use ErnestDefoe\Glimpse\Flags;
use ErnestDefoe\Glimpse\Highlight;
use ErnestDefoe\Glimpse\Ids;
use ErnestDefoe\Glimpse\Story;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\Pagination;

/**
 * The `glimpse-highlights` type: a member's named, lasting collections of their own stories.
 *
 * GET    /api/glimpse-highlights?filter[user]=<id>   a profile's row, in order, with the stories each
 *                                                  holds that the viewer may see
 * POST   /api/glimpse-highlights                     {title, relationships.stories}
 * PATCH  /api/glimpse-highlights/{id}                rename, reorder, or replace its stories
 * DELETE /api/glimpse-highlights/{id}                the stories stay (and expire as usual)
 *
 * @extends AbstractDatabaseResource<Highlight>
 */
class HighlightResource extends AbstractDatabaseResource
{
    public function __construct(
        private readonly Config $config,
        private readonly TranslatorInterface $translator,
        private readonly ConnectionInterface $db,
        private readonly Flags $flags,
        private readonly Repository $cache,
    ) {
    }

    public function type(): string
    {
        return 'glimpse-highlights';
    }

    public function model(): string
    {
        return Highlight::class;
    }

    /** @param Builder<Highlight> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        if (! $context->getActor()->hasPermission('glimpse.view')) {
            $query->whereRaw('1 = 0');
        }
    }

    public function endpoints(): array
    {
        $owner = fn (Highlight $highlight, Context $context): bool => $highlight->user_id === $context->getActor()->id;

        return [
            Endpoint\Index::make()
                ->defaultInclude(['stories'])
                ->query(fn (Builder $query, ?Pagination $pagination, Context $context, array $filters): Context => $context->withQuery(
                    $query->where('glimpse_highlights.user_id', Ids::one($filters['user'] ?? null))
                        ->orderBy('glimpse_highlights.position')
                        ->orderBy('glimpse_highlights.id')
                        ->limit($this->config->highlightsMax())
                )),
            Endpoint\Show::make()->defaultInclude(['stories']),
            Endpoint\Create::make()
                ->authenticated()
                ->can('glimpse.create')
                ->defaultInclude(['stories']),
            Endpoint\Update::make()
                ->authenticated()
                ->visible($owner)
                ->defaultInclude(['stories']),
            Endpoint\Delete::make()
                ->authenticated()
                ->visible($owner),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title')
                ->requiredOnCreate()
                ->writable()
                ->minLength(1)
                ->maxLength(40),
            Schema\Integer::make('position')
                ->writableOnUpdate()
                // Replacing is intended: no earlier rule (not nullable), so these three are the whole set.
                ->rules(['integer', 'min:0', 'max:1000'], true),
            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
            Schema\Relationship\ToMany::make('stories')
                ->type('glimpse-stories')
                ->includable()
                ->writable()
                // The viewer's visibility rule and flags (seen, reaction), and the media: one query for every story.
                ->scope(fn (Builder|Relation $query, Context $context) => $this->scopeStories($query, $context))
                // Checked before the highlight is written; synced (instead of Flarum's plain sync) after.
                ->set(fn (Highlight $highlight, array $stories, Context $context) => $this->checkStories($highlight, $stories, $context))
                ->save(fn (Highlight $highlight, array $stories, Context $context) => $this->saveStories($highlight, $stories, $context)),
        ];
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $model->user_id = $context->getActor()->id;
        $model->created_at = Carbon::now();

        return $model;
    }

    /**
     * A new highlight is counted against the limit and given the next position under a per-member lock,
     * so two created at once can't both slip under the limit or share a position.
     */
    protected function saveModel(Model $model, BaseContext $context): void
    {
        if ($model->exists || ! $model instanceof Highlight) {
            parent::saveModel($model, $context);

            return;
        }

        $save = function () use ($model, $context): void {
            $max = $this->config->highlightsMax();
            if (Highlight::query()->where('user_id', $model->user_id)->count() >= $max) {
                throw new ValidationException(['title' => $this->translator->trans('ernestdefoe-glimpse.api.highlights_full', ['{count}' => $max])]);
            }
            $model->position = Ids::one(Highlight::query()->where('user_id', $model->user_id)->max('position')) + 1;
            parent::saveModel($model, $context);
        };

        // Every cache store Flarum ships (file, array, redis) can lock; one that can't still checks the limit.
        $store = $this->cache->getStore();
        $store instanceof LockProvider
            ? $store->lock('ernestdefoe-glimpse.highlight.'.$model->user_id, 10)->block(5, $save)
            : $save();
    }

    /** @param Builder<Story>|Relation<Story, Highlight, mixed> $query */
    private function scopeStories(Builder|Relation $query, Context $context): void
    {
        $stories = $query instanceof Relation ? $query->getQuery() : $query;
        $actor = $context->getActor();
        $stories->whereVisibleTo($actor)->select('glimpse_stories.*')->with('media');
        $this->flags->apply($stories, $actor);
    }

    /**
     * Before anything is written: only the owner edits a highlight, and it holds at most the admin's limit.
     *
     * @param array<mixed> $stories
     */
    private function checkStories(Highlight $highlight, array $stories, Context $context): void
    {
        if ($highlight->exists && $highlight->user_id !== $context->getActor()->id) {
            throw new PermissionDeniedException();
        }

        if (count($stories) > $this->config->highlightStoriesMax()) {
            throw new ValidationException(['stories' => $this->translator->trans('ernestdefoe-glimpse.api.highlight_full', ['{count}' => $this->config->highlightStoriesMax()])]);
        }
    }

    /**
     * Keeps the member's own stories only, in the order given.
     *
     * @param array<mixed> $stories
     */
    private function saveStories(Highlight $highlight, array $stories, Context $context): void
    {
        $ids = Ids::of(array_map(fn (mixed $story): int => $story instanceof Story ? $story->id : 0, $stories));
        $own = Ids::of(Story::query()->whereIn('id', $ids)->where('user_id', $context->getActor()->id)->pluck('id'));
        $ids = array_values(array_intersect($ids, $own));

        $this->db->transaction(fn () => $highlight->stories()->sync(array_combine($ids, array_map(
            fn (int $position): array => ['position' => $position],
            array_keys($ids)
        ))));
    }
}
