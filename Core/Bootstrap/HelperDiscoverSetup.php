<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\Helpers\FileExistenceCache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class HelperDiscoverSetup
{
    private const string HELPER_MAP_CACHE_FILE =
        BASE_PATH . "/storage/framework/cache/helper-map.php";
    private const array HELPER_SEARCH_PATHS = [
        BASE_PATH . "/app/Support",
        BASE_PATH . "/modules",
    ];

    /**
     * Discovers, caches, and includes all helper files.
     */
    public static function setup(): void
    {
        $helperFiles = [];

        if (self::isProductionOrStaging()) {
            $helperFiles = self::loadHelperMapCache();
            if ($helperFiles) {
                self::includeHelpersWithBase($helperFiles);
                return;
            }
        }

        $absoluteFiles = self::discoverHelperFiles();

        $relativeFiles = self::convertToRelativePaths($absoluteFiles);
        self::generateHelperMapCache($relativeFiles);
        self::includeHelpers(array_values($absoluteFiles));
    }

    private static function isProductionOrStaging(): bool
    {
        return isset($_ENV['APP_ENV']) && in_array($_ENV['APP_ENV'], ['production', 'staging'], true);
    }

    /**
     * Loads the helper file paths from cache.
     * @return array<string>|null Array of file paths relative to BASE_PATH, or null if cache not found or invalid.
     */
    private static function loadHelperMapCache(): ?array
    {
        if (file_exists(self::HELPER_MAP_CACHE_FILE)) {
            try {
                $cachedData = include self::HELPER_MAP_CACHE_FILE;
                if (is_array($cachedData)) {
                    foreach ($cachedData as $path) {
                        if (!file_exists(BASE_PATH . $path)) {
                            return null;
                        }
                    }
                    return $cachedData;
                }
            } catch (\Exception $e) {

            }
        }
        return null;
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
            if (FileExistenceCache::exists($absolutePath)) {
                require $absolutePath;
            }
        }
    }


    /**
     * Scans the defined paths for all helper files.
     * @return array<string> Array of absolute file paths.
     */
    private static function discoverHelperFiles(): array
    {
        $files = [];

        foreach (self::HELPER_SEARCH_PATHS as $directory) {
            if (is_dir($directory)) {
                if (str_ends_with($directory, '/Support')) {
                    $files = array_merge($files, self::scanDirectory($directory));
                    continue;
                }

                if (str_ends_with($directory, '/modules')) {
                    $moduleIterator = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
                    foreach (new RecursiveIteratorIterator($moduleIterator, RecursiveIteratorIterator::SELF_FIRST) as $item) {
                        if ($item->isDir() && $item->getFilename() === 'Support') {
                            if (strpos($item->getPathname(), 'src/Support') !== false) {
                                $files = array_merge($files, self::scanDirectory($item->getPathname()));
                            }
                        }
                    }
                }
            }
        }
        return array_unique($files);
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
     * Generates and caches the helper map to a file.
     * @param array<string> $helperFiles Array of file paths relative to BASE_PATH.
     */
    private static function generateHelperMapCache(array $helperFiles): void
    {
        if (!is_dir(dirname(self::HELPER_MAP_CACHE_FILE))) {
            mkdir(dirname(self::HELPER_MAP_CACHE_FILE), 0777, true);
        }

        $cacheContent = "<?php return " . var_export($helperFiles, true) . ";";
        file_put_contents(self::HELPER_MAP_CACHE_FILE, $cacheContent);
    }

    /**
     * Includes all helper files into the global scope using their absolute paths.
     * @param array<string> $helperFiles Array of absolute file paths.
     */
    private static function includeHelpers(array $helperFiles): void
    {
        foreach ($helperFiles as $filepath) {
            require $filepath;
        }
    }
}