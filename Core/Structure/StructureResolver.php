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
        self::$resolvedModulesRoot = $config['modules_root'] ?? 'modules';

        if (defined('BASE_PATH')) {
            $userPath = BASE_PATH . '/forge_structure.php';
            if (file_exists($userPath)) {
                $userConfig = require $userPath;
                if (isset($userConfig['modules_root'])) {
                    self::$resolvedModulesRoot = $userConfig['modules_root'];
                }
            }
        }

        return self::$resolvedModulesRoot;
    }

    public static function resolveModulesNamespace(): string
    {
        if (self::$resolvedModulesNamespace !== null) {
            return self::$resolvedModulesNamespace;
        }

        $config = require self::INTERNAL_STRUCTURE_PATH;
        self::$resolvedModulesNamespace = $config['modules_namespace'] ?? 'Modules';

        if (defined('BASE_PATH')) {
            $userPath = BASE_PATH . '/forge_structure.php';
            if (file_exists($userPath)) {
                $userConfig = require $userPath;
                if (isset($userConfig['modules_namespace'])) {
                    self::$resolvedModulesNamespace = $userConfig['modules_namespace'];
                }
            }
        }

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

        return $root;
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

    public function getAppNamespace(string $type): string
    {
        $path = $this->getAppPath($type);

        if (str_starts_with($path, 'app/')) {
            $path = substr($path, 4);
        } elseif (str_starts_with($path, 'src/')) {
            $path = substr($path, 4);
        }

        return 'App\\' . $this->pathToNamespace($path);
    }

    public function getModuleNamespace(string $module, string $type): string
    {
        $path = $this->getModulePath($module, $type);

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
