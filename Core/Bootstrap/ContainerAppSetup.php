<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\CLI\Application;
use Forge\Core\Config\Config;
use Forge\Core\DI\Container;
use Forge\Core\Module\HookManager;
use Forge\Core\Module\LifecycleHookName;
use Forge\Core\Module\ModuleLoader\Loader;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;
use ReflectionException;

final class ContainerAppSetup
{
  private static bool $containerLoaded = false;

  /**
   * @throws ReflectionException
   * @throws MissingServiceException
   * @throws ResolveParameterException
   */
  public static function initOnce(): Container
  {
    if (!self::$containerLoaded) {
      $container = self::setup();
      self::$containerLoaded = true;
      return $container;
    }
    return Container::getInstance();
  }

  /**
   * @throws ReflectionException
   * @throws MissingServiceException
   * @throws ResolveParameterException
   */
  public static function setup(): Container
  {
    $container = Container::getInstance();
    HelperDiscoverSetup::setup();

    $container->singleton(Config::class, function () {
      return new Config(BASE_PATH . '/config');
    });

    $container->singleton(Application::class, function () use ($container) {
      return Application::getInstance($container);
    });

    SessionSetup::setup($container);

    $container->singleton(Loader::class, function () use ($container) {
      return new Loader(
        container: $container,
        config: $container->get(Config::class)
      );
    });

    $moduleLoader = $container->get(Loader::class);
    $moduleLoader->discoverEarlyHooks();

    HookManager::setContainer($container);

    HookManager::triggerHook(LifecycleHookName::EARLY_BOOT);

    ModuleSetup::loadModules($container);
    ErrorHandlerSetup::setup($container);
    ServiceDiscoverSetup::setup($container);
    AppCommandSetup::getInstance($container);

    HookManager::triggerHook(LifecycleHookName::APP_BOOTED);

    // Mark bootstrap as complete to enable cache wrapping
    $container->finishBootstrap();

    return $container;
  }
}
