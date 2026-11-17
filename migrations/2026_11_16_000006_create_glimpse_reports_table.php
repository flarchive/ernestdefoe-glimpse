<?php

declare(strict_types=1);

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// A member's report of a story, for the moderators' queue. One per person per story.
return Migration::createTable('glimpse_reports', function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedInteger('story_id');
    $table->unsignedInteger('user_id');
    $table->string('reason', 32);
    $table->string('detail', 500)->nullable();
    $table->dateTime('created_at');

    $table->unique(['story_id', 'user_id']);
    $table->foreign('story_id')->references('id')->on('glimpse_stories')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
