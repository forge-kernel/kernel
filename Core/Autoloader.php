<?php
declare(strict_types=1);

namespace Forge\Core;

use Forge\Exceptions\ClassNotFoundException;
use Forge\Core\Helpers\FileExistenceCache;
use SplFileInfo;
use const PHP_SAPI;

final class Autoloader
{
    /** @var array<string,string>  namespace prefix → base directory (normalised, no trailing slash) */
    private static array $map = [];

    /** @var bool  set to true once test loading is allowed (avoids premature *Test autoloading) */
    private static bool $allowTestLoading = false;

    /** @var SplFileInfo[]  fast file-existence cache (APCu in web, array in CLI) */
    private static array $cache = [];

    /** @var int  maximum number of entries in the in-process cache */
    private static int $maxCacheSize = 8_000;

    /** @var array<string, bool>  tracks files currently being loaded to prevent concurrent requires */
    private static array $loadingFiles = [];

    /** @var array<string,string>  persistent class → file mapping cache */
    private static array $classFileMap = [];

    /** @var array<string,string>  lowercase class → original class mapping for faster lookups */
    private static array $lowerClassMap = [];

    /** @var array<string>  batch file existence checks */
    private static array $fileCheckBatch = [];

    /** @var bool  flag to prevent cache saving during flush */
    private static bool $cacheSavingEnabled = true;

    public static function register(): void
    {
        self::buildMap();
        self::loadClassMapCache();
        spl_autoload_register([self::class, 'load'], true, true);

        // Register shutdown function to save cache
        register_shutdown_function([self::class, 'saveClassMapCache']);
    }

    /**
     * Disable cache saving (useful during cache flush operations)
     */
    public static function disableCacheSaving(): void
    {
        self::$cacheSavingEnabled = false;
    }

    /**
     * Enable cache saving
     */
    public static function enableCacheSaving(): void
    {
        self::$cacheSavingEnabled = true;
    }

    /**
     * Allow autoloading of *Test classes (called by test runner).
     * Test classes are blocked by default to prevent accidental loading
     * during normal HTTP requests.
     */
    public static function allowTestLoading(): void
    {
        self::$allowTestLoading = true;
    }

    private static function buildMap(): void
    {
        if (is_dir(BASE_PATH . '/app')) {
            self::addPath('app', BASE_PATH . '/app');
        }
        self::addPath('forge', BASE_PATH . '/kernel');
        if (is_dir(BASE_PATH . '/modules')) {
            self::addPath('modules', BASE_PATH . '/modules');
        }
    }

    public static function addPath(string $namespace, string $path): void
    {
        $ns = strtolower(trim($namespace, '\\'));
        $dir = rtrim(realpath($path), \DIRECTORY_SEPARATOR);
        if ($dir === false || !is_dir($dir)) {
            throw new \InvalidArgumentException("Directory $path does not exist");
        }
        self::$map[$ns] = $dir;
    }

    public static function getPaths(): array
    {
        return self::$map;
    }

    public static function removePath(string $namespace): void
    {
        $ns = strtolower(trim($namespace, '\\'));
        unset(self::$map[$ns]);
    }

    /**
     * Check if a file is currently being loaded by the autoloader.
     * This can be used to prevent manual requires that might conflict with autoloader.
     */
    public static function isFileLoading(string $realPath): bool
    {
        return isset(self::$loadingFiles[$realPath]);
    }

    /**
     * PSR-4 autoload callback.
     * @throws ClassNotFoundException
     */
    private static function load(string $class): void
    {
        $class = ltrim($class, '\\');

        if (!self::$allowTestLoading && str_ends_with($class, 'Test')) {
            return;
        }

        // Fast path: check persistent class file map first
        if (isset(self::$classFileMap[$class])) {
            $cachedFile = self::$classFileMap[$class];
            if (file_exists($cachedFile)) {
                self::requireFile($cachedFile, $class);
                return;
            }
            // Stale cache entry — clean up
            self::cleanupClassMapping($class);
        }

        // Use lowercase map for faster prefix matching
        $lower = strtolower($class);
        if (isset(self::$lowerClassMap[$lower])) {
            $actualClass = self::$lowerClassMap[$lower];
            if (isset(self::$classFileMap[$actualClass])) {
                self::requireFile(self::$classFileMap[$actualClass], $class);
                return;
            }
        }

        foreach (self::$map as $prefix => $dir) {
            if (!str_starts_with($lower, $prefix)) {
                continue;
            }

            // Optimize string operations
            $relative = substr($class, strlen($prefix));
            $relative = str_replace('\\', \DIRECTORY_SEPARATOR, $relative) . '.php';
            $file = $dir . \DIRECTORY_SEPARATOR . ltrim($relative, \DIRECTORY_SEPARATOR);

            if (isset(self::$cache[$file])) {
                /** @var SplFileInfo $info */
                $info = self::$cache[$file];
                if ($info->isFile()) {
                    $realPath = $info->getRealPath();
                    self::cacheClassMapping($class, $realPath);
                    self::requireFile($realPath, $class);
                    return;
                }
                unset(self::$cache[$file]);
            }

            // Batch file existence check for better performance
            self::$fileCheckBatch[] = $file;
            if (self::batchCheckFile($file)) {
                $realPath = realpath($file);
                if ($realPath !== false) {
                    self::cache($file);
                    self::cacheClassMapping($class, $realPath);
                    self::requireFile($realPath, $class);
                    return;
                }
            }
        }

        if (!str_ends_with($class, 'Test') || self::$allowTestLoading) {
            throw new ClassNotFoundException($class);
        }
    }

    /** Require the file in a way that survives concurrent includes. */
    private static function requireFile(string $realPath, string $class): void
    {
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return;
        }

