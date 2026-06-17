<?php

declare(strict_types=1);

namespace Forge\Core\Helpers;

use Forge\Core\Config\Config;

final class ModuleHelper
{
  private static ?array $disabledModulesCache = null;
  private static ?Config $configInstance = null;

  public static function isModuleDisabled(string $moduleName, ?Config $config = null): bool
  {
    if (self::$disabledModulesCache === null) {
      self::initializeCache($config);
    }

    return isset(self::$disabledModulesCache[$moduleName]);
  }

  public static function extractModuleNameFromNamespace(string $namespace): ?string
  {
    if (preg_match('/^App\\\\Modules\\\\([^\\\\]+)/', $namespace, $matches)) {
      return $matches[1];
    }
    return null;
  }

  public static function extractModuleNameFromPath(string $path): ?string
  {
    if (preg_match('#modules/([^/]+)/#', $path, $matches)) {
      return $matches[1];
    }
    return null;
  }

  public static function extractModuleNameFromViewPath(string $viewPath): ?string
  {
    if (str_contains($viewPath, ':')) {
      [$moduleName] = explode(':', $viewPath, 2);
      return $moduleName;
    }
    return null;
  }

  public static function clearCache(): void
  {
    self::$disabledModulesCache = null;
    self::$configInstance = null;
  }

  private static function initializeCache(?Config $config): void
  {
    if ($config === null) {
      if (self::$configInstance === null) {
        self::$configInstance = new Config(BASE_PATH . '/config');
      }
      $config = self::$configInstance;
    }

    $disabledModules = $config->get('app.disabled_modules', env('DISABLED_MODULES', []));

    if (empty($disabledModules)) {
      self::$disabledModulesCache = [];
    } else {
      self::$disabledModulesCache = array_flip($disabledModules);
    }
  }
}
