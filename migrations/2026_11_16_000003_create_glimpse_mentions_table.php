<?php

declare(strict_types=1);

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// The members a caption @mentions, so the viewer links only real people and they are told once.
return Migration::createTable('glimpse_mentions', function (Blueprint $table): void {
    $table->unsignedInteger('story_id');
    $table->unsignedInteger('user_id');

    $table->primary(['story_id', 'user_id']);
    $table->foreign('story_id')->references('id')->on('glimpse_stories')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
