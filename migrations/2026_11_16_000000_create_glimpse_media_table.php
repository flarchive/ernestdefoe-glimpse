<?php

declare(strict_types=1);

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// An uploaded photo or video, after the server sniffed, re-encoded or scrubbed it. A story points at
// one; uploads no story ever used are pruned after a day.
return Migration::createTable('glimpse_media', function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('kind', 8);
    $table->string('mime', 32);
    $table->string('path', 100);
    $table->unsignedSmallInteger('width')->nullable();
    $table->unsignedSmallInteger('height')->nullable();
    $table->unsignedInteger('duration_ms')->nullable();
    $table->unsignedInteger('size');
    $table->dateTime('created_at');

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->index('created_at');
});
