<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\Logger;
use Forge\Core\Structure\StructureResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class HelperDiscoverSetup
{
    private const string HELPER_MAP_CACHE_FILE =
        BASE_PATH . "/storage/framework/cache/helper-map.php";
    private static array $helperSearchPaths = [];
    private static array $includedFiles = [];

    private static function getHelperSearchPaths(): array
    {
        if (empty(self::$helperSearchPaths)) {
            $resolver = new StructureResolver();
            $paths = [];

            foreach ($resolver->getAppPaths('support') as $supportPath) {
                $paths[] = BASE_PATH . '/' . $supportPath;
            }

            $modulesRoot = $resolver->getModulesRoot();
            $modulesPath = BASE_PATH . '/' . $modulesRoot;
            if (is_dir($modulesPath)) {
                $moduleDirs = scandir($modulesPath);
                foreach ($moduleDirs as $module) {
                    if ($module === '.' || $module === '..') continue;
                    $moduleDir = $modulesPath . '/' . $module;
                    if (!is_dir($moduleDir)) continue;

                    try {
                        $moduleSupportPaths = $resolver->getModulePaths($module, 'support');
                    } catch (\InvalidArgumentException) {
                        $moduleSupportPaths = ['src/Support'];
                    }

                    foreach ($moduleSupportPaths as $supportPath) {
                        $paths[] = $moduleDir . '/' . $supportPath;
                    }
                }
            }

            self::$helperSearchPaths = $paths;
        }
        return self::$helperSearchPaths;
    }

    /**
     * Discovers, caches, and includes all helper files.
     */
    public static function setup(): void
    {
        $helperFiles = self::loadHelperMapCache();
        if ($helperFiles !== null) {
            self::includeHelpersWithBase($helperFiles);
            return;
        }

        [$absoluteFiles, $scannedDirs] = self::discoverHelperFiles();

        $relativeFiles = self::convertToRelativePaths($absoluteFiles);
        self::generateHelperMapCache($relativeFiles, $scannedDirs);
        self::includeHelpers(array_values($absoluteFiles));
    }

    /**
     * Loads the helper file paths from cache.
     * Validates stored directory mtimes — no recursive scanning needed.
     * @return array<string>|null Array of file paths relative to BASE_PATH, or null if cache invalid.
     */
    private static function loadHelperMapCache(): ?array
    {
        if (!file_exists(self::HELPER_MAP_CACHE_FILE)) {
            return null;
        }

        try {
            $cachedData = include self::HELPER_MAP_CACHE_FILE;
            if (!is_array($cachedData)) {
                return null;
            }

            $files = $cachedData['files'] ?? null;
            $dirMtimes = $cachedData['dir_mtimes'] ?? [];

            if ($files === null) {
                return null;
            }

            foreach ($dirMtimes as $dir => $cachedMtime) {
                if (!is_dir($dir)) {
                    return null;
                }
                $currentMtime = @filemtime($dir);
                if ($currentMtime === false || $currentMtime > $cachedMtime) {
                    return null;
                }
            }

            $absolutePaths = [];
            foreach ($files as $relativePath) {
                $absolutePaths[] = BASE_PATH . $relativePath;
            }
            FileExistenceCache::preload($absolutePaths);

            foreach ($absolutePaths as $absolutePath) {
                if (!FileExistenceCache::exists($absolutePath)) {
                    return null;
                }
            }

            return $files;
        } catch (\Exception $e) {
            Logger::log("HelperDiscoverSetup: failed to load helper map cache", $e->getMessage());
            return null;
        }
    }

    /**
     * Reconstructs absolute paths from relative paths stored in cache and includes them.
     * @param array<string> $relativePaths Array of file paths relative to BASE_PATH.
     */
    private static function includeHelpersWithBase(array $relativePaths): void
    {
        $absolutePaths = [];
        foreach ($relativePaths as $relativePath) {
            $absolutePaths[] = BASE_PATH . $relativePath;
        }

        FileExistenceCache::preload($absolutePaths);

        foreach ($absolutePaths as $absolutePath) {
            if (FileExistenceCache::exists($absolutePath) && !in_array($absolutePath, self::$includedFiles, true)) {
                self::$includedFiles[] = $absolutePath;
                require $absolutePath;
            }
        }
    }

    /**
     * Scans the defined paths for all helper files.
     * @return array{0: array<string>, 1: array<string, int>} [absolute file paths, directory mtimes]
     */
    private static function discoverHelperFiles(): array
    {
        $files = [];
        $scannedDirs = [];

        foreach (self::getHelperSearchPaths() as $directory) {
            if (is_dir($directory)) {
                $files = array_merge($files, self::scanDirectory($directory));
                $scannedDirs[$directory] = @filemtime($directory) ?: 0;
            }
        }

        return [array_unique($files), $scannedDirs];
    }

    /**
     * Recursively scans a directory for PHP files.
     * @param string $directory
     * @return array<string>
     */
    private static function scanDirectory(string $directory): array
    {
        $files = [];
        $directoryIterator = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directoryIterator);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === "php") {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    /**
     * Converts a list of absolute paths to paths relative to BASE_PATH.
     * @param array<string> $absolutePaths
     * @return array<string> Array of relative file paths.
     */
    private static function convertToRelativePaths(array $absolutePaths): array
    {
        $basePathLength = strlen(BASE_PATH);
        $relativePaths = [];
        foreach ($absolutePaths as $path) {
            if (str_starts_with($path, BASE_PATH)) {
                $relativePaths[] = substr($path, $basePathLength);
            } else {
                $relativePaths[] = $path;
            }
        }
        return $relativePaths;
    }

    /**
     * Generates and caches the helper map with directory mtimes for validation.
     * @param array<string> $helperFiles Array of file paths relative to BASE_PATH.
     * @param array<string, int> $dirMtimes Map of directory path to mtime.
     */
    private static function generateHelperMapCache(array $helperFiles, array $dirMtimes): void
    {
        if (!is_dir(dirname(self::HELPER_MAP_CACHE_FILE))) {
            mkdir(dirname(self::HELPER_MAP_CACHE_FILE), 0777, true);
        }

        $cacheData = [
            'files' => $helperFiles,
            'dir_mtimes' => $dirMtimes,
        ];

        $cacheContent = "<?php return " . var_export($cacheData, true) . ";";
        file_put_contents(self::HELPER_MAP_CACHE_FILE, $cacheContent);
    }

    /**
     * Includes all helper files into the global scope using their absolute paths.
     * @param array<string> $helperFiles Array of absolute file paths.
     */
    private static function includeHelpers(array $helperFiles): void
    {
        foreach ($helperFiles as $filepath) {
            if (!in_array($filepath, self::$includedFiles, true)) {
                self::$includedFiles[] = $filepath;
                require $filepath;
            }
        }
    }
}
