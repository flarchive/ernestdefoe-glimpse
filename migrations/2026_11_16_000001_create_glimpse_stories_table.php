<?php

declare(strict_types=1);

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// A story. `expires_at` is null while it waits for approval: its lifetime starts when it goes live.
return Migration::createTable('glimpse_stories', function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('kind', 8);
    $table->unsignedInteger('media_id')->nullable();
    $table->string('text', 255)->nullable();
    $table->unsignedTinyInteger('background')->nullable();
    $table->string('caption', 255)->nullable();
    $table->string('link_url', 2000)->nullable();
    $table->string('audience', 16);
    $table->string('status', 16);
    $table->unsignedInteger('view_count')->default(0);
    $table->unsignedInteger('reaction_count')->default(0);
    $table->dateTime('created_at');
    $table->dateTime('approved_at')->nullable();
    $table->dateTime('expires_at')->nullable();
    $table->dateTime('hidden_at')->nullable();
    $table->unsignedInteger('hidden_user_id')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('media_id')->references('id')->on('glimpse_media')->nullOnDelete();
    $table->foreign('hidden_user_id')->references('id')->on('users')->nullOnDelete();
    $table->index(['status', 'expires_at']);
    $table->index(['user_id', 'expires_at']);
    $table->index('expires_at');
});
