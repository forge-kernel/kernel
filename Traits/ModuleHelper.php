<?php

declare(strict_types=1);

namespace Forge\Traits;

use RuntimeException;

trait ModuleHelper
{
    private function checkModuleRequirements(string $moduleName): void
    {
        if (!isset($this->moduleRequirements[$moduleName])) {
            return;
        }

        $requirements = $this->moduleRequirements[$moduleName];

        foreach ($requirements['interfaces'] ?? [] as $interface => $version) {
            if (!$this->container->has($interface)) {
                throw new RuntimeException(
                    "Module '{$moduleName}' requires service '{$interface}' (version {$version}) which is not provided."
                );
            }
        }

        foreach ($requirements['modules'] ?? [] as $requiredModule => $versionConstraint) {
            $moduleDirName = $this->nameToPascalCase($requiredModule);
            $moduleRoot = \Forge\Core\Structure\StructureResolver::findModuleRoot(BASE_PATH, $moduleDirName);
            if ($moduleRoot === null) {
                throw new RuntimeException(
                    "Module '{$moduleName}' requires module '{$requiredModule}' (constraint: {$versionConstraint}) which is not installed."
                );
            }
        }

        unset($this->moduleRequirements[$moduleName]);
    }

    private function nameToPascalCase(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    }

    private function normalizeViewPath(string $name): string
    {
        $parts = preg_split('#[/:]#', $name);
        array_shift($parts);
        $parts = array_map(fn($p) => strtolower($p), $parts);
        return implode('/', $parts);
    }
}
