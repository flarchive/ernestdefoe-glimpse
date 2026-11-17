<?php

declare(strict_types=1);

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Which stories a highlight holds, in order.
return Migration::createTable('glimpse_highlight_stories', function (Blueprint $table): void {
    $table->unsignedInteger('highlight_id');
    $table->unsignedInteger('story_id');
    $table->unsignedSmallInteger('position')->default(0);

    $table->primary(['highlight_id', 'story_id']);
    $table->foreign('highlight_id')->references('id')->on('glimpse_highlights')->cascadeOnDelete();
    $table->foreign('story_id')->references('id')->on('glimpse_stories')->cascadeOnDelete();
    $table->index('story_id');
});
