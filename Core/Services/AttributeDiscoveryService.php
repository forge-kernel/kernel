<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\DI\Attributes\Injectable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;

#[Injectable]
final class AttributeDiscoveryService
{
    private const string CACHE_FILE = BASE_PATH . "/storage/framework/cache/attribute-discovery-cache.php";
    private const array EXCLUDED_DIRS = ['config', 'vendor', 'node_modules', '.git', 'storage', 'public'];
    private const array EXCLUDED_PATTERNS = ['/config/', '/vendor/', '/node_modules/', '/.git/', '/storage/', '/public/'];

    private static array $cacheData = [];
    private static bool $cacheLoaded = false;

    /** @var array<string, array<string, array{file: string, mtime: int, attributes: array<string>}>>> */
    private static array $discoverCache = [];

    /**
     * Discover classes with specific attributes in given base paths
     *
     * @param array<string> $basePaths Base paths to scan (e.g., ['app', 'modules/ModuleName/src'])
     * @param array<string> $attributeClasses Attribute class names to search for
     * @param bool $filterResults When true, returns only classes matching the requested attributes.
     *                            When false, returns the full class map (for consumers that scan
     *                            all classes for method-level attributes like #[EventListener]).
     * @return array<string, array{file: string, mtime: int, attributes: array<string>}> Class map with metadata
     */
    public function discover(array $basePaths, array $attributeClasses, bool $filterResults = true): array
    {
        $signature = md5(serialize($basePaths) . serialize($attributeClasses) . '|' . (int) $filterResults);
        if (isset(self::$discoverCache[$signature])) {
            return self::$discoverCache[$signature];
        }

        $cache = $this->loadCache();

        // If cache already covers the requested paths and attributes, return it.
        if ($this->isCacheFullyValid($cache, $basePaths, $attributeClasses)) {
            $classMap = $cache['class_map'] ?? [];
            return self::$discoverCache[$signature] = $filterResults
                ? $this->filterByAttributes($classMap, $attributeClasses)
                : $classMap;
        }

        // If only new attributes are requested (dirs unchanged), do additive scan.
        // Otherwise, do a full filesystem scan.
        if ($this->areDirsValid($cache, $basePaths) && !empty($cache['scanned_files'] ?? [])) {
            $this->additiveScan($cache, $basePaths, $attributeClasses);
        } else {
            $this->fullScan($cache, $basePaths, $attributeClasses);
        }

        // Update scanned_attributes metadata
        foreach ($basePaths as $basePath) {
            $cache['metadata']['scanned_attributes'][$basePath] = array_values(array_unique(array_merge(
                $cache['metadata']['scanned_attributes'][$basePath] ?? [],
                $attributeClasses,
            )));
        }

        $this->saveCache($cache);

        $finalClassMap = $cache['class_map'] ?? [];
        return self::$discoverCache[$signature] = $filterResults
            ? $this->filterByAttributes($finalClassMap, $attributeClasses)
            : $finalClassMap;
    }

    /**
     * Full filesystem scan: walk all base paths, check each file for attributes.
     */
    private function fullScan(array &$cache, array $basePaths, array $attributeClasses): void
    {
        $scannedPathAttributes = $cache['metadata']['scanned_attributes'] ?? [];

        $attributesToScan = $attributeClasses;
        foreach ($basePaths as $basePath) {
            if (isset($scannedPathAttributes[$basePath])) {
                $attributesToScan = array_merge($attributesToScan, $scannedPathAttributes[$basePath]);
            }
        }
        $attributesToScan = array_values(array_unique($attributesToScan));

        $newClassMap = [];
        $scannedFiles = [];
        $scannedDirs = [];

        foreach ($basePaths as $basePath) {
            $fullPath = BASE_PATH . '/' . ltrim($basePath, '/');
            if (!is_dir($fullPath)) {
                continue;
            }

            $this->scanDirectory($fullPath, $attributesToScan, $cache, $newClassMap, $scannedFiles, $scannedDirs);
        }

        $this->cleanupStaleEntries($cache, $scannedFiles, $basePaths);

        $cache['class_map'] = array_merge($cache['class_map'] ?? [], $newClassMap);
        $cache['metadata']['last_scan'] = time();
        $cache['metadata']['version'] = 1;
        $cache['scanned_dirs'] = $scannedDirs;
        $cache['scanned_files'] = array_values(array_unique(array_merge(
            $cache['scanned_files'] ?? [],
            $scannedFiles,
        )));
    }

