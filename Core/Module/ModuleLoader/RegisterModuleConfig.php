<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\Core\Config\Config;
use Forge\Core\Module\Attributes\ConfigDefaults;
use ReflectionClass;

final readonly class RegisterModuleConfig
{
    public function __construct(private Config $config, private ReflectionClass $reflectionClass)
    {
    }

    public function init(): void
    {
        $this->registerModuleConfig();
    }

    private function registerModuleConfig(): void
    {
        $configDefaultsAttribute = $this->reflectionClass->getAttributes(ConfigDefaults::class)[0] ?? null;
        if ($configDefaultsAttribute) {
            $configDefaults = $configDefaultsAttribute->newInstance()->defaults;
            $this->config->mergeModuleDefaults($configDefaults);
        }
    }
}
