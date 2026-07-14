<?php

declare(strict_types=1);

namespace Forge\CLI\Commands;

use FilesystemIterator;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\OutputHelper;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Module\ModuleCache;
use Forge\Core\Autoloader;

#[Cli(
    command: 'cache:flush',
    description: 'Flush all application caches and reset module registry',
    usage: 'cache:flush',
    examples: [
        'cache:flush'
    ]
)]
final class FlushCacheCommand extends Command
{
    use OutputHelper;

    private const string VIEW_CACHE_DIR = BASE_PATH . "/storage/framework/views";
    private const string APP_CACHE_DIR = BASE_PATH . "/storage/framework/cache";

    public function execute(array $args): int
    {
        // Disable autoloader cache saving during flush to prevent immediate rebuild
        Autoloader::disableCacheSaving();

        $this->clearAttributeDiscoveryCache();
        $this->clearViewCache();
        $this->clearGeneralCache();
        $this->clearRolePermissionCache();
        $this->clearModuleCache();

        // Re-enable cache saving after flush completes
        Autoloader::enableCacheSaving();

        $this->info("Application caches flushed successfully.");
        return 0;
    }

    private function clearAttributeDiscoveryCache(): void
    {
        $cacheFile = BASE_PATH . '/storage/framework/cache/attribute-discovery-cache.php';

        if (FileExistenceCache::exists($cacheFile)) {
            unlink($cacheFile)
                ? $this->success("Attribute discovery cache cleared successfully.")
                : $this->error("Failed to clear attribute discovery cache.");
        } else {
            $this->warning("Attribute discovery cache file does not exist.");
        }
    }

    private function clearViewCache(): void
    {
        $this->clearFilesInDirRecursive(self::VIEW_CACHE_DIR, "View cache");
    }

    private function clearFilesInDirRecursive(string $directory, string $name, array $excludeFiles = []): void
    {
        if (!is_dir($directory)) {
            $this->warning("$name directory does not exist.");
            return;
        }

        $successCount = 0;
        $failure = false;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $filePath = $item->getRealPath();
                if (in_array($filePath, $excludeFiles))
                    continue;

                if (!unlink($filePath)) {
                    $this->error("Failed to clear $name file: " . $item->getFilename());
                    $failure = true;
                } else {
                    $successCount++;
                }
            }
        }

        if ($failure) {
            $this->error("Partially failed to clear $name.");
        } elseif ($successCount > 0) {
            $this->success("$name cleared successfully ($successCount files).");
        } else {
            $this->warning("$name directory is empty or only contains excluded files.");
        }
    }

    private function clearGeneralCache(): void
    {
        $this->clearFilesInDirRecursive(
            self::APP_CACHE_DIR,
            "General application cache"
        );
    }

    private function clearRolePermissionCache(): void
    {
        $roleCacheFile = BASE_PATH . '/storage/framework/cache/role_cache.php';
        $permissionCacheFile = BASE_PATH . '/storage/framework/cache/permissions_cache.php';

        if (FileExistenceCache::exists($roleCacheFile)) {
            unlink($roleCacheFile)
                ? $this->success("Role cache cleared successfully.")
                : $this->error("Failed to clear role cache.");
        } else {
            $this->warning("Role cache file does not exist.");
        }

        if (FileExistenceCache::exists($permissionCacheFile)) {
            unlink($permissionCacheFile)
                ? $this->success("Permission cache cleared successfully.")
                : $this->error("Failed to clear permission cache.");
        } else {
            $this->warning("Permission cache file does not exist.");
        }
    }

    private function clearModuleCache(): void
    {
        ModuleCache::clear()
            ? $this->success("Module registration cache cleared successfully.")
            : $this->warning("Module registration cache file does not exist.");
    }
}
