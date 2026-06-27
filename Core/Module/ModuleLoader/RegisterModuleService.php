<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\Core\DI\Attributes\Injectable;
use Forge\Core\DI\Attributes\Service;
use Forge\Core\DI\Container;
use Forge\Core\Module\Helpers\ModuleFileDiscovery;
use Forge\Traits\NamespaceHelper;
use ReflectionClass;

final class RegisterModuleService
{
  use NamespaceHelper;

  public function __construct(private readonly Container $container, private readonly ReflectionClass $reflectionClass)
  {
  }

  /**
   * @throws \ReflectionException
   */
  public function init(): void
  {
    $this->registerModuleServices();
  }

  /**
   * @throws \ReflectionException
   */
  private function registerModuleServices(): void
  {
    $moduleNamespace = $this->reflectionClass->getNamespaceName();
    $modulePath = dirname($this->reflectionClass->getFileName());

    $files = ModuleFileDiscovery::discoverPhpFilesInModule($modulePath, $moduleNamespace);

    foreach ($files as $file) {
      $className = $file['className'];
      if (class_exists($className, false)) {
        $classReflection = ModuleFileDiscovery::getReflectionClass($className);
        if ($classReflection->getAttributes(Injectable::class) || $classReflection->getAttributes(Service::class)) {
          $this->container->register($className);
        }
      }
    }
  }
}
