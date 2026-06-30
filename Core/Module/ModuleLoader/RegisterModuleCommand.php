<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\CLI\Application;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Attributes\Command as CommandAttr;
use Forge\CLI\Attributes\CoreCommand;
use Forge\CLI\Command;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\Logger;
use Forge\Core\Helpers\ModuleHelper;
use Forge\Core\Module\Helpers\ModuleFileDiscovery;
use Forge\Core\Module\ModuleLoader\Loader;
use Forge\Core\Services\AttributeDiscoveryService;
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
        $moduleBasePath = dirname($modulePath, 1);

        $cliApplication = $this->container->get(Application::class);
        $registeredClasses = [];
        $registeredCount = 0;

        // 1. Attribute-based discovery: any class under modules/{Module}/src with #[Cli] or #[Command]
        if ($moduleName && is_dir($moduleBasePath . '/src')) {
            $discoveryService = new AttributeDiscoveryService();
            $classMap = $discoveryService->discover(["modules/$moduleName/src"], [Cli::class, CommandAttr::class]);

            foreach ($classMap as $className => $metadata) {
                if (!in_array(Cli::class, $metadata['attributes'] ?? [], true) && !in_array(CommandAttr::class, $metadata['attributes'] ?? [], true)) {
                    continue;
                }

                if (!str_starts_with($className, $moduleNamespace . '\\')) {
                    continue;
                }

                if (!is_subclass_of($className, Command::class)) {
                    continue;
                }

                $commandAttribute = (new ReflectionClass($className))->getAttributes(CommandAttr::class)[0] ?? (new ReflectionClass($className))->getAttributes(Cli::class)[0] ?? null;
                if (!$commandAttribute) {
                    continue;
                }

                $hasCoreCommand = !empty((new ReflectionClass($className))->getAttributes(CoreCommand::class));
                $prefix = $hasCoreCommand ? '' : 'modules:';
                $cliApplication->registerCommandClass($className, $prefix);
                $registeredClasses[$className] = true;
                $registeredCount++;
            }
        }

        // 2. Legacy folder fallback: module src/Commands/ (or configured commands path)
        $searchPath = $this->resolveModuleCommandsPath($moduleName, $modulePath);
        $commandFiles = ModuleFileDiscovery::discoverCommandFilesInModule($searchPath, $moduleNamespace);

        foreach ($commandFiles as $file) {
            $className = $file['className'];
            if (isset($registeredClasses[$className])) {
                continue;
            }

            if (class_exists($className) && is_subclass_of($className, Command::class)) {
                $reflection = ModuleFileDiscovery::getReflectionClass($className);
                $commandAttribute = $reflection->getAttributes(CommandAttr::class)[0] ?? $reflection->getAttributes(Cli::class)[0] ?? null;
                if ($commandAttribute) {
                    $commandInstance = $commandAttribute->newInstance();
                    $commandName = $commandInstance->command;

                    $hasCoreCommand = !empty(ModuleFileDiscovery::getReflectionClass($className)->getAttributes(CoreCommand::class));
                    $prefix = $hasCoreCommand ? '' : 'modules:';
                    $cliApplication->registerCommandClass($className, $prefix);
                    $registeredClasses[$className] = true;
                    $registeredCount++;
                }
            } else {
                Logger::log("Class " . $className . " is not a valid CLI Command.");
            }
        }

        if ($registeredCount > 0 && $moduleName && $this->container->has(Loader::class)) {
            $this->container->get(Loader::class)->recordModuleHasCommands($moduleName);
        }
    }

    private function resolveModuleCommandsPath(?string $moduleName, string $modulePath): string
    {
        $structureResolver = $this->container->has(StructureResolver::class)
            ? $this->container->get(StructureResolver::class)
            : null;

        if ($moduleName && $structureResolver) {
            try {
                $moduleCommandsPath = $structureResolver->getModulePath($moduleName, 'commands');
                $moduleBasePath = dirname($modulePath, 1);
                $commandsPath = "$moduleBasePath/$moduleCommandsPath";
                if (FileExistenceCache::isDir($commandsPath)) {
                    return $commandsPath;
                }
            } catch (\InvalidArgumentException $e) {
                Logger::log("RegisterModuleCommand: failed to resolve commands path for module '{$moduleName}'", $e->getMessage());
            }
        }

        return $modulePath;
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
