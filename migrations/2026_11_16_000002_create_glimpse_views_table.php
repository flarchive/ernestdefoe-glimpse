<?php

declare(strict_types=1);

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Who saw a story, and their reaction to it. One row per person per story: marking it seen twice is a no-op.
return Migration::createTable('glimpse_views', function (Blueprint $table): void {
    $table->unsignedInteger('story_id');
    $table->unsignedInteger('user_id');
    $table->dateTime('viewed_at');
    $table->string('reaction', 16)->nullable();
    $table->dateTime('reacted_at')->nullable();

    $table->primary(['story_id', 'user_id']);
    $table->foreign('story_id')->references('id')->on('glimpse_stories')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->index(['user_id', 'viewed_at']);
});
