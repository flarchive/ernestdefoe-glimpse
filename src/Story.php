<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use Carbon\CarbonInterface;
use ErnestDefoe\Glimpse\Enum\Audience;
use ErnestDefoe\Glimpse\Enum\Kind;
use ErnestDefoe\Glimpse\Enum\Status;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A story. Who may see it is decided in one place, Access\ScopeStoryVisibility, registered as the
 * model's visibility scope, so every read (the API, media files, notifications) asks the same question.
 *
 * @property int $id
 * @property int $user_id
 * @property Kind $kind
 * @property int|null $media_id
 * @property string|null $text
 * @property int|null $background
 * @property string|null $caption
 * @property string|null $link_url
 * @property Audience $audience
 * @property Status $status
 * @property int $view_count
 * @property int $reaction_count
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $approved_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $hidden_at
 * @property int|null $hidden_user_id
 * @property-read User|null $user
 * @property-read StoryMedia|null $media
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $mentionedUsers
 */
class Story extends AbstractModel
{
    use ScopeVisibilityTrait;

    protected $table = 'glimpse_stories';

    public $timestamps = false;

    protected $casts = [
        // Ids as ints whatever the driver returns (emulated prepares hand back strings): the policies compare with ===.
        'user_id' => 'integer',
        'media_id' => 'integer',
        'kind' => Kind::class,
        'audience' => Audience::class,
        'status' => Status::class,
        'background' => 'integer',
        'view_count' => 'integer',
        'reaction_count' => 'integer',
        'created_at' => 'datetime',
        'approved_at' => 'datetime',
        'expires_at' => 'datetime',
        'hidden_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<StoryMedia, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(StoryMedia::class, 'media_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function mentionedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'glimpse_mentions', 'story_id', 'user_id');
    }

    /** @return HasMany<StoryView, $this> */
    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class, 'story_id');
    }

    public function isLive(): bool
    {
        return $this->status === Status::Live && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
