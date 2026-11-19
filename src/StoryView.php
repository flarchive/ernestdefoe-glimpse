<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use Carbon\CarbonInterface;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person having seen one story, and their reaction. The table's key is (story_id, user_id); rows
 * are written in bulk by Engagement, never one model at a time, so the model is read-only.
 *
 * @property int $story_id
 * @property int $user_id
 * @property CarbonInterface $viewed_at
 * @property string|null $reaction
 * @property CarbonInterface|null $reacted_at
 * @property-read User|null $user
 */
class StoryView extends AbstractModel
{
    protected $table = 'glimpse_views';

    protected $primaryKey = 'story_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'viewed_at' => 'datetime',
        'reacted_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
