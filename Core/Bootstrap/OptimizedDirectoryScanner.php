<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\Config\Config;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\ModuleHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class OptimizedDirectoryScanner
{
    private const CACHE_FILE = BASE_PATH . '/storage/framework/cache/directory_structure.php';
    private const CACHE_TTL = 300; // 5 minutes

    private static array $cache = [];
    private static ?int $cacheTime = null;
    private static bool $loaded = false;

    /**
     * Get module directories efficiently with caching
     */
    public static function getModuleDirectories(?Config $config = null): array
    {
        $cacheKey = 'module_directories';
        $cached = self::getCachedData($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $modulesPath = BASE_PATH . '/modules';
        if (!FileExistenceCache::isDir($modulesPath)) {
            return [];
        }

        $modules = [];
        $iterator = new RecursiveDirectoryIterator($modulesPath, RecursiveDirectoryIterator::SKIP_DOTS);

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir() && !ModuleHelper::isModuleDisabled($item->getBasename(), $config)) {
                $modules[$item->getBasename()] = $item->getPathname();
            }
        }

        self::setCachedData($cacheKey, $modules);
        return $modules;
    }

    /**
     * Get all base paths for service discovery efficiently
     */
    public static function getServiceDiscoveryPaths(?Config $config = null): array
    {
        $cacheKey = 'service_discovery_paths';
        $cached = self::getCachedData($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $basePaths = ['kernel/Core'];
        if (FileExistenceCache::isDir(BASE_PATH . '/app')) {
            $basePaths[] = 'app';
        }
        $modules = self::getModuleDirectories($config);

        foreach ($modules as $moduleName => $modulePath) {
            $srcPath = $modulePath . '/src';
            if (FileExistenceCache::isDir($srcPath)) {
                $basePaths[] = "modules/$moduleName/src";
            }
        }

        self::setCachedData($cacheKey, $basePaths);
        return $basePaths;
    }

    /**
     * Get controller directories efficiently
     */
    public static function getControllerDirectories(?Config $config = null): array
    {
        $cacheKey = 'controller_directories';
        $cached = self::getCachedData($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $controllerDirs = [];
        $appControllersDir = BASE_PATH . '/app/Controllers';
        if (FileExistenceCache::isDir($appControllersDir)) {
            $controllerDirs[] = $appControllersDir;
        }
        $modules = self::getModuleDirectories($config);

        foreach ($modules as $moduleName => $modulePath) {
            $controllerPath = $modulePath . '/src/Controllers';
            if (FileExistenceCache::isDir($controllerPath)) {
                $controllerDirs[] = $controllerPath;
            }
        }

        self::setCachedData($cacheKey, $controllerDirs);
        return $controllerDirs;
    }

    /**
     * Batch check if multiple files have changed (O(1) instead of O(n))
     */
    public static function hasFilesChanged(array $filesWithMtime): bool
    {
        if (empty($filesWithMtime)) {
            return false;
        }

        // Use FileExistenceCache::preload() for batch file system checks
        $filePaths = array_keys($filesWithMtime);
        FileExistenceCache::preload($filePaths);

        foreach ($filesWithMtime as $file => $expectedMtime) {
            if (!FileExistenceCache::exists($file)) {
                return true;
            }

            if (@filemtime($file) !== $expectedMtime) {
                return true;
            }
        }

        return false;
    }

    /**
     * Efficient directory existence and readability check
     */
    public static function validateDirectories(array $directories): array
    {
        $validDirs = [];

        if (empty($directories)) {
            return $validDirs;
        }

        // Batch preload directory checks
        FileExistenceCache::preload($directories);

        foreach ($directories as $dir) {
            if (FileExistenceCache::isDir($dir) && is_readable($dir)) {
                $validDirs[] = $dir;
            }
        }

        return $validDirs;
    }

    /**
     * Get cached data with TTL check
     */
    private static function getCachedData(string $key)
    {
        if (!self::$loaded) {
            self::loadCache();
        }

        // Check if cache is still valid
        if (self::$cacheTime !== null && (time() - self::$cacheTime) > self::CACHE_TTL) {
            return null;
        }

        return self::$cache[$key] ?? null;
    }

    /**
     * Set cached data
     */
    private static function setCachedData(string $key, $data): void
    {
        self::$cache[$key] = $data;
        self::saveCache();
    }

    /**
     * Load cache from disk
     */
    private static function loadCache(): void
    {
        if (self::$loaded) {
            return;
        }

        if (!FileExistenceCache::exists(self::CACHE_FILE)) {
            self::$cache = [];
            self::$cacheTime = null;
            self::$loaded = true;
            return;
        }

        try {
            $data = include self::CACHE_FILE;
            if (is_array($data)) {
                self::$cache = $data['data'] ?? [];
                self::$cacheTime = $data['time'] ?? null;
            }
        } catch (\Throwable $e) {
            // Cache corrupted, ignore
        }

        self::$loaded = true;
    }

    /**
     * Save cache to disk
     */
    private static function saveCache(): void
    {
        $directory = dirname(self::CACHE_FILE);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $data = [
            'data' => self::$cache,
            'time' => self::$cacheTime
        ];

        $content = '<?php return ' . var_export($data, true) . ';';
        file_put_contents(self::CACHE_FILE, $content);
    }

    /**
     * Clear cache
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$cacheTime = null;

        if (FileExistenceCache::exists(self::CACHE_FILE)) {
            @unlink(self::CACHE_FILE);
        }
    }

    /**
     * Get cache statistics for monitoring
     */
    public static function getCacheStats(): array
    {
        return [
            'cache_exists' => FileExistenceCache::exists(self::CACHE_FILE),
            'cache_age' => self::$cacheTime ? time() - self::$cacheTime : null,
            'cache_entries' => count(self::$cache),
            'cache_keys' => array_keys(self::$cache)
        ];
    }
}
