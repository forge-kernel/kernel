<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\CLI\Application;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Attributes\Command as CommandAttr;
use Forge\CLI\Attributes\CoreCommand;
use Forge\CLI\Command;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\Logger;
use Forge\Core\Module\Helpers\ModuleFileDiscovery;
use Forge\Core\Module\ModuleLoader\Loader;
use Forge\Exceptions\MissingServiceException;
use ReflectionClass;

final class RegisterModuleCommand
{
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

        $cliApplication = $this->container->get(Application::class);
        $registeredCount = 0;

        $files = ModuleFileDiscovery::discoverCommandFilesInModule($modulePath, $moduleNamespace);

        foreach ($files as $file) {
            if (str_starts_with($file['namespace'], $moduleNamespace . '\\Tests')) {
                continue;
            }

            $fqcn = $file['className'];
            if (!class_exists($fqcn)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($fqcn);
                $hasCommandAttr = !empty($reflection->getAttributes(Cli::class))
                    || !empty($reflection->getAttributes(CommandAttr::class));

                if (!$hasCommandAttr || !$reflection->isSubclassOf(Command::class)) {
                    continue;
                }

                $hasCoreCommand = !empty($reflection->getAttributes(CoreCommand::class));
                $prefix = $hasCoreCommand ? '' : 'modules:';
                $cliApplication->registerCommandClass($fqcn, $prefix);
                $registeredCount++;
            } catch (\ReflectionException $e) {
                continue;
            }
        }

        if ($registeredCount > 0 && $moduleName && $this->container->has(Loader::class)) {
            $this->container->get(Loader::class)->recordModuleHasCommands($moduleName);
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
