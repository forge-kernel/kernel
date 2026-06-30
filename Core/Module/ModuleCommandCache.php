<?php

declare(strict_types=1);

namespace Forge\Core\Module;

use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\Logger;

final class ModuleCommandCache
{
    private const CACHE_FILE = BASE_PATH . '/storage/framework/cache/module_command_map.php';

    public static function getCacheFile(): string
    {
        return self::CACHE_FILE;
    }

    public static function load(): ?array
    {
        if (!FileExistenceCache::exists(self::CACHE_FILE)) {
            return null;
        }

        try {
            $data = include self::CACHE_FILE;
            if (!is_array($data) || !isset($data['modules_with_commands'])) {
                return null;
            }
            return $data;
        } catch (\Throwable $e) {
            Logger::log("ModuleCommandCache: failed to load cache", $e->getMessage());
            return null;
        }
    }

    public static function isValid(?array $cache = null): bool
    {
        if ($cache === null) {
            $cache = self::load();
        }
        if ($cache === null) {
            return false;
        }

        $moduleMtimes = $cache['module_mtimes'] ?? [];
        foreach ($moduleMtimes as $moduleName => $cachedMtime) {
            $dir = self::getModulesDir() . '/' . $moduleName;
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

    private static function getModulesDir(): string
    {
        static $dir = null;
        if ($dir === null) {
            $dir = BASE_PATH . '/' . \Forge\Core\Structure\StructureResolver::resolveModulesRoot();
        }
        return $dir;
    }

    public static function getModulesWithCommands(): array
    {
        $cache = self::load();
        if ($cache === null || !self::isValid($cache)) {
            return [];
        }
        return $cache['modules_with_commands'] ?? [];
    }

    public static function buildAndSave(array $modulesWithCommands): void
    {
        $moduleMtimes = [];
        foreach ($modulesWithCommands as $moduleName) {
            $dir = self::getModulesDir() . '/' . $moduleName;
            if (is_dir($dir)) {
                $moduleMtimes[$moduleName] = @filemtime($dir) ?: 0;
            }
        }

        $cacheDir = dirname(self::CACHE_FILE);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $data = [
            'modules_with_commands' => array_values(array_unique($modulesWithCommands)),
            'module_mtimes' => $moduleMtimes,
        ];

        $content = '<?php return ' . var_export($data, true) . ';';
        file_put_contents(self::CACHE_FILE, $content);
    }

    public static function clear(): void
    {
        if (FileExistenceCache::exists(self::CACHE_FILE)) {
            @unlink(self::CACHE_FILE);
        }
    }
}
