<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\Core\DI\Container;
use Forge\Core\Helpers\Logger;
use Forge\Core\Module\Attributes\LifecycleHook;
use Forge\Core\Module\Attributes\Module;
use Forge\Core\Module\HookManager;
use Forge\Core\Module\LifecycleHookName;
use Forge\Core\Module\ModuleLoader\Loader;
use Forge\Exceptions\MissingServiceException;
use Forge\Traits\NamespaceHelper;
use ReflectionClass;

final class RegisterModuleHooks
{
  use NamespaceHelper;

  public function __construct(private readonly Container $container, private readonly ReflectionClass $reflectionClass)
  {
  }

  /**
   * @throws MissingServiceException
   */
  public function init(): void
  {
    $this->initModuleHooks();
  }

  /**
   * @throws MissingServiceException
   */
  private function initModuleHooks(): void
  {
    $moduleAttribute = $this->reflectionClass->getAttributes(Module::class)[0] ?? null;
    if ($moduleAttribute) {
      $moduleAttributeInstance = $moduleAttribute->newInstance();
      $moduleName = $moduleAttributeInstance->name;

      $moduleInstance = $this->container->make($this->reflectionClass->getName());
      $className = $this->reflectionClass->getName();

      $loader = null;
      try {
        if ($this->container->has(Loader::class)) {
          $loader = $this->container->get(Loader::class);
        }
      } catch (\Throwable $e) {
        Logger::log("RegisterModuleHooks: failed to get Loader from container", $e->getMessage());
      }

      foreach ($this->reflectionClass->getMethods() as $method) {
        $lifecycleHookAttributes = $method->getAttributes(LifecycleHook::class);
        foreach ($lifecycleHookAttributes as $attribute) {
          $hookInstance = $attribute->newInstance();
          $hookName = $hookInstance->hook;
          $methodName = $method->getName();

          if (
            $loader && in_array($hookName, [
              LifecycleHookName::EARLY_BOOT,
              LifecycleHookName::BEFORE_MODULE_LOAD
            ], true)
          ) {
            if ($loader->wasHookRegisteredEarly($className, $methodName, $hookName)) {

              continue;
            }
          }

          $callback = [$moduleInstance, $methodName];

          if ($hookInstance->forSelf) {
            $wrappedCallback = function (...$args) use ($moduleName, $callback) {
              $passedModuleName = $args[0] ?? '';
              if ($passedModuleName === $moduleName) {
                call_user_func_array($callback, $args);
              }
            };
            HookManager::addHook($hookName, $wrappedCallback);
          } else {
            HookManager::addHook($hookName, $callback);
          }
        }
      }
    }
  }
}
