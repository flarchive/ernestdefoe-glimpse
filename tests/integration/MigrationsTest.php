<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\integration;

use Flarum\Database\Migrator;
use Flarum\Extension\ExtensionManager;
use PHPUnit\Framework\Attributes\Test;

/** Every migration can be undone and redone, leaving nothing behind. */
class MigrationsTest extends GlimpseTestCase
{
    protected bool $follows = false;

    #[Test]
    public function the_migrations_roll_back_cleanly_and_run_again(): void
    {
        $this->app();
        $container = $this->app()->getContainer();
        $extension = $container->make(ExtensionManager::class)->getExtension('ernestdefoe-glimpse');
        $this->assertNotNull($extension);
        $schema = $this->database()->getSchemaBuilder();
        $tables = ['glimpse_media', 'glimpse_stories', 'glimpse_views', 'glimpse_mentions', 'glimpse_highlights', 'glimpse_highlight_stories', 'glimpse_reports', 'glimpse_mutes'];

        $migrator = $container->make(Migrator::class);
        $extension->migrate($migrator, 'down');
        foreach ($tables as $table) {
            $this->assertFalse($schema->hasTable($table), "$table was left behind");
        }
        $this->assertSame(0, $this->database()->table('group_permission')->where('permission', 'like', 'glimpse.%')->count());

        $extension->migrate($migrator, 'up');
        foreach ($tables as $table) {
            $this->assertTrue($schema->hasTable($table), "$table is missing");
        }
        $this->assertSame(5, $this->database()->table('group_permission')->where('permission', 'like', 'glimpse.%')->count());
    }
}
