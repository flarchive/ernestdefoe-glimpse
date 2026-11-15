<?php

declare(strict_types=1);

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// "Mute this person's stories": their ring leaves your bar and the Stories page. Unmuted from their profile.
return Migration::createTable('glimpse_mutes', function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('muted_user_id');
    $table->dateTime('created_at');

    $table->unique(['user_id', 'muted_user_id']);
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('muted_user_id')->references('id')->on('users')->cascadeOnDelete();
});
