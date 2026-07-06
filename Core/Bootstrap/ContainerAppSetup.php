<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\CLI\Application;
use Forge\Core\Config\Config;
use Forge\Core\DI\Container;
use Forge\Core\Debug\Metrics;
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

    KernelServiceSetup::register($container);

    Metrics::start("helper_discovery");
    HelperDiscoverSetup::setup();
    Metrics::stop("helper_discovery");

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

    Metrics::start("early_hooks_discovery");
    $moduleLoader->discoverEarlyHooks();
    Metrics::stop("early_hooks_discovery");

    HookManager::setContainer($container);

    Metrics::start("early_boot_trigger");
    HookManager::triggerHook(LifecycleHookName::EARLY_BOOT);
    Metrics::stop("early_boot_trigger");

    ModuleSetup::loadModules($container);

    Metrics::start("error_handler_setup");
    ErrorHandlerSetup::setup($container);
    Metrics::stop("error_handler_setup");

    Metrics::start("service_discovery");
    ServiceDiscoverSetup::setup($container);
    Metrics::stop("service_discovery");

    if (PHP_SAPI === "cli") {
      Metrics::start("app_command_setup");
      AppCommandSetup::getInstance($container);
      Metrics::stop("app_command_setup");
    }

    Metrics::start("app_booted_hook");
    HookManager::triggerHook(LifecycleHookName::APP_BOOTED);
    Metrics::stop("app_booted_hook");

    // Mark bootstrap as complete to enable cache wrapping
    $container->finishBootstrap();

    return $container;
  }
}
