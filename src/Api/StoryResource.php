<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Api;

use ErnestDefoe\Glimpse\Engagement;
use ErnestDefoe\Glimpse\Enum\Audience;
use ErnestDefoe\Glimpse\Enum\Kind;
use ErnestDefoe\Glimpse\Enum\Status;
use ErnestDefoe\Glimpse\Flags;
use ErnestDefoe\Glimpse\Story;
use ErnestDefoe\Glimpse\StoryWriter;
use ErnestDefoe\Glimpse\UnseenAuthors;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Http\UrlGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Laminas\Diactoros\Response\EmptyResponse;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `glimpse-stories` JSON:API type. Listing goes through Search\StorySearcher (filters: bar, live, author,
 * status, reported); every other read goes through scope(), which is the same visibility rule plus the
 * same per-viewer flags.
 *
 * GET    /api/glimpse-stories               POST /api/glimpse-stories            (create; media via relationships.media)
 * GET    /api/glimpse-stories/{id}          PATCH /api/glimpse-stories/{id}      (moderators: status)
 * DELETE /api/glimpse-stories/{id}          (author or moderator)
 * POST   /api/glimpse-stories/seen          {"ids": [..]}  marks stories seen, 204
 * POST   /api/glimpse-stories/{id}/reaction {"reaction": "❤️" | null}
 *
 * @extends AbstractDatabaseResource<Story>
 */
class StoryResource extends AbstractDatabaseResource
{
    /** Built on every API request: these construct without touching the database. */
    public function __construct(
        private readonly Flags $flags,
        private readonly UrlGenerator $url,
        private readonly StoryWriter $writer,
        private readonly Engagement $engagement,
        private readonly UnseenAuthors $unseen,
    ) {
    }

    public function type(): string
    {
        return 'glimpse-stories';
    }

    public function model(): string
    {
        return Story::class;
    }

    /** @param Builder<Story> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = $context->getActor();
        // Media rides on the story query itself (here and in StorySearcher), never on an endpoint's eagerLoad:
        // that would run through StoryMediaResource's owner-only scope and blank everyone else's media.
        $query->whereVisibleTo($actor)->select('glimpse_stories.*')->with('media');
        $this->flags->apply($query, $actor);
    }

    public function endpoints(): array
    {
        return [
            // No N+1: media and author groups are eager-loaded by StorySearcher's query (one IN query each),
            // proved by QueryCountTest (1 vs 24 photo/video stories). Media is deliberately NOT in eagerLoad():
            // an endpoint eagerLoad runs through StoryMediaResource's owner-only scope and would null every
            // other member's media (that was the 1.0.0 Show bug).
            Endpoint\Index::make()
                ->defaultInclude(['user'])
                ->defaultSort('-createdAt')
                ->paginate(24, 100),
            Endpoint\Show::make()
                ->defaultInclude(['user', 'mentionedUsers'])
                ->eagerLoad(['user.groups', 'mentionedUsers']),
            Endpoint\Create::make()
                ->authenticated()
                ->can('glimpse.create')
                ->defaultInclude(['user', 'mentionedUsers']),
            Endpoint\Update::make()
                ->authenticated()
                ->can('moderate')
                ->defaultInclude(['user']),
            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),
            Endpoint\Endpoint::make('seen')
                ->route('POST', '/seen')
                ->authenticated()
                ->action(function (Context $context): void {
                    $ids = $context->body()['ids'] ?? [];
                    $this->engagement->markSeen(
                        $context->getActor(),
                        is_array($ids) ? array_values(array_map(intval(...), array_filter($ids, is_numeric(...)))) : []
                    );
                })
                ->response(fn () => new EmptyResponse(204)),
            Endpoint\Endpoint::make('reaction')
                ->route('POST', '/{id}/reaction')
                ->authenticated()
                ->can('react')
                ->action(function (Context $context): ?Story {
                    /** @var Story $story */
                    $story = $context->model;
                    $reaction = $context->body()['reaction'] ?? null;
                    $this->engagement->react($context->getActor(), $story, is_string($reaction) ? $reaction : null);

