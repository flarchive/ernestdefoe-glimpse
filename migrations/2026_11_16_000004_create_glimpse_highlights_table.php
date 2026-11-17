<?php

declare(strict_types=1);

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Highlights: a member's named collections of their own stories, kept after the stories expire.
return Migration::createTable('glimpse_highlights', function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('title', 40);
    $table->unsignedSmallInteger('position')->default(0);
    $table->dateTime('created_at');
    $table->dateTime('updated_at')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->index(['user_id', 'position']);
});
