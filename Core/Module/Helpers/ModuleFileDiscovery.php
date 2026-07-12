<?php

declare(strict_types=1);

namespace Forge\Core\Module\Helpers;

use Forge\Core\Helpers\FileExistenceCache;
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

    public static function getReflectionClass(string $className): ReflectionClass
    {
        if (!isset(self::$reflectionCache[$className])) {
            self::$reflectionCache[$className] = new ReflectionClass($className);
        }
        return self::$reflectionCache[$className];
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

        $pathsToPreload = [];
        $phpFiles = [];

        foreach ($iterator as $file) {
            $relativePath = str_replace($modulePath, '', $file->getPathname());
            if (preg_match('#[/\\\\](tests?)[/\\\\]#i', $relativePath)) {
                continue;
            }

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

    private function getNamespaceFromFile(string $filePath, string $basePath): ?string
    {
        if (!FileExistenceCache::isFile($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

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
}
