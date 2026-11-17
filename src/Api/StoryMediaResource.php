<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Api;

use ErnestDefoe\Glimpse\Media\Uploader;
use ErnestDefoe\Glimpse\StoryMedia;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Serializer;
use Flarum\Foundation\ValidationException;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Tobyz\JsonApiServer\Context as BaseContext;

use function Tobyz\JsonApiServer\json_api_response;

/**
 * The `glimpse-media` type: an upload, before it is attached to a story.
 *
 * POST /api/glimpse-media   multipart `file`. Returns the cleaned upload (its URL serves the preview).
 *
 * A member only ever reaches their own uploads, which is also what keeps a story from attaching
 * someone else's media through `relationships.media`.
 *
 * @extends AbstractDatabaseResource<StoryMedia>
 */
class StoryMediaResource extends AbstractDatabaseResource
{
    public function __construct(
        private readonly Uploader $uploader,
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function type(): string
    {
        return 'glimpse-media';
    }

    public function model(): string
    {
        return StoryMedia::class;
    }

    /** @param Builder<StoryMedia> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = $context->getActor();
        $query->where('glimpse_media.user_id', $actor->isGuest() ? 0 : $actor->id);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Endpoint::make('upload')
                ->route('POST', '/')
                ->authenticated()
                ->can('glimpse.create')
                ->action(function (Context $context): StoryMedia {
                    $file = $context->request->getUploadedFiles()['file'] ?? null;
                    if (! $file instanceof UploadedFileInterface) {
                        throw new ValidationException(['file' => $this->translator->trans('ernestdefoe-glimpse.api.upload_failed')]);
                    }

                    return $this->uploader->upload($context->getActor(), $file);
                })
                // A collection-level endpoint has no model in its context, so it says how to answer.
                ->response(fn (Context $context, StoryMedia $media): ResponseInterface => json_api_response($this->document($context, $media), 201)),
        ];
    }

    /** @return array{data: mixed} */
    private function document(Context $context, StoryMedia $media): array
    {
        $serializer = new Serializer($context);
        $serializer->addPrimary($this, $media, []);
        [$primary] = $serializer->serialize();

        return ['data' => $primary[0]];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('kind'),
            Schema\Str::make('mime'),
            Schema\Integer::make('width')->nullable(),
            Schema\Integer::make('height')->nullable(),
            Schema\Integer::make('durationMs')->property('duration_ms')->nullable(),
            Schema\Str::make('url')->get(fn (StoryMedia $media): string => $this->url->to('forum')->route('glimpse.media', ['id' => $media->id])),
        ];
    }
}
