<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\Config\Config;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\Logger;
use Forge\Core\Helpers\ModuleHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class OptimizedDirectoryScanner
{
    private const CACHE_FILE = BASE_PATH . '/storage/framework/cache/directory_structure.php';

    private static array $cache = [];
    private static ?int $cacheTime = null;
    private static bool $loaded = false;
    private static ?array $cachedMtimes = null;

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

        $modulesPath = BASE_PATH . '/' . \Forge\Core\Structure\StructureResolver::resolveModulesRoot();
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
        $basePaths = array_merge($basePaths, self::getAttributeDiscoveryPaths($config));

        self::setCachedData($cacheKey, $basePaths);
        return $basePaths;
    }

    /**
     * Get base paths for attribute-based discovery (app + enabled module src dirs).
     * Excludes kernel paths since kernel classes are typically hardcoded.
     */
    public static function getAttributeDiscoveryPaths(?Config $config = null): array
    {
        $cacheKey = 'attribute_discovery_paths';
        $cached = self::getCachedData($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $basePaths = [];
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
     * Get cached data — validates by comparing directory mtimes, no TTL.
     */
    private static function getCachedData(string $key)
    {
        if (!self::$loaded) {
            self::loadCache();
        }

        if (self::$cachedMtimes === null || !self::areMtimesValid(self::$cachedMtimes)) {
            self::$cache = [];
            self::$cachedMtimes = null;
            return null;
        }

        return self::$cache[$key] ?? null;
    }

    /**
     * Check that no monitored directories have changed since cache was built.
     */
    private static function areMtimesValid(array $cached): bool
    {
        foreach ($cached as $path => $expectedMtime) {
            $current = @filemtime($path);
            if ($current === false || $current !== $expectedMtime) {
                return false;
            }
        }
        return true;
    }

    /**
     * Collect mtimes for all directories the scanner depends on.
     * @return array<string, int>
     */
    private static function collectDependentMtimes(): array
    {
        $mtimes = [];

        $modulesPath = BASE_PATH . '/' . \Forge\Core\Structure\StructureResolver::resolveModulesRoot();
        if (is_dir($modulesPath)) {
            $modulesMtime = @filemtime($modulesPath);
            if ($modulesMtime !== false) {
                $mtimes[$modulesPath] = $modulesMtime;
            }
            foreach (scandir($modulesPath) as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $dir = $modulesPath . '/' . $entry;
                if (is_dir($dir)) {
                    $dirMtime = @filemtime($dir);
                    if ($dirMtime !== false) {
                        $mtimes[$dir] = $dirMtime;
                    }
                }
            }
        }

        $appPath = BASE_PATH . '/app';
        if (is_dir($appPath)) {
            $appMtime = @filemtime($appPath);
            if ($appMtime !== false) {
                $mtimes[$appPath] = $appMtime;
            }
        }

        ksort($mtimes);
        return $mtimes;
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
            self::$cachedMtimes = null;
            self::$loaded = true;
            return;
        }

        try {
            $data = include self::CACHE_FILE;
            if (is_array($data)) {
                self::$cache = $data['data'] ?? [];
                self::$cacheTime = $data['time'] ?? null;
                self::$cachedMtimes = $data['mtimes'] ?? null;
            }
        } catch (\Throwable $e) {
            Logger::log("OptimizedDirectoryScanner: cache corrupted", $e->getMessage());
        }

        self::$loaded = true;
    }

    /**
     * Save cache to disk with current directory mtimes for validation.
     */
    private static function saveCache(): void
    {
        $directory = dirname(self::CACHE_FILE);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        self::$cachedMtimes = self::collectDependentMtimes();

        $data = [
            'data' => self::$cache,
            'time' => self::$cacheTime,
            'mtimes' => self::$cachedMtimes,
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
        self::$cachedMtimes = null;

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
