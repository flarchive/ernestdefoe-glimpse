<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use Carbon\CarbonInterface;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A member's named collection of their own stories. Stories in a highlight outlive their 24 hours
 * (the prune job leaves them) but keep the audience they were posted with.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property int $position
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Story> $stories
 */
class Highlight extends AbstractModel
{
    protected $table = 'glimpse_highlights';

    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'position' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Story, $this> */
    public function stories(): BelongsToMany
    {
        return $this->belongsToMany(Story::class, 'glimpse_highlight_stories', 'highlight_id', 'story_id')
            ->withPivot('position')
            ->orderBy('glimpse_highlight_stories.position');
    }
}
