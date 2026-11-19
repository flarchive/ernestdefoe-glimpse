<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// A member's uploads by time: the upload cap and the flood guard look them up on every upload. MySQL
// had an index through the user_id foreign key; PostgreSQL and SQLite had none. Laravel names it.
return [
    'up' => function (Builder $schema): void {
        $schema->table('glimpse_media', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at']);
        });
    },
    'down' => function (Builder $schema): void {
        // MySQL may let the new index serve the user_id foreign key, so the key needs a plain index back first.
        $schema->table('glimpse_media', function (Blueprint $table): void {
            $table->index('user_id');
            $table->dropIndex(['user_id', 'created_at']);
        });
    },
];