    /**
     * Additive scan: only check previously-scanned files for new attributes.
     * Skips the filesystem walk (RecursiveDirectoryIterator) when only new
     * attribute classes are requested and no files have changed.
     */
    private function additiveScan(array &$cache, array $basePaths, array $attributeClasses): void
    {
        $scannedPathAttributes = $cache['metadata']['scanned_attributes'] ?? [];

        $newAttributes = $attributeClasses;
        foreach ($basePaths as $basePath) {
            if (isset($scannedPathAttributes[$basePath])) {
                $newAttributes = array_diff($newAttributes, $scannedPathAttributes[$basePath]);
            }
        }

        if (empty($newAttributes)) {
            return;
        }

        $newAttributes = array_values($newAttributes);

        foreach ($cache['scanned_files'] ?? [] as $filepath) {
            if (!file_exists($filepath)) {
                continue;
            }

            $currentMtime = @filemtime($filepath);
            if ($currentMtime === false) {
                continue;
            }

            $className = $this->fileToClass($filepath);
            $cachedEntry = $cache['class_map'][$className] ?? null;

            // File is already in class_map with current mtime, check for new attributes
            if ($cachedEntry && $cachedEntry['mtime'] === $currentMtime) {
                $hasNewAttr = false;
                foreach ($newAttributes as $newAttr) {
                    if (!in_array($newAttr, $cachedEntry['attributes'] ?? [], true)) {
                        $hasNewAttr = true;
                        break;
                    }
                }

                if (!$hasNewAttr) {
                    continue;
                }
            }

            // Re-check file for new attributes
            $attributesFound = $this->checkFileAttributes($filepath, $newAttributes, $currentMtime);
            if (!empty($attributesFound)) {
                if ($cachedEntry) {
                    $cachedEntry['attributes'] = array_values(array_unique(array_merge(
                        $cachedEntry['attributes'],
                        $attributesFound,
                    )));
                    $cache['class_map'][$className] = $cachedEntry;
                } else {
                    $cache['class_map'][$className] = [
                        'file' => $filepath,
                        'mtime' => $currentMtime,
                        'attributes' => $attributesFound,
                    ];
                }
            }
        }

        $cache['metadata']['last_scan'] = time();
    }

