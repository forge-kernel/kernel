<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\Core\Module\Attributes\Requires;
use Forge\Traits\NamespaceHelper;
use ReflectionClass;

final class RegisterModuleRequires
{
    use NamespaceHelper;

    public function __construct(
        private readonly ReflectionClass $reflectionClass,
        private readonly string $moduleName,
    ) {
    }

    public function init(array &$moduleRequirements): void
    {
        foreach ($this->reflectionClass->getAttributes(Requires::class) as $attribute) {
            $instance = $attribute->newInstance();
            if ($instance->interface !== null) {
                $moduleRequirements[$this->moduleName]['interfaces'][$instance->interface] = $instance->version;
            }
            if ($instance->module !== null) {
                $moduleRequirements[$this->moduleName]['modules'][$instance->module] = $instance->version;
            }
        }
    }
}
