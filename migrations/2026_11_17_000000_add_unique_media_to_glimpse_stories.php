<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// One upload belongs to one story: two stories racing for the same upload can't both hold it (deleting
// one would take the other's file). Laravel names the index (no hand-written name: prefixes stay right).
return [
    'up' => function (Builder $schema): void {
        $schema->table('glimpse_stories', function (Blueprint $table): void {
            $table->unique('media_id');
        });
    },
    'down' => function (Builder $schema): void {
        // MySQL lets the unique index serve the media_id foreign key, so the key needs a plain index back first.
        $schema->table('glimpse_stories', function (Blueprint $table): void {
            $table->index('media_id');
            $table->dropUnique(['media_id']);
        });
    },
];
