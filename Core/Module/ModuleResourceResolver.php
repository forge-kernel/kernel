<?php
declare(strict_types=1);

namespace Forge\Core\Module;

final class ModuleResourceResolver
{
    public static function parse(string $name): ModuleResourceReference
    {
        if (!str_contains($name, ':')) {
            return new ModuleResourceReference(
                module: null,
                name: $name
            );

        }

        [$module, $resource] = explode(':', $name, 2);
        return new ModuleResourceReference(
            module: $module,
            name: $resource
        );
    }
}