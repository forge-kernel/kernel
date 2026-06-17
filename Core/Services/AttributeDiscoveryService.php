<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\DI\Attributes\Discoverable;
use Forge\Core\DI\Attributes\Migration;
use Forge\Core\DI\Attributes\Service;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;

#[Service]
final class AttributeDiscoveryService
{
    private const string CACHE_FILE = BASE_PATH . "/storage/framework/cache/attribute-discovery-cache.php";
    private const array EXCLUDED_DIRS = ['config', 'vendor', 'node_modules', '.git', 'storage', 'public'];
    private const array EXCLUDED_PATTERNS = ['/config/', '/vendor/', '/node_modules/', '/.git/', '/storage/', '/public/'];

    /**
     * Discover classes with specific attributes in given base paths
     * 
     * @param array<string> $basePaths Base paths to scan (e.g., ['app', 'modules/ModuleName/src'])
     * @param array<string> $attributeClasses Attribute class names to search for
     * @return array<string, array{file: string, mtime: int, attributes: array<string>}> Class map with metadata
     */
    public function discover(array $basePaths, array $attributeClasses): array
    {
        $cache = $this->loadCache();
        $newClassMap = [];
        $scannedFiles = [];

        foreach ($basePaths as $basePath) {
            $fullPath = BASE_PATH . '/' . ltrim($basePath, '/');
            if (!is_dir($fullPath)) {
                continue;
            }

            $this->scanDirectory($fullPath, $attributeClasses, $cache, $newClassMap, $scannedFiles);
        }

        $this->cleanupStaleEntries($cache, $scannedFiles);

        $finalClassMap = array_merge($cache['class_map'] ?? [], $newClassMap);
        $cache['class_map'] = $finalClassMap;
        $cache['metadata']['last_scan'] = time();
        $cache['metadata']['version'] = 1;

        $this->saveCache($cache);

        return $finalClassMap;
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
        $classes = [];

        foreach ($cache['class_map'] ?? [] as $className => $metadata) {
            if (in_array($attributeClass, $metadata['attributes'] ?? [], true)) {
                $classes[] = $className;
            }
        }

        return $classes;
    }

    /**
     * Scan a directory recursively for classes with attributes
     */
    private function scanDirectory(
        string $directory,
        array $attributeClasses,
        array &$cache,
        array &$newClassMap,
        array &$scannedFiles
    ): void {
        try {
            $directoryIterator = new RecursiveDirectoryIterator($directory);
            $iterator = new RecursiveIteratorIterator($directoryIterator);

            foreach ($iterator as $file) {
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

                $this->scanFile($filepath, $attributeClasses, $currentMtime, $newClassMap);
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
            
            if (strpos($contents, "#[$fullNamespace") !== false || 
                strpos($contents, "#[\\$fullNamespace") !== false) {
                $hasAttribute = true;
                break;
            }

            $namespaceParts = explode('\\', $fullNamespace);
            $className = end($namespaceParts);
            if (preg_match('/use\s+' . preg_quote(str_replace('\\', '\\\\', $fullNamespace), '/') . '\s*;/', $contents) &&
                (strpos($contents, "#[$className") !== false || strpos($contents, "#[\\$className") !== false)) {
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

        if (str_starts_with($class, 'engine\\Core\\')) {
            $class = str_replace('engine\\Core\\', 'Forge\\Core\\', $class);
        } elseif (str_starts_with($class, 'app\\')) {
            $class = str_replace('app\\', 'App\\', $class);
        } elseif (str_starts_with($class, 'modules\\')) {
            $class = str_replace('modules\\', 'App\\Modules\\', $class);
            // Remove 'src' from path if present
            $class = str_replace('\\src\\', '\\', $class);
            $class = preg_replace('/^App\\\\Modules\\\\([^\\\\]+)\\\\src\\\\/', 'App\\Modules\\$1\\', $class);
        }

        return $class;
    }

    /**
     * Load cache from file
     */
    private function loadCache(): array
    {
        if (!file_exists(self::CACHE_FILE)) {
            return [
                'class_map' => [],
                'metadata' => [
                    'last_scan' => 0,
                    'version' => 1,
                ],
            ];
        }

        try {
            $cache = include self::CACHE_FILE;
            if (is_array($cache) && isset($cache['class_map']) && isset($cache['metadata'])) {
                return $cache;
            }
        } catch (\Exception $e) {
            
        }

        return [
            'class_map' => [],
            'metadata' => [
                'last_scan' => 0,
                'version' => 1,
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
    }

    /**
     * Remove stale cache entries (files that no longer exist)
     */
    private function cleanupStaleEntries(array &$cache, array $scannedFiles): void
    {
        $scannedFilesSet = array_flip($scannedFiles);
        $hasChanges = false;

        foreach ($cache['class_map'] ?? [] as $className => $metadata) {
            $filepath = $metadata['file'] ?? '';
            
            if (!file_exists($filepath) || !isset($scannedFilesSet[$filepath])) {
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
    }

    /**
     * Get the cache file path
     */
    public function getCacheFilePath(): string
    {
        return self::CACHE_FILE;
    }
}

