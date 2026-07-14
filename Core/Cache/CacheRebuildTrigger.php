<?php

declare(strict_types=1);

namespace Forge\Core\Cache;

use Forge\Core\Autoloader;
use Forge\Core\Bootstrap\OptimizedDirectoryScanner;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\Logger;
use Forge\Core\Module\ModuleCache;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CacheRebuildTrigger
{
    private const string SENTINEL_FILE = '/storage/framework/.cache_rebuild';
    private const string LOCK_FILE = '/storage/framework/.cache_rebuild.lock';
    private const string VIEW_CACHE_DIR = '/storage/framework/views';

    private const array CACHE_FILES = [
        '/storage/framework/cache/module_registrations.php',
        '/storage/framework/cache/module_command_map.php',
        '/storage/framework/cache/class_file_map.php',
        '/storage/framework/cache/directory_structure.php',
        '/storage/framework/cache/compiled_hooks.php',
        '/storage/framework/cache/module_assets.cache',
        '/storage/framework/cache/controller-map.php',
        '/storage/framework/cache/role_cache.php',
        '/storage/framework/cache/permissions_cache.php',
    ];

    /**
     * Check for the sentinel file and, if present, rebuild all caches.
     * Safe for concurrent worker processes — uses flock for mutual exclusion.
     *
     * Call this early in bootstrap, before any cache is loaded.
     */
    public static function process(): void
    {
        $sentinel = BASE_PATH . self::SENTINEL_FILE;

        if (!is_file($sentinel)) {
            return;
        }

        $lockPath = BASE_PATH . self::LOCK_FILE;
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            Logger::log('CacheRebuildTrigger: failed to open lock file');
            return;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            // Another worker is handling the rebuild — skip
            fclose($handle);
            return;
        }

        // Double-check sentinel still exists (workers may have raced past is_file)
        if (!is_file($sentinel)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return;
        }

        Logger::log('CacheRebuildTrigger: sentinel detected, rebuilding caches');

        self::deleteFrameworkCacheFiles();
        self::clearStaticCaches();

        // Remove sentinel so subsequent workers don't repeat the rebuild
        @unlink($sentinel);
        FileExistenceCache::clearPath($sentinel);

        flock($handle, LOCK_UN);
        fclose($handle);

        Logger::log('CacheRebuildTrigger: cache rebuild complete');
    }

    /**
     * Delete all known framework infrastructure cache files from disk.
     * Does NOT touch application data caches (CacheManager sqlite/file).
     */
    private static function deleteFrameworkCacheFiles(): void
    {
        foreach (self::CACHE_FILES as $relative) {
            $path = BASE_PATH . $relative;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        self::clearViewCacheDirectory();
    }

    /**
     * Recursively delete compiled view templates.
     */
    private static function clearViewCacheDirectory(): void
    {
        $dir = BASE_PATH . self::VIEW_CACHE_DIR;
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile() || $item->isLink()) {
                @unlink($item->getRealPath());
            } elseif ($item->isDir()) {
                @rmdir($item->getRealPath());
            }
        }
    }

    /**
     * Reset all in-memory static caches so the next request rebuilds from scratch.
     * Critical for worker-mode safety — prevents stale state across requests.
     */
    private static function clearStaticCaches(): void
    {
        FileExistenceCache::clear();

        Autoloader::clearClassFileMap();
        OptimizedDirectoryScanner::clearCache();

        /* Also reset module cache state.
         * The ModuleCache disk file will be recreated lazily by ModuleSetup
         * when isValid() returns false after deletion above.
         */
        ModuleCache::clear();
    }
}
