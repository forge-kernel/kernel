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
            $requireInstance = $attribute->newInstance();
            $this->moduleRequirements[$moduleName][$requireInstance->interface] = $requireInstance->version;
        }
    }
}
