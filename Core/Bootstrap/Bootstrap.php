<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\Config\Config;
use Forge\Core\Config\Environment;
use Forge\Core\Config\EnvParser;
use Forge\Core\Debug\Metrics;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;
use ReflectionException;

require_once "Version.php";

final class Bootstrap
{
  private static ?self $instance = null;

  private const array STORAGE_DIRS = [
    '/storage/sessions',
    '/storage/logs',
    '/storage/database',
    '/storage/framework/cache',
    '/storage/app',
    '/storage/bin',
    '/storage/queues',
  ];

  /**
   * @throws ReflectionException
   * @throws MissingServiceException
   * @throws ResolveParameterException
   */
  private function __construct()
  {
    $this->init();
  }

  /**
   * @throws ReflectionException
   * @throws MissingServiceException|ResolveParameterException
   */
  private function init(): void
  {
    self::ensureStorageDirectory();
    self::initEnvironment();
    if (PHP_SAPI !== "cli") {
      self::initSession();
    }
    ContainerAppSetup::initOnce();
  }

  private static function ensureStorageDirectory(): void
  {
    foreach (self::STORAGE_DIRS as $dir) {
      $path = BASE_PATH . $dir;
      if (!is_dir($path)) {
        mkdir($path, 0755, true);
        touch($path . '/.gitkeep');
      }
    }
  }

  private static function initEnvironment(): void
  {
    Metrics::start("environment_resolution");
    if(isset($_ENV['FORGE_MANAGED'])) {
      Environment::getInstance()->hidrate($_ENV);
      Metrics::stop();
      return;
    }

    $envPath = BASE_PATH . "/.env";

    if (FileExistenceCache::exists($envPath)) {
      EnvParser::load($envPath);
    }
    Environment::getInstance();
    Metrics::stop("environment_resolution");
  }

  public static function getInstance(): self
  {
    if (!self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  private static function initSession(): void
  {
    ini_set("session.cookie_httponly", true);
    ini_set("session.cookie_secure", true);
    ini_set("session.cookie_samesite", "Strict");
    ini_set("session.use_strict_mode", true);
    ini_set("session.use_only_cookies", true);
  }

  public static function shouldCacheViews(): bool
  {
    return Environment::getInstance()->get("VIEW_CACHE") &&
      !Environment::getInstance()->isDevelopment();
  }

  /**
   * @throws ReflectionException
   * @throws MissingServiceException
   * @throws ResolveParameterException
   */
  public static function initCliContainer(): Container
  {
    Metrics::start("di_resolution");
    self::configSetup(Container::getInstance());
    Metrics::stop("di_resolution");
    return ContainerCLISetup::setup();
  }

  private static function configSetup(Container $container): void
  {
    Metrics::start("config_resolution");
    $container->singleton(Config::class, function () {
      return new Config(BASE_PATH . "/config");
    });
    Metrics::stop("config_resolution");
  }

  /**
   * @throws ReflectionException
   * @throws MissingServiceException
   * @throws ResolveParameterException
   * @deprecated Use ErrorHandlerSetup::setup() instead. This method is kept for backward compatibility.
   */
  public static function initErrorHandling(Container $container): void
  {
    ErrorHandlerSetup::setup($container);
  }
}
