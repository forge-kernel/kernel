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

        foreach ($requirements as $interface => $version) {
            if (!$this->container->has($interface)) {
                throw new RuntimeException(
                    "Module '{$moduleName}' requires service '{$interface}' (version {$version}) which is not provided."
                );
            }
        }

        unset($this->moduleRequirements[$moduleName]);
    }

    private function normalizeViewPath(string $name): string
    {
        $parts = preg_split('#[/:]#', $name);
        array_shift($parts);
        $parts = array_map(fn($p) => strtolower($p), $parts);
        return implode('/', $parts);
    }
}
