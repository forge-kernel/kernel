<?php

declare(strict_types=1);

namespace Forge\Core\Structure;

use Forge\Core\DI\Attributes\Injectable;

#[Injectable]
final class StructureResolver
{
    private const string INTERNAL_STRUCTURE_PATH = __DIR__ . '/forge_structure.php';
    private const string USER_STRUCTURE_PATH_1 = BASE_PATH . '/forge_structure.php';

    private ?array $internalStructure = null;
    private ?array $userStructure = null;
    private array $appStructure = [];
    private array $moduleStructures = [];
    private array $moduleAttributeStructures = [];

    public function getAppPaths(string $type): array
    {
        if (empty($this->appStructure)) {
            $this->loadAppStructure();
        }

        if (!isset($this->appStructure[$type])) {
            throw new \InvalidArgumentException("Unknown structure type: {$type}");
        }

        return self::normalizeToArray($this->appStructure[$type]);
    }

    public function getAppPath(string $type): string
    {
        return $this->getAppPaths($type)[0];
    }

    public function getModulePaths(string $module, string $type): array
    {
        if (!isset($this->moduleStructures[$module])) {
            $this->loadModuleStructure($module);
        }

        $moduleStructure = $this->moduleStructures[$module];

        if (!isset($moduleStructure[$type])) {
            throw new \InvalidArgumentException("Unknown structure type: {$type} for module: {$module}");
        }

        return self::normalizeToArray($moduleStructure[$type]);
    }

    public function getModulePath(string $module, string $type): string
    {
        return $this->getModulePaths($module, $type)[0];
    }

    public function registerModuleStructure(string $module, array $structure): void
    {
        $this->moduleAttributeStructures[$module] = $structure;
        unset($this->moduleStructures[$module]);
    }

    private function loadAppStructure(): void
    {
        $internal = $this->getInternalStructure();
        $user = $this->getUserStructure();

        $this->appStructure = $internal['app'] ?? [];

        if ($user !== null && isset($user['app'])) {
            $this->appStructure = array_merge($this->appStructure, $user['app']);
        }
    }

    private function loadModuleStructure(string $module): void
    {
        if (isset($this->moduleAttributeStructures[$module])) {
            $moduleAttr = $this->moduleAttributeStructures[$module];
            $internal = $this->getInternalStructure();
            $internalModules = $internal['modules'] ?? [];

            $this->moduleStructures[$module] = array_merge($internalModules, $moduleAttr);
            return;
        }

        $internal = $this->getInternalStructure();
        $user = $this->getUserStructure();

        $this->moduleStructures[$module] = $internal['modules'] ?? [];

        if ($user !== null && isset($user['modules'])) {
            $this->moduleStructures[$module] = array_merge(
                $this->moduleStructures[$module],
                $user['modules']
            );
        }
    }

    private function getInternalStructure(): array
    {
        if ($this->internalStructure !== null) {
            return $this->internalStructure;
        }

        if (!file_exists(self::INTERNAL_STRUCTURE_PATH)) {
            throw new \RuntimeException("Internal structure file not found: " . self::INTERNAL_STRUCTURE_PATH);
        }

        $this->internalStructure = require self::INTERNAL_STRUCTURE_PATH;

        if (!is_array($this->internalStructure)) {
            throw new \RuntimeException("Internal structure file must return an array");
        }

        return $this->internalStructure;
    }

    private function getUserStructure(): ?array
    {
        if ($this->userStructure !== null) {
            return $this->userStructure;
        }

        $userPath = null;
        if (file_exists(self::USER_STRUCTURE_PATH_1)) {
            $userPath = self::USER_STRUCTURE_PATH_1;
        }

        if ($userPath === null) {
            $this->userStructure = null;
            return null;
        }

        $structure = require $userPath;

        if (!is_array($structure)) {
            throw new \RuntimeException("User structure file must return an array: {$userPath}");
        }

        $this->userStructure = $structure;
        return $this->userStructure;
    }

    private static ?string $resolvedModulesRoot = null;
    private static ?string $resolvedModulesNamespace = null;

    public static function resolveModulesRoot(): string
    {
        if (self::$resolvedModulesRoot !== null) {
            return self::$resolvedModulesRoot;
        }

        $config = require self::INTERNAL_STRUCTURE_PATH;
        $root = $config['modules_root'] ?? 'modules';

        if (defined('BASE_PATH')) {
            $userPath = BASE_PATH . '/forge_structure.php';
            if (file_exists($userPath)) {
                $userConfig = require $userPath;
                if (isset($userConfig['modules_root'])) {
                    $root = $userConfig['modules_root'];
                }
            }
        }

        self::$resolvedModulesRoot = is_array($root) ? $root[0] : $root;
        return self::$resolvedModulesRoot;
    }

