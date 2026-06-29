<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use Modules\ForgeTesting\Attributes\AfterEach;
use Modules\ForgeTesting\Attributes\BeforeEach;
use Modules\ForgeTesting\Attributes\Group;
use Modules\ForgeTesting\Attributes\Test;
use Modules\ForgeTesting\TestCase;
use Forge\Core\Helpers\FileExistenceCache;

#[Group('helpers')]
final class FileExistenceCacheTest extends TestCase
{
    private string $tmpDir = '';
    private string $tmpFile = '';

    #[BeforeEach]
    public function setup(): void
    {
        FileExistenceCache::clear();
        $this->tmpDir = sys_get_temp_dir() . '/fec_test_' . uniqid();
        mkdir($this->tmpDir);
        $this->tmpFile = $this->tmpDir . '/test.txt';
        file_put_contents($this->tmpFile, 'hello');
    }

    #[AfterEach]
    public function cleanup(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
        FileExistenceCache::clear();
    }

    #[Test('exists returns true for real file')]
    public function exists_real_file(): void
    {
        $this->assertTrue(FileExistenceCache::exists($this->tmpFile));
    }

    #[Test('exists returns false for missing file')]
    public function exists_missing_file(): void
    {
        $this->assertFalse(FileExistenceCache::exists($this->tmpDir . '/nonexistent.txt'));
    }

    #[Test('isFile returns true for file, false for directory')]
    public function is_file_vs_is_dir(): void
    {
        $this->assertTrue(FileExistenceCache::isFile($this->tmpFile));
        $this->assertFalse(FileExistenceCache::isDir($this->tmpFile));
        $this->assertTrue(FileExistenceCache::isDir($this->tmpDir));
        $this->assertFalse(FileExistenceCache::isFile($this->tmpDir));
    }

    #[Test('getMtime returns timestamp for existing file')]
    public function get_mtime_existing_file(): void
    {
        $mtime = FileExistenceCache::getMtime($this->tmpFile);
        $this->assertNotNull($mtime);
        $this->assertTrue($mtime > 0);
    }

    #[Test('getMtime returns null for missing file')]
    public function get_mtime_missing_file(): void
    {
        $mtime = FileExistenceCache::getMtime($this->tmpDir . '/ghost.txt');
        $this->assertNull($mtime);
    }

    #[Test('clear empties all internal caches')]
    public function clear_resets_cache(): void
    {
        FileExistenceCache::exists($this->tmpFile);
        $statsBefore = FileExistenceCache::getCacheStats();
        $this->assertTrue($statsBefore['file_checks'] > 0);

        FileExistenceCache::clear();
        $statsAfter = FileExistenceCache::getCacheStats();
        $this->assertEquals(0, $statsAfter['file_checks']);
        $this->assertEquals(0, $statsAfter['stat_checks']);
    }

    #[Test('clearPath removes only that paths entries')]
    public function clear_path_is_targeted(): void
    {
        $other = $this->tmpDir . '/other.txt';
        file_put_contents($other, 'x');
        FileExistenceCache::exists($this->tmpFile);
        FileExistenceCache::exists($other);

        $statsBefore = FileExistenceCache::getCacheStats();
        FileExistenceCache::clearPath($this->tmpFile);
        $statsAfter = FileExistenceCache::getCacheStats();

        $this->assertTrue($statsAfter['file_checks'] < $statsBefore['file_checks']);
        $this->assertTrue(FileExistenceCache::exists($other));

        unlink($other);
    }

    #[Test('preload batch-loads multiple paths')]
    public function preload_populates_cache(): void
    {
        FileExistenceCache::preload([$this->tmpFile, $this->tmpDir]);
        $stats = FileExistenceCache::getCacheStats();
        $this->assertTrue($stats['file_checks'] >= 2);
    }
}
