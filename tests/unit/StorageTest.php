<?php

declare(strict_types=1);

namespace ErnestDefoe\Glimpse\Tests\unit;

use ErnestDefoe\Glimpse\Media\Storage;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StorageTest extends TestCase
{
    private function storage(bool $written): Storage
    {
        $disk = $this->createStub(Filesystem::class);
        $disk->method('put')->willReturn($written);
        $factory = $this->createStub(Factory::class);
        $factory->method('disk')->willReturn($disk);

        return new Storage($factory);
    }

    #[Test]
    public function a_written_file_gets_a_random_dated_path(): void
    {
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f]{32}\.webp$#', $this->storage(true)->put('bytes', 'webp'));
    }

    #[Test]
    public function a_write_the_disk_reports_as_failed_stops_the_upload(): void
    {
        $this->expectException(RuntimeException::class);
        $this->storage(false)->put('bytes', 'webp');
    }
}