    public static function resolveModulesNamespace(): string
    {
        if (self::$resolvedModulesNamespace !== null) {
            return self::$resolvedModulesNamespace;
        }

        $config = require self::INTERNAL_STRUCTURE_PATH;
        $ns = $config['modules_namespace'] ?? 'Modules';

        if (defined('BASE_PATH')) {
            $userPath = BASE_PATH . '/forge_structure.php';
            if (file_exists($userPath)) {
                $userConfig = require $userPath;
                if (isset($userConfig['modules_namespace'])) {
                    $ns = $userConfig['modules_namespace'];
                }
            }
        }

        self::$resolvedModulesNamespace = is_array($ns) ? $ns[0] : $ns;
        return self::$resolvedModulesNamespace;
    }

    public function getModulesRoot(): string
    {
        $internal = $this->getInternalStructure();
        $root = $internal['modules_root'] ?? 'modules';

        $user = $this->getUserStructure();
        if ($user !== null && isset($user['modules_root'])) {
            $root = $user['modules_root'];
        }

        return is_array($root) ? $root[0] : $root;
    }

    public function getModulesNamespace(): string
    {
        $internal = $this->getInternalStructure();
        $ns = $internal['modules_namespace'] ?? 'Modules';

        $user = $this->getUserStructure();
        if ($user !== null && isset($user['modules_namespace'])) {
            $ns = $user['modules_namespace'];
        }

        return $ns;
    }

    public function getModuleEntryFiles(): array
    {
        $internal = $this->getInternalStructure();
        $files = $internal['module_entry_files'] ?? ['{name}Module.php', '{name}.php'];

        $user = $this->getUserStructure();
        if ($user !== null && isset($user['module_entry_files'])) {
            $files = $user['module_entry_files'];
        }

        return is_array($files) ? $files : [$files];
    }

    public function getModuleEntryGlob(): string
    {
        $internal = $this->getInternalStructure();
        $glob = $internal['module_entry_glob'] ?? '*Module.php';

        $user = $this->getUserStructure();
        if ($user !== null && isset($user['module_entry_glob'])) {
            $glob = $user['module_entry_glob'];
        }

        return $glob;
    }

    public function findModuleEntryFile(string $modulesPath, string $moduleName): ?string
    {
        $patterns = $this->getModuleEntryFiles();
        $srcDir = $modulesPath . '/' . $moduleName . '/src';

        foreach ($patterns as $pattern) {
            $file = $srcDir . '/' . str_replace('{name}', $moduleName, $pattern);
            if (file_exists($file)) {
                return $file;
            }
        }

        $glob = $this->getModuleEntryGlob();
        $files = glob($srcDir . '/' . $glob);
        if (!empty($files)) {
            return $files[0];
        }

        return null;
    }

    public static function findModuleEntryFileStatic(string $modulesPath, string $moduleName): ?string
    {
        $config = require self::INTERNAL_STRUCTURE_PATH;
        $patterns = $config['module_entry_files'] ?? ['{name}Module.php', '{name}.php'];
        $glob = $config['module_entry_glob'] ?? '*Module.php';

        if (defined('BASE_PATH')) {
            $userPath = BASE_PATH . '/forge_structure.php';
            if (file_exists($userPath)) {
                $userConfig = require $userPath;
                if (isset($userConfig['module_entry_files'])) {
                    $patterns = $userConfig['module_entry_files'];
                }
                if (isset($userConfig['module_entry_glob'])) {
                    $glob = $userConfig['module_entry_glob'];
                }
            }
        }

        $patterns = is_array($patterns) ? $patterns : [$patterns];
        $srcDir = $modulesPath . '/' . $moduleName . '/src';

        foreach ($patterns as $pattern) {
            $file = $srcDir . '/' . str_replace('{name}', $moduleName, $pattern);
            if (file_exists($file)) {
                return $file;
            }
        }

        $files = glob($srcDir . '/' . $glob);
        if (!empty($files)) {
            return $files[0];
        }

        return null;
    }

    public function getFullAppStructure(): array
    {
        if (empty($this->appStructure)) {
            $this->loadAppStructure();
        }
        return $this->appStructure;
    }

    public function getFullModuleStructure(string $module): array
    {
        if (!isset($this->moduleStructures[$module])) {
            $this->loadModuleStructure($module);
        }
        return $this->moduleStructures[$module];
    }

    public function getAppNamespace(string $type, ?string $specificPath = null): string
    {
        $path = $specificPath ?? $this->getAppPath($type);

        if (str_starts_with($path, 'app/')) {
            $path = substr($path, 4);
        } elseif (str_starts_with($path, 'src/')) {
            $path = substr($path, 4);
        }

        return 'App\\' . $this->pathToNamespace($path);
    }

    public function getModuleNamespace(string $module, string $type, ?string $specificPath = null): string
    {
        $path = $specificPath ?? $this->getModulePath($module, $type);

        if (str_starts_with($path, 'src/')) {
            $path = substr($path, 4);
        }

        return $this->getModulesNamespace() . '\\' . $module . '\\' . $this->pathToNamespace($path);
    }

    private static function normalizeToArray(string|array $value): array
    {
        return is_array($value) ? array_values($value) : [$value];
    }

    private function pathToNamespace(string $path): string
    {
        $parts = explode('/', $path);
        $parts = array_map(function (string $part) {
            return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part)));
        }, $parts);

        return implode('\\', $parts);
    }
}