    /**
     * Check a single file for specific attributes (string-match + reflection).
     * Returns the list of attribute classes found.
     */
    private function checkFileAttributes(string $filepath, array $attributeClasses, int $mtime): array
    {
        $contents = @file_get_contents($filepath);
        if ($contents === false) {
            return [];
        }

        $hasAttribute = false;
        foreach ($attributeClasses as $attributeClass) {
            $fullNamespace = $attributeClass;
            $namespaceParts = explode('\\', $fullNamespace);
            $shortName = end($namespaceParts);

            if (
                strpos($contents, "#[$fullNamespace") !== false ||
                strpos($contents, "#[\\$fullNamespace") !== false ||
                strpos($contents, "#[$shortName") !== false ||
                strpos($contents, "#[\\$shortName") !== false
            ) {
                $hasAttribute = true;
                break;
            }

            if (
                preg_match('/use\s+' . preg_quote(str_replace('\\', '\\\\', $fullNamespace), '/') . '\s*;/', $contents) &&
                (strpos($contents, "#[$shortName") !== false || strpos($contents, "#[\\$shortName") !== false)
            ) {
                $hasAttribute = true;
                break;
            }
        }

        if (!$hasAttribute) {
            return [];
        }

        $className = $this->fileToClass($filepath);

        if (!class_exists($className, false)) {
            try {
                @include_once $filepath;
            } catch (\Throwable $e) {
                return [];
            }
        }

        if (!class_exists($className)) {
            return [];
        }

        try {
            $reflectionClass = new ReflectionClass($className);
            if ($reflectionClass->isAbstract() || $reflectionClass->isInterface()) {
                return [];
            }

            $found = [];
            foreach ($attributeClasses as $attributeClass) {
                if (!empty($reflectionClass->getAttributes($attributeClass))) {
                    $found[] = $attributeClass;
                }
            }
            return $found;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Filter a class map to only include classes whose attributes intersect
     * the requested attribute classes.
     *
     * @param array<string, array{file: string, mtime: int, attributes: array<string>}> $classMap
     * @param array<string> $attributeClasses
     * @return array<string, array{file: string, mtime: int, attributes: array<string>}>
     */
    private function filterByAttributes(array $classMap, array $attributeClasses): array
    {
        if (empty($attributeClasses)) {
            return $classMap;
        }

        $attrSet = array_flip($attributeClasses);
        $filtered = [];

        foreach ($classMap as $className => $metadata) {
            foreach ($metadata['attributes'] ?? [] as $attr) {
                if (isset($attrSet[$attr])) {
                    $filtered[$className] = $metadata;
                    break;
                }
            }
        }

        return $filtered;
    }

    /**
     * Check if the cache is fully valid — all requested paths and attributes
     * are covered and directory mtimes are unchanged.
     */
    private function isCacheFullyValid(array $cache, array $basePaths, array $attributeClasses): bool
    {
        if (!$this->areDirsValid($cache, $basePaths)) {
            return false;
        }

        $scannedPathAttributes = $cache['metadata']['scanned_attributes'] ?? [];

        foreach ($basePaths as $basePath) {
            if (!isset($scannedPathAttributes[$basePath]) || !empty(array_diff($attributeClasses, $scannedPathAttributes[$basePath]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if stored directory mtimes are still current.
     */
    private function areDirsValid(array $cache, array $basePaths): bool
    {
        $scannedDirs = $cache['scanned_dirs'] ?? [];

        if (empty($scannedDirs)) {
            return false;
        }

        foreach ($basePaths as $basePath) {
            $fullPath = BASE_PATH . '/' . ltrim($basePath, '/');
            if (!is_dir($fullPath)) {
                return false;
            }
            if (!isset($scannedDirs[$fullPath])) {
                return false;
            }
        }

        foreach ($scannedDirs as $dir => $cachedMtime) {
            if (!is_dir($dir)) {
                return false;
            }
            $currentMtime = @filemtime($dir);
            if ($currentMtime === false || $currentMtime > $cachedMtime) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all classes with a specific attribute
     *
     * @param string $attributeClass Full class name of the attribute
     * @return array<string> Array of class names
     */
    public function getClassesWithAttribute(string $attributeClass): array
    {
        $cache = $this->loadCache();
        $cacheKey = '_attr_list_' . $attributeClass;
        if (isset(self::$discoverCache[$cacheKey])) {
            return self::$discoverCache[$cacheKey];
        }
        $classes = [];

        foreach ($cache['class_map'] ?? [] as $className => $metadata) {
            if (in_array($attributeClass, $metadata['attributes'] ?? [], true)) {
                $classes[] = $className;
            }
        }

        return self::$discoverCache[$cacheKey] = $classes;
    }

    public function getClassesWithAttributeMetadata(string $attributeClass): array
    {
        $cache = $this->loadCache();
        $cacheKey = '_attr_meta_' . $attributeClass;
        if (isset(self::$discoverCache[$cacheKey])) {
            return self::$discoverCache[$cacheKey];
        }
        $result = [];

        foreach ($cache['class_map'] ?? [] as $className => $metadata) {
            if (in_array($attributeClass, $metadata['attributes'] ?? [], true)) {
                $result[$className] = $metadata;
            }
        }

        return self::$discoverCache[$cacheKey] = $result;
    }

    /**
     * Scan a directory recursively for classes with attributes.
     * Also collects directory mtimes for cache validation.
     */
    private function scanDirectory(
        string $directory,
        array $attributeClasses,
        array &$cache,
        array &$newClassMap,
        array &$scannedFiles,
        array &$scannedDirs
    ): void {
        $scannedDirs[$directory] = @filemtime($directory) ?: 0;

        try {
            $directoryIterator = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
            $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);

            $rescannedFiles = [];

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    $scannedDirs[$file->getPathname()] = @filemtime($file->getPathname()) ?: 0;
                    continue;
                }

                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $filepath = $file->getRealPath();
                $scannedFiles[] = $filepath;

                if ($this->shouldExcludeFile($filepath)) {
                    continue;
                }

                $currentMtime = $file->getMTime();
                $cachedEntry = $cache['class_map'][$this->fileToClass($filepath)] ?? null;

                if ($cachedEntry && $cachedEntry['mtime'] === $currentMtime) {
                    continue;
                }

                $rescannedFiles[] = $filepath;
                $this->scanFile($filepath, $attributeClasses, $currentMtime, $newClassMap);
            }

            foreach ($cache['class_map'] ?? [] as $className => $metadata) {
                if (in_array($metadata['file'], $rescannedFiles, true) && !isset($newClassMap[$className])) {
                    unset($cache['class_map'][$className]);
                }
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * Scan a single file for attributes
     */
    private function scanFile(
        string $filepath,
        array $attributeClasses,
        int $mtime,
        array &$newClassMap
    ): void {
        $contents = @file_get_contents($filepath);
        if ($contents === false) {
            return;
        }

        $hasAttribute = false;
        foreach ($attributeClasses as $attributeClass) {
            $shortName = $this->getAttributeShortName($attributeClass);
            $fullNamespace = $attributeClass;

            if (
                strpos($contents, "#[$fullNamespace") !== false ||
                strpos($contents, "#[\\$fullNamespace") !== false
            ) {
                $hasAttribute = true;
                break;
            }

            $namespaceParts = explode('\\', $fullNamespace);
            $className = end($namespaceParts);
            if (
                preg_match('/use\s+' . preg_quote(str_replace('\\', '\\\\', $fullNamespace), '/') . '\s*;/', $contents) &&
                (strpos($contents, "#[$className") !== false || strpos($contents, "#[\\$className") !== false)
            ) {
                $hasAttribute = true;
                break;
            }

            if (strpos($contents, "#[$shortName") !== false || strpos($contents, "#[\\$shortName") !== false) {
                $hasAttribute = true;
                break;
            }
        }

        if (!$hasAttribute) {
            return;
        }

        $className = $this->fileToClass($filepath);

        if (!class_exists($className, false)) {
            $previousErrorHandler = set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($filepath) {
                if ($errfile === $filepath) {
                    return true;
                }
                return false;
            }, E_ALL);

            $previousExceptionHandler = set_exception_handler(function ($exception) {
                return true;
            });

            try {
                if (!class_exists($className, false)) {
                    @include_once $filepath;
                }
            } catch (\Throwable $e) {
                restore_error_handler();
                if ($previousExceptionHandler) {
                    set_exception_handler($previousExceptionHandler);
                }
                return;
            }

            restore_error_handler();
            if ($previousExceptionHandler) {
                set_exception_handler($previousExceptionHandler);
            }
        }

        if (!class_exists($className)) {
            return;
        }

        try {
            $reflectionClass = new ReflectionClass($className);

            if ($reflectionClass->isAbstract() || $reflectionClass->isInterface()) {
                return;
            }

            $foundAttributes = [];

            foreach ($attributeClasses as $attributeClass) {
                if (!empty($reflectionClass->getAttributes($attributeClass))) {
                    $foundAttributes[] = $attributeClass;
                }
            }

            if (!empty($foundAttributes)) {
                $newClassMap[$className] = [
                    'file' => $filepath,
                    'mtime' => $mtime,
                    'attributes' => $foundAttributes,
                ];
            }
        } catch (ReflectionException $e) {
        } catch (\Error $e) {
        } catch (\Throwable $e) {
        }
    }

    /**
     * Check if a file should be excluded from scanning
     */
    private function shouldExcludeFile(string $filepath): bool
    {
        foreach (self::EXCLUDED_PATTERNS as $pattern) {
            if (strpos($filepath, $pattern) !== false) {
                return true;
            }
        }

        $parts = explode('/', $filepath);
        foreach ($parts as $part) {
            if (in_array($part, self::EXCLUDED_DIRS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get short name of attribute class for string matching
     */
    private function getAttributeShortName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }

    /**
     * Convert file path to class name
     */
    private function fileToClass(string $filepath): string
    {
        $basePath = rtrim(str_replace('\\', '/', BASE_PATH), '/');
        $normalizedPath = str_replace('\\', '/', $filepath);

        $relativePath = str_replace($basePath, '', $normalizedPath);
        $relativePath = ltrim($relativePath, '/');

        $class = str_replace(['.php', '/'], ['', '\\'], $relativePath);
        $class = ltrim($class, '\\');

        if (str_starts_with($class, 'kernel\\Core\\')) {
            $class = str_replace('kernel\\Core\\', 'Forge\\Core\\', $class);
        } elseif (str_starts_with($class, 'app\\')) {
            $class = str_replace('app\\', 'App\\', $class);
        } elseif (str_starts_with($class, 'modules\\')) {
            $class = str_replace('modules\\', 'App\\Modules\\', $class);
            // Remove 'src' from path if present
            $class = str_replace('\\src\\', '\\', $class);
            $class = preg_replace('/^App\\\\Modules\\\\([^\\\\]+)\\\\src\\\\/', 'App\\Modules\\$1\\', $class);
        }

        // Seeder and migration files use a timestamp prefix in the filename
        // (e.g. 2025_01_01_000000_ClassName) while the class name omits it.
        $pathLower = strtolower(str_replace('\\', '/', $class));
        if (str_contains($pathLower, 'database/seeders/') || str_contains($pathLower, 'database/migrations/')) {
            $parts = explode('\\', $class);
            $lastIndex = count($parts) - 1;
            $parts[$lastIndex] = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $parts[$lastIndex]);
            $class = implode('\\', $parts);
        }

        return $class;
    }

    /**
     * Load cache from file
     */
    private function loadCache(): array
    {
        if (self::$cacheLoaded) {
            return self::$cacheData;
        }

        if (!file_exists(self::CACHE_FILE)) {
            return self::$cacheData = [
                'class_map' => [],
                'scanned_dirs' => [],
                'scanned_files' => [],
                'metadata' => [
                    'last_scan' => 0,
                    'version' => 1,
                    'scanned_attributes' => [],
                ],
            ];
        }

        try {
            $cache = include self::CACHE_FILE;
            if (is_array($cache) && isset($cache['class_map']) && isset($cache['metadata'])) {
                $cache['metadata']['scanned_attributes'] = $cache['metadata']['scanned_attributes'] ?? [];
                self::$cacheLoaded = true;
                return self::$cacheData = $cache;
            }
        } catch (\Exception $e) {

        }

        return self::$cacheData = [
            'class_map' => [],
            'scanned_dirs' => [],
            'scanned_files' => [],
            'metadata' => [
                'last_scan' => 0,
                'version' => 1,
                'scanned_attributes' => [],
            ],
        ];
    }

    /**
     * Save cache to file
     */
    private function saveCache(array $cache): void
    {
        $cacheDir = dirname(self::CACHE_FILE);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $fp = fopen(self::CACHE_FILE, 'c+');
        if ($fp === false) {
            return;
        }

        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, "<?php return " . var_export($cache, true) . ";");
            flock($fp, LOCK_UN);
        }

        fclose($fp);

        self::$cacheData = $cache;
        self::$cacheLoaded = true;
        self::$discoverCache = [];
    }

    /**
     * Remove stale cache entries.
     * Only removes entries for files inside the currently scanned base paths
     * that were not discovered this scan (attribute removed), plus files that
     * no longer exist on disk. Entries from other base paths are preserved.
     */
    private function cleanupStaleEntries(array &$cache, array $scannedFiles, array $basePaths): void
    {
        $scannedFilesSet = array_flip($scannedFiles);
        $hasChanges = false;

        $scannedPrefixes = array_map(
            fn(string $path): string => rtrim(BASE_PATH . '/' . ltrim($path, '/'), '/') . '/',
            $basePaths,
        );

        foreach ($cache['class_map'] ?? [] as $className => $metadata) {
            $filepath = $metadata['file'] ?? '';

            if (!file_exists($filepath)) {
                unset($cache['class_map'][$className]);
                $hasChanges = true;
                continue;
            }

            $isInsideScannedPath = false;
            foreach ($scannedPrefixes as $prefix) {
                if (str_starts_with($filepath, $prefix)) {
                    $isInsideScannedPath = true;
                    break;
                }
            }

            if ($isInsideScannedPath && !isset($scannedFilesSet[$filepath])) {
                unset($cache['class_map'][$className]);
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            $cache['metadata']['last_scan'] = time();
        }
    }

    /**
     * Clear the discovery cache
     */
    public function clearCache(): void
    {
        if (file_exists(self::CACHE_FILE)) {
            unlink(self::CACHE_FILE);
        }
        self::$cacheData = [];
        self::$cacheLoaded = false;
        self::$discoverCache = [];
    }

    /**
     * Get the cache file path
     */
    public function getCacheFilePath(): string
    {
        return self::CACHE_FILE;
    }
}
