<?php

declare(strict_types=1);

namespace Forge\Core\Module;

use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\Logger;
use Forge\Core\Structure\StructureResolver;

final class ModuleCommandCache
{
    private const string CACHE_FILE = BASE_PATH . '/storage/framework/cache/module_command_map.php';

    public static function getCacheFile(): string
    {
        return self::CACHE_FILE;
    }

    public static function load(): ?array
    {
        FileExistenceCache::clearPath(self::CACHE_FILE);
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
            $dir = BASE_PATH . '/' . StructureResolver::resolveModulesRoot();
        }
        return $dir;
    }

    public static function getModulesWithCommands(): array
    {
        $cache = self::load();
        if ($cache === null || !self::isValid($cache)) {
            return [];
        }
        return array_keys($cache['modules_with_commands'] ?? []);
    }

    public static function getCommandsForModule(string $moduleName): array
    {
        $cache = self::load();
        if ($cache === null || !self::isValid($cache)) {
            return [];
        }
        return $cache['modules_with_commands'][$moduleName] ?? [];
    }

    public static function build(StructureResolver $structureResolver, array $moduleDirectories): array
    {
        $modulesWithCommands = [];
        $moduleMtimes = [];

        foreach ($moduleDirectories as $name => $path) {
            try {
                $commandsPaths = $structureResolver->getModulePaths($name, 'commands');
            } catch (\InvalidArgumentException) {
                $commandsPaths = ['src/Commands'];
            }

            foreach ($commandsPaths as $commandsPath) {
                $dir = $path . '/' . $commandsPath;
                if (!is_dir($dir)) {
                    continue;
                }

                $files = glob($dir . '/*Command.php');
                if (empty($files)) {
                    continue;
                }

                foreach ($files as $file) {
                    $content = file_get_contents($file);
                    if ($content === false) {
                        continue;
                    }

                    if (!str_contains($content, '#[Cli') && !str_contains($content, '#[Command(')) {
                        continue;
                    }

                    if (preg_match('/^namespace\s+([^;]+);/m', $content, $nsMatch)
                        && preg_match('/^(final\s+)?class\s+(\w+)/m', $content, $classMatch)) {
                        $fqcn = trim($nsMatch[1]) . '\\' . $classMatch[2];
                        $modulesWithCommands[$name][] = $fqcn;
                    }
                }
            }

            if (is_dir($path)) {
                $moduleMtimes[$name] = @filemtime($path) ?: 0;
            }
        }

        return [
            'modules_with_commands' => $modulesWithCommands,
            'module_mtimes' => $moduleMtimes,
        ];
    }

    public static function buildAndSave(StructureResolver $structureResolver, array $moduleDirectories): void
    {
        $data = self::build($structureResolver, $moduleDirectories);

        $cacheDir = dirname(self::CACHE_FILE);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $content = '<?php return ' . var_export($data, true) . ';';
        file_put_contents(self::CACHE_FILE, $content);
        FileExistenceCache::clearPath(self::CACHE_FILE);
    }

    public static function clear(): bool
    {
        if (FileExistenceCache::exists(self::CACHE_FILE)) {
            @unlink(self::CACHE_FILE);
            return true;
        }
        return false;
    }
}