                    return $this->fresh($story, $context);
                }),
        ];
    }

    public function fields(): array
    {
        return [...$this->inputs(), ...$this->media(), ...$this->forViewer(), ...$this->abilities(), ...$this->relationships()];
    }

    /**
     * What a member sends (on create), and what a moderator changes (status).
     *
     * @return list<Schema\Attribute|Schema\Relationship\ToOne|Schema\Relationship\ToMany>
     */
    private function inputs(): array
    {
        return [
            Schema\Str::make('kind')
                ->requiredOnCreate()
                ->writableOnCreate()
                ->in(array_column(Kind::cases(), 'value'))
                ->get(fn (Story $story): string => $story->kind->value),
            Schema\Str::make('text')
                ->nullable()
                ->writableOnCreate()
                ->maxLength(200),
            Schema\Integer::make('background')
                ->nullable()
                ->writableOnCreate()
                ->rules(['integer', 'min:0', 'max:7'], true, false),
            Schema\Str::make('caption')
                ->nullable()
                ->writableOnCreate()
                ->maxLength(200)
                ->set(fn (Story $story, ?string $value) => $story->caption = $this->clean($value)),
            // rules(…, override: false): the default override would drop nullable() and maxLength() above it,
            // and an empty link (null or "") must mean no link.
            Schema\Str::make('linkUrl')
                ->nullable()
                ->writableOnCreate()
                ->maxLength(2000)
                ->rules(['url:http,https'], true, false)
                ->set(fn (Story $story, ?string $value) => $story->link_url = $this->clean($value)),
            Schema\Str::make('audience')
                ->writableOnCreate()
                ->in(array_column(Audience::cases(), 'value'))
                ->get(fn (Story $story): string => $story->audience->value),
            Schema\Str::make('status')
                ->writableOnUpdate()
                ->in([Status::Live->value, Status::Hidden->value])
                ->get(fn (Story $story): string => $story->status->value)
                ->set(fn (Story $story, string $value, Context $context) => $this->writer->moderate($story, Status::from($value), $context->getActor())),
            Schema\DateTime::make('createdAt')->property('created_at'),
            Schema\DateTime::make('expiresAt')->property('expires_at')->nullable(),
        ];
    }

    /**
     * The photo or video, served by Http\MediaController.
     *
     * @return list<Schema\Attribute|Schema\Relationship\ToOne|Schema\Relationship\ToMany>
     */
    private function media(): array
    {
        return [
            Schema\Str::make('mediaUrl')
                ->nullable()
                ->get(fn (Story $story): ?string => $story->media_id === null ? null : $this->url->to('forum')->route('glimpse.media', ['id' => $story->media_id])),
            Schema\Str::make('mediaType')->nullable()->get(fn (Story $story): ?string => $story->media?->mime),
            Schema\Integer::make('width')->nullable()->get(fn (Story $story): ?int => $story->media?->width),
            Schema\Integer::make('height')->nullable()->get(fn (Story $story): ?int => $story->media?->height),
            Schema\Integer::make('durationMs')->nullable()->get(fn (Story $story): ?int => $story->media?->duration_ms),
        ];
    }

    /**
     * What the story means to the person looking.
     *
     * @return list<Schema\Attribute|Schema\Relationship\ToOne|Schema\Relationship\ToMany>
     */
    private function forViewer(): array
    {
        return [
            // What it means to the viewer: from subselects on the story query (Flags), no queries here.
            Schema\Boolean::make('isSeen')->get(fn (Story $story): bool => Flags::seen($story)),
            Schema\Str::make('myReaction')->nullable()->get(fn (Story $story): ?string => Flags::reaction($story)),
            Schema\Boolean::make('authorFollowed')->get(fn (Story $story): bool => Flags::followed($story)),
            // The ring bar only: whether the author has anything the viewer hasn't seen (one query per page).
            Schema\Boolean::make('authorHasUnseen')
                ->visible(fn (Story $story, Context $context): bool => is_array($filter = $context->queryParam('filter')) && isset($filter['bar']))
                ->get(fn (Story $story, Context $context): \Closure => $this->unseen->defer($context->request, $context->getActor(), $story->user_id)),
        ];
    }

    /**
     * What the viewer may do, and the counts only its author and moderators see.
     *
     * @return list<Schema\Attribute|Schema\Relationship\ToOne|Schema\Relationship\ToMany>
     */
    private function abilities(): array
    {
        $insider = fn (Story $story, Context $context): bool => $context->getActor()->can('seeViewers', $story);

        return [
            // Its author and moderators only.
            Schema\Integer::make('viewCount')->property('view_count')->visible($insider),
            Schema\Integer::make('reactionCount')->property('reaction_count')->visible($insider),

            Schema\Boolean::make('canDelete')->get(fn (Story $story, Context $context): bool => $context->getActor()->can('delete', $story)),
            Schema\Boolean::make('canModerate')->get(fn (Story $story, Context $context): bool => $context->getActor()->can('moderate', $story)),
            Schema\Boolean::make('canReact')->get(fn (Story $story, Context $context): bool => $context->getActor()->can('react', $story)),
            Schema\Boolean::make('canReport')->get(fn (Story $story, Context $context): bool => $context->getActor()->can('report', $story)),
            Schema\Boolean::make('canSeeViewers')->get($insider),
        ];
    }

    /**
     * The author, the upload (write-only), and the members the caption mentions.
     *
     * @return list<Schema\Attribute|Schema\Relationship\ToOne|Schema\Relationship\ToMany>
     */
    private function relationships(): array
    {
        return [
            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
            Schema\Relationship\ToOne::make('media')
                ->type('glimpse-media')
                ->writableOnCreate()
                ->visible(false),
            Schema\Relationship\ToMany::make('mentionedUsers')
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

    /** Two stories racing for one upload: the unique key on media_id lets one through. */
    protected function saveModel(Model $model, BaseContext $context): void
    {
        try {
            parent::saveModel($model, $context);
        } catch (UniqueConstraintViolationException) {
            $this->writer->mediaTaken();
        }
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $this->writer->prepare($model, $context->getActor());

        return $model;
    }

    /** @param Context $context */
    public function created(object $model, BaseContext $context): ?object
    {
        $this->writer->created($model, $context->getActor());

        return $this->fresh($model, $context);
    }

    /**
     * @param object $model
     * @param BaseContext $context
     */
    public function delete(object $model, BaseContext $context): void
    {
        if (! $model instanceof Story || ! $context instanceof Context) {
            throw new \LogicException('StoryResource deletes stories through a Flarum API context.');
        }

        $this->writer->delete($model, $context->getActor());
    }

    /** The story read again through scope(), so it carries the viewer's flags. */
    private function fresh(Story $story, Context $context): ?Story
    {
        $found = $this->find((string) $story->id, $context);

        return $found instanceof Story ? $found : null;
    }

    /** Trims, and treats an empty string as nothing. */
    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim(str_replace(["\r\n", "\r"], "\n", $value));

        return $value === '' ? null : $value;
    }
}
