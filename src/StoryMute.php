<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use Carbon\CarbonInterface;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $muted_user_id
 * @property CarbonInterface $created_at
 * @property-read User|null $user
 * @property-read User|null $mutedUser
 */
class StoryMute extends AbstractModel
{
    protected $table = 'glimpse_mutes';

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function mutedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'muted_user_id');
    }
}
