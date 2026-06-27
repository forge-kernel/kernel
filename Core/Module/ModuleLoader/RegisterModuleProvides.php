<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\Core\DI\Attributes\Injectable;
use Forge\Core\DI\Attributes\Service;
use Forge\Core\DI\Container;
use Forge\Core\Module\Attributes\Provides;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Module\Helpers\ModuleFileDiscovery;
use Forge\Traits\NamespaceHelper;
use ReflectionClass;

final class RegisterModuleProvides
{
    use NamespaceHelper;

    public function __construct(private readonly Container $container, private readonly ReflectionClass $reflectionClass)
    {
    }

    public function init(): void
    {
        $this->initModuleProvides();
        $this->initServiceProvides();
    }

    /**
     * Scan the module class itself for #[Provides] attributes.
     */
    private function initModuleProvides(): void
    {
        foreach ($this->reflectionClass->getAttributes(Provides::class) as $attribute) {
            $provideInstance = $attribute->newInstance();
            $this->container->bind($provideInstance->interface, $this->reflectionClass->getName());
        }
    }

    /**
     * Scan service classes within the module for #[Provides] attributes.
     * This ensures that services with #[Provides] attributes are automatically
     * registered, similar to how RegisterModuleService works.
     */
    private function initServiceProvides(): void
    {
        $moduleNamespace = $this->reflectionClass->getNamespaceName();
        $modulePath = dirname($this->reflectionClass->getFileName());

        $files = ModuleFileDiscovery::discoverPhpFilesInModule($modulePath, $moduleNamespace);

        foreach ($files as $file) {
            $className = $file['className'];

            if (!class_exists($className, false)) {
                if (FileExistenceCache::exists($file['path'])) {
                    try {
                        require_once $file['path'];
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }

            if (!class_exists($className)) {
                continue;
            }

            try {
                $classReflection = ModuleFileDiscovery::getReflectionClass($className);
                if ($classReflection->getAttributes(Injectable::class) || $classReflection->getAttributes(Service::class)) {
                    foreach ($classReflection->getAttributes(Provides::class) as $attribute) {
                        $provideInstance = $attribute->newInstance();
                        $this->container->bind($provideInstance->interface, $className);
                    }
                }
            } catch (\ReflectionException $e) {
                continue;
            }
        }
    }
}
