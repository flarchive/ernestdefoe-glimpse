<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse;

use Carbon\CarbonInterface;
use Flarum\Database\AbstractModel;

/**
 * An uploaded photo or video, already checked and cleaned by Media\Uploader. Served only through
 * Http\MediaController, which asks the story's visibility first.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind image or video
 * @property string $mime
 * @property string $path
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration_ms
 * @property int $size
 * @property CarbonInterface $created_at
 */
class StoryMedia extends AbstractModel
{
    protected $table = 'glimpse_media';

    public $timestamps = false;

    protected $casts = [
        'user_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_ms' => 'integer',
        'size' => 'integer',
        'created_at' => 'datetime',
    ];
}
