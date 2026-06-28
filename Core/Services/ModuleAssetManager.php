<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\DI\Attributes\Injectable;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\ModuleHelper;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use DirectoryIterator;
use SplFileInfo;

#[Injectable]
final class ModuleAssetManager
{
    private const CACHE_FILE = 'module_assets.cache';
    private static array $manifest = [];

    public static function initialize(): void
    {
        if (!empty(self::$manifest)) {
            return;
        }

        $cachePath = BASE_PATH . '/storage/framework/cache/' . self::CACHE_FILE;

        if (FileExistenceCache::exists($cachePath) && !self::shouldRefreshCache()) {
            $cached = file_get_contents($cachePath);
            if ($cached !== false) {
                self::$manifest = unserialize($cached);
                return;
            }
        }

        self::scanModules();
        file_put_contents($cachePath, serialize(self::$manifest));
    }

    private static function shouldRefreshCache(): bool
    {
        // Implement logic to check if modules were updated
        // Could check last modified time of modules directory
        // Or use a versioning system
        return false;
    }

    private static function scanModules(): void
    {
        $modulesPath = BASE_PATH . '/public/assets/modules';

        if (!is_dir($modulesPath)) {
            return;
        } else {
            foreach (new DirectoryIterator($modulesPath) as $module) {
                if ($module->isDot()) {
                    continue;
                }

                $moduleName = $module->getFilename();
                if (ModuleHelper::isModuleDisabled($moduleName)) {
                    continue;
                }

                self::$manifest[$moduleName] = [
                    'css' => self::findAssets($module->getPathname(), 'css'),
                    'js' => self::findAssets($module->getPathname(), 'js'),
                    'images' => self::findAssets($module->getPathname(), 'images')
                ];
            }
        }
    }

    private static function findAssets(string $path, string $type): array
    {
        $assets = [];
        $targetDir = $path . '/' . $type;

        if (!is_dir($targetDir)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($targetDir)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $assets[] = [
                    'path' => str_replace(BASE_PATH . '/public', '', $file->getPathname()),
                    'mtime' => $file->getMTime()
                ];
            }
        }

        return $assets;
    }

    public static function getStyles(string $module): array
    {
        return self::$manifest[$module]['css'] ?? [];
    }

    public static function getScripts(string $module): array
    {
        return self::$manifest[$module]['js'] ?? [];
    }
}
