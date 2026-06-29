<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\Core\Module\Attributes\Requires;
use Forge\Traits\NamespaceHelper;
use ReflectionClass;

final class RegisterModuleRequires
{
    use NamespaceHelper;

    public function __construct(private readonly ReflectionClass $reflectionClass, private array $moduleRequirements)
    {
    }

    public function init(): void
    {
        $this->initModuleRequires();
    }

    private function initModuleRequires(): void
    {
        $moduleName = $this->reflectionClass->getShortName();
        foreach ($this->reflectionClass->getAttributes(Requires::class) as $attribute) {
            $instance = $attribute->newInstance();
            if ($instance->interface !== null) {
                $this->moduleRequirements[$moduleName]['interfaces'][$instance->interface] = $instance->version;
            }
            if ($instance->module !== null) {
                $this->moduleRequirements[$moduleName]['modules'][$instance->module] = $instance->version;
            }
        }
    }
}