        if (!file_exists($realPath)) {
            self::cleanupClassMapping($class);
            return;
        }

        if (isset(self::$loadingFiles[$realPath])) {
            return;
        }

        self::$loadingFiles[$realPath] = true;

        try {
            require_once $realPath;
        } finally {
            // Remove from loading tracking after require completes
            unset(self::$loadingFiles[$realPath]);
        }
    }

    private static function cache(string $path): void
    {
        self::evictCacheIfNeeded();
        self::$cache[$path] = new SplFileInfo($path);
    }

    /**
     * Cache class-to-file mapping for fast subsequent lookups
     * Only caches frequently used classes, not everything
     */
    private static function cacheClassMapping(string $class, string $realPath): void
    {
        // Only cache application-level classes, not kernel classes
        if (self::shouldCacheClass($class)) {
            self::$classFileMap[$class] = $realPath;
            self::$lowerClassMap[strtolower($class)] = $class;

            // Limit persistent cache size to prevent memory bloat
            if (count(self::$classFileMap) > 100) {
                array_shift(self::$classFileMap);
                array_shift(self::$lowerClassMap);
            }
        }
    }

    /**
     * Determine if a class should be cached
     * Kernel classes are stable and don't need caching
     */
    private static function shouldCacheClass(string $class): bool
    {
        if (
            str_starts_with($class, 'Forge\\Core\\') ||
            str_starts_with($class, 'Forge\\CLI\\') ||
            str_starts_with($class, 'Forge\\Traits\\')
        ) {
            return false;
        }

        // Don't cache test classes
        if (str_ends_with($class, 'Test')) {
            return false;
        }

        // Only cache application classes and frequently loaded classes
        return str_starts_with($class, 'App\\') || str_starts_with($class, 'Modules\\');
    }

    private static function cleanupClassMapping(string $class): void
    {
        unset(self::$classFileMap[$class]);
        unset(self::$lowerClassMap[strtolower($class)]);
    }

    /**
     * Batch file existence check with fallback to direct file_exists
     */
    private static function batchCheckFile(string $file): bool
    {
        // If we have accumulated files, batch check them
        if (count(self::$fileCheckBatch) >= 10) {
            // Try to use FileExistenceCache if available
            if (class_exists('\Forge\Core\Helpers\FileExistenceCache')) {
                FileExistenceCache::preload(self::$fileCheckBatch);
                self::$fileCheckBatch = [];
                return FileExistenceCache::exists($file);
            } else {
                // Fallback to direct file checks
                foreach (self::$fileCheckBatch as $checkFile) {
                    clearstatcache(true, $checkFile);
                }
                self::$fileCheckBatch = [];
            }
        }

        // For single checks, use available cache or fallback
        if (class_exists('\Forge\Core\Helpers\FileExistenceCache')) {
            return FileExistenceCache::exists($file);
        }

        return file_exists($file);
    }

    /**
     * Smart cache eviction - removes only oldest entries
     */
    private static function evictCacheIfNeeded(): void
    {
        if (count(self::$cache) >= self::$maxCacheSize) {
            // Remove oldest 25% instead of all entries
            $toRemove = (int) (self::$maxCacheSize * 0.25);
            self::$cache = array_slice(self::$cache, $toRemove, null, true);
        }
    }

    /**
     * Load persistent class map from cache
     */
    public static function loadClassMapCache(): void
    {
        if (!defined('BASE_PATH')) {
            return; // Skip if BASE_PATH not defined yet
        }

        $cacheFile = BASE_PATH . '/storage/framework/cache/class_file_map.php';
        if (file_exists($cacheFile)) {
            try {
                $data = include $cacheFile;
                if (is_array($data)) {
                    self::$classFileMap = $data['classFileMap'] ?? [];
                    self::$lowerClassMap = $data['lowerClassMap'] ?? [];
                }
            } catch (\Throwable $e) {
                // Cache corrupted, ignore
            }
        }
    }

    /**
     * Save persistent class map to cache
     */
    public static function saveClassMapCache(): void
    {
        if (empty(self::$classFileMap) || !defined('BASE_PATH') || !self::$cacheSavingEnabled) {
            return;
        }

        $cacheFile = BASE_PATH . '/storage/framework/cache/class_file_map.php';
        $directory = dirname($cacheFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $data = [
            'classFileMap' => self::$classFileMap,
            'lowerClassMap' => self::$lowerClassMap,
        ];

        $content = '<?php return ' . var_export($data, true) . ';';

        // Use atomic write for safety
        $tempFile = tempnam($directory, 'class_map_');
        if ($tempFile !== false) {
            file_put_contents($tempFile, $content);
            rename($tempFile, $cacheFile);
        } else {
            file_put_contents($cacheFile, $content);
        }
    }

    /**
     * Get autoloader performance statistics
     */
    public static function getStats(): array
    {
        return [
            'namespace_map_size' => count(self::$map),
            'cache_size' => count(self::$cache),
            'class_file_map_size' => count(self::$classFileMap),
            'lower_class_map_size' => count(self::$lowerClassMap),
            'max_cache_size' => self::$maxCacheSize,
            'cache_hit_ratio' => self::calculateCacheHitRatio(),
            'allow_test_loading' => self::$allowTestLoading,
        ];
    }

    /**
     * Calculate approximate cache hit ratio (for monitoring)
     */
    private static function calculateCacheHitRatio(): float
    {
        $totalLookups = count(self::$classFileMap) + count(self::$cache);
        if ($totalLookups === 0) {
            return 0.0;
        }

        return (count(self::$classFileMap) / $totalLookups) * 100;
    }
}
