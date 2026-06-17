<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\CLI\Application;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Attributes\CoreCommand;
use Forge\CLI\Command;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\ModuleHelper;
use Forge\Core\Module\Helpers\ModuleFileDiscovery;
use Forge\Core\Structure\StructureResolver;
use Forge\Exceptions\MissingServiceException;
use Forge\Traits\NamespaceHelper;
use ReflectionClass;

final class RegisterModuleCommand
{
  use NamespaceHelper;

  public function __construct(private readonly Container $container, private readonly ReflectionClass $reflectionClass)
  {
  }

  public function init(): void
  {
    $this->registerModuleCommands();
  }

  /**
   * @throws MissingServiceException
   */
  private function registerModuleCommands(): void
  {
    $moduleNamespace = $this->reflectionClass->getNamespaceName();
    $modulePath = dirname($this->reflectionClass->getFileName());

    $moduleName = $this->extractModuleName();
    $structureResolver = $this->container->has(StructureResolver::class)
      ? $this->container->get(StructureResolver::class)
      : null;

    $searchPath = $modulePath;
    if ($moduleName && $structureResolver) {
      try {
        $moduleCommandsPath = $structureResolver->getModulePath($moduleName, 'commands');
        $moduleBasePath = dirname($modulePath, 1);
        $commandsPath = "$moduleBasePath/$moduleCommandsPath";
        if (FileExistenceCache::isDir($commandsPath)) {
          $searchPath = $commandsPath;
        }
      } catch (\InvalidArgumentException $e) {
        // Use default modulePath
      }
    }

    $cliApplication = $this->container->get(Application::class);
    $commandFiles = ModuleFileDiscovery::discoverCommandFilesInModule($searchPath, $moduleNamespace);

    foreach ($commandFiles as $file) {
      $className = $file['className'];
      if (class_exists($className) && is_subclass_of($className, Command::class)) {
        $commandAttribute = ModuleFileDiscovery::getReflectionClass($className)->getAttributes(Cli::class)[0] ?? null;
        if ($commandAttribute) {
          $commandInstance = $commandAttribute->newInstance();
          $commandName = $commandInstance->command;

          $hasCoreCommand = !empty(ModuleFileDiscovery::getReflectionClass($className)->getAttributes(CoreCommand::class));
          $prefix = $hasCoreCommand ? '' : 'modules:';
          $cliApplication->registerCommandClass($className, $prefix);
        }
      } else {
        error_log("Class " . $className . " is not a valid CLI Command.");
      }
    }
  }

  private function extractModuleName(): ?string
  {
    $className = $this->reflectionClass->getName();
    if (preg_match('/App\\\\Modules\\\\([^\\\\]+)\\\\/', $className, $matches)) {
      return $matches[1];
    }
    return null;
  }
}
