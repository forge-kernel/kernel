<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\Core\Helpers\Framework;
use Forge\Core\Module\Attributes\Compatibility;
use Forge\Core\Module\Attributes\Module;
use Forge\Traits\NamespaceHelper;
use RuntimeException;
use ReflectionClass;

final class RegisterModuleCompatibility
{
    use NamespaceHelper;

    public function __construct(private readonly ReflectionClass $reflectionClass, private readonly Module $moduleAttributeInstance)
    {
    }

    public function init(): void
    {
        $this->initModuleCompatibility();
    }

    private function initModuleCompatibility(): void
    {
        $compatibilityAttribute = $this->reflectionClass->getAttributes(Compatibility::class)[0] ?? null;
        if ($compatibilityAttribute) {
            $compatibilityInstance = $compatibilityAttribute->newInstance();
            $frameworkCompatibility = $compatibilityInstance->framework;
            $phpCompatibility = $compatibilityInstance->php;

            if ($frameworkCompatibility) {
                $currentFrameworkVersion = Framework::version();
                if (!Framework::isVersionCompatible($currentFrameworkVersion, $frameworkCompatibility)) {
                    throw new RuntimeException(
                        "Module '{$this->moduleAttributeInstance->name}' is not compatible with the current framework version. " .
                        "Requires framework version: {$frameworkCompatibility}, current version: " . Framework::version()
                    );
                }
            }

            if ($phpCompatibility) {
                $currentPhpVersion = PHP_VERSION;
                if (!Framework::isVersionCompatible($currentPhpVersion, $phpCompatibility)) {
                    throw new RuntimeException(
                        "Module '{$this->moduleAttributeInstance->name}' requires PHP version {$phpCompatibility} or higher. " .
                        "Your current PHP version is " . $currentPhpVersion
                    );
                }
            }
        }
    }
}
