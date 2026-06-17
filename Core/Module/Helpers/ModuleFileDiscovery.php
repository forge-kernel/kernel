<?php

declare(strict_types=1);

namespace Forge\Core\Module\Helpers;

use Forge\Core\Helpers\FileExistenceCache;
use Forge\Traits\NamespaceHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

final class ModuleFileDiscovery
{
    private static array $reflectionCache = [];
    private static array $fileCache = [];
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function clearCache(): void
    {
        self::$reflectionCache = [];
        self::$fileCache = [];
    }

    public static function discoverModulesInDirectory(string $moduleDirectory): array
    {
        $cacheKey = md5($moduleDirectory);
        if (isset(self::$fileCache[$cacheKey])) {
            return self::$fileCache[$cacheKey];
        }

        if (!FileExistenceCache::isDir($moduleDirectory)) {
            return [];
        }

        $allItems = scandir($moduleDirectory);
        if ($allItems === false) {
            return [];
        }

        $itemsToCheck = array_map(
            fn($item) => "$moduleDirectory/$item",
            array_filter($allItems, fn($item) => $item !== '.' && $item !== '..')
        );

        if (!empty($itemsToCheck)) {
            FileExistenceCache::preload($itemsToCheck);
        }

        $directories = array_filter(
            $allItems,
            fn($item) => $item !== '.' && $item !== '..' && FileExistenceCache::isDir("$moduleDirectory/$item")
        );

        $modules = [];
        foreach ($directories as $directoryName) {
            $modulePath = "$moduleDirectory/$directoryName";
            $srcPath = "$modulePath/src";

            if (!FileExistenceCache::isDir($srcPath)) {
                continue;
            }

            $moduleClass = self::findModuleClass($srcPath);
            if ($moduleClass) {
                $modules[] = $moduleClass;
            }
        }

        self::$fileCache[$cacheKey] = $modules;
        return $modules;
    }

