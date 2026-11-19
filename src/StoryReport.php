<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use Carbon\CarbonInterface;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $story_id
 * @property int $user_id
 * @property string $reason
 * @property string|null $detail
 * @property CarbonInterface $created_at
 * @property-read Story|null $story
 * @property-read User|null $user
 */
class StoryReport extends AbstractModel
{
    public const REASONS = ['spam', 'inappropriate', 'harassment', 'other'];

    protected $table = 'glimpse_reports';

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class, 'story_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