    private static function findModuleClass(string $srcPath): ?array
    {
        $cacheKey = md5($srcPath . '_module_class');
        if (isset(self::$fileCache[$cacheKey])) {
            return self::$fileCache[$cacheKey];
        }
        
        if (!FileExistenceCache::isDir($srcPath)) {
            self::$fileCache[$cacheKey] = null;
            return null;
        }

        $directoryIterator = new RecursiveDirectoryIterator($srcPath);
        $iterator = new RecursiveIteratorIterator($directoryIterator);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getRealPath();
                $className = self::extractClassNameFromFile($filePath);

                if ($className === null) {
                    continue;
                }

                try {

                    if (!class_exists($className, false)) {
                        if (FileExistenceCache::exists($filePath)) {
                            require_once $filePath;
                        }
                    }

                    if (class_exists($className)) {
                        $reflectionClass = self::getReflectionClass($className);
                        $attributes = $reflectionClass->getAttributes(\Forge\Core\Module\Attributes\Module::class);
                        if (!empty($attributes)) {
                            $moduleInstance = $attributes[0]->newInstance();
                            $result = [
                                'name' => $className,
                                'order' => $moduleInstance->order ?? 999,
                                'path' => dirname($srcPath),
                            ];
                            self::$fileCache[$cacheKey] = $result;
                            return $result;
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        self::$fileCache[$cacheKey] = null;
        return null;
    }

    private static function extractClassNameFromFile(string $filePath): ?string
    {
        if (!FileExistenceCache::isFile($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        if (!str_contains($content, '#[Module') && !str_contains($content, '@Attributes\Module')) {
            return null;
        }

        if (!preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
            return null;
        }

        if (!preg_match('/(?:class|enum|trait|interface)\s+(\w+)/', $content, $classMatch)) {
            return null;
        }

        return trim($namespaceMatch[1]) . '\\' . $classMatch[1];
    }

    public static function getReflectionClass(string $className): ReflectionClass
    {
        if (!isset(self::$reflectionCache[$className])) {
            self::$reflectionCache[$className] = new ReflectionClass($className);
        }
        return self::$reflectionCache[$className];
    }

    public static function preloadAllModuleFiles(array $modules): void
    {
        $allPaths = [];
        foreach ($modules as $module) {
            $modulePath = $module['path'];
            $srcPath = "$modulePath/src";

            if (FileExistenceCache::isDir($srcPath)) {
                $files = self::discoverPhpFilesInModule($srcPath, '');
                foreach ($files as $file) {
                    $allPaths[] = $file['path'];
                }
            }
        }

        if (!empty($allPaths)) {
            FileExistenceCache::preload($allPaths);
        }
    }

    public static function discoverPhpFilesInModule(string $modulePath, string $moduleNamespace): array
    {
        $cacheKey = md5($modulePath . $moduleNamespace);
        if (isset(self::$fileCache[$cacheKey])) {
            return self::$fileCache[$cacheKey];
        }

        if (!FileExistenceCache::isDir($modulePath)) {
            self::$fileCache[$cacheKey] = [];
            return [];
        }

        $directoryIterator = new RecursiveDirectoryIterator($modulePath);
        $iterator = new RecursiveIteratorIterator($directoryIterator);

        // First pass: collect all PHP file paths for preloading and basic file info
        $pathsToPreload = [];
        $phpFiles = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getRealPath();
                $pathsToPreload[] = $filePath;
                $phpFiles[] = [
                    'path' => $filePath,
                    'filename' => $file->getFilename(),
                ];
            }
        }

        if (!empty($pathsToPreload)) {
            FileExistenceCache::preload($pathsToPreload);
        }

        $files = [];
        foreach ($phpFiles as $phpFile) {
            $fileNamespace = self::getInstance()->getNamespaceFromFile($phpFile['path'], BASE_PATH);
            if ($fileNamespace !== null && str_starts_with($fileNamespace, $moduleNamespace)) {
                $files[] = [
                    'path' => $phpFile['path'],
                    'namespace' => $fileNamespace,
                    'className' => $fileNamespace . '\\' . pathinfo($phpFile['filename'], PATHINFO_FILENAME),
                    'filename' => $phpFile['filename'],
                ];
            }
        }

        self::$fileCache[$cacheKey] = $files;
        return $files;
    }

    private function getNamespaceFromFile(string $filePath, string $basePath): ?string
    {
        // Extract namespace from file content
        if (!FileExistenceCache::isFile($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        // Look for namespace declaration
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function discoverModuleFilesLazy(string $modulePath, string $moduleNamespace, ?callable $filter = null): \Generator
    {
        if (!FileExistenceCache::isDir($modulePath)) {
            return;
        }

        $directoryIterator = new RecursiveDirectoryIterator($modulePath);
        $iterator = new RecursiveIteratorIterator($directoryIterator);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getRealPath();
                $fileNamespace = self::getInstance()->getNamespaceFromFile($filePath, BASE_PATH);

                if ($fileNamespace !== null && str_starts_with($fileNamespace, $moduleNamespace)) {
                    $fileInfo = [
                        'path' => $filePath,
                        'namespace' => $fileNamespace,
                        'className' => $fileNamespace . '\\' . pathinfo($file->getFilename(), PATHINFO_FILENAME),
                        'filename' => $file->getFilename(),
                    ];

                    if ($filter === null || $filter($fileInfo)) {
                        yield $fileInfo;
                    }
                }
            }
        }
    }

    public static function getOptimizedModuleData(array $modules): array
    {
        $moduleData = [];
        foreach ($modules as $module) {
            $moduleName = basename($module['path']);
            $modulePath = $module['path'];
            $srcPath = "$modulePath/src";

            $moduleData[$moduleName] = [
                'class' => $module['name'],
                'path' => $modulePath,
                'order' => $module['order'],
                'namespace' => str_replace('\\Module', '', $module['name']),
                'files_preloaded' => false,
            ];

            if (FileExistenceCache::isDir($srcPath)) {
                $files = self::discoverPhpFilesInModule($srcPath, $moduleData[$moduleName]['namespace']);
                $moduleData[$moduleName]['file_count'] = count($files);
                $moduleData[$moduleName]['has_commands'] = !empty(self::discoverCommandFilesInModule($srcPath, $moduleData[$moduleName]['namespace']));
            } else {
                $moduleData[$moduleName]['file_count'] = 0;
                $moduleData[$moduleName]['has_commands'] = false;
            }
        }

        return $moduleData;
    }

    public static function discoverCommandFilesInModule(string $modulePath, string $moduleNamespace): array
    {
        $cacheKey = md5($modulePath . $moduleNamespace . '_commands');
        if (isset(self::$fileCache[$cacheKey])) {
            return self::$fileCache[$cacheKey];
        }

        $allFiles = self::discoverPhpFilesInModule($modulePath, $moduleNamespace);
        $commandFiles = array_filter(
            $allFiles,
            fn($file) => str_ends_with($file['filename'], 'Command.php')
        );

        self::$fileCache[$cacheKey] = $commandFiles;
        return $commandFiles;
    }
}