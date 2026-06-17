<?php

declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\OutputHelper;
use Forge\Core\Bootstrap\ModuleSetup;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Module\ModuleLoader\Loader;
use Forge\Core\Services\AttributeDiscoveryService;
use Forge\Core\Services\ModuleAssetManager;

#[Cli(
  command: 'cache:warm',
  description: 'Warm up application caches and rebuild registries',
  usage: 'cache:warm',
  examples: [
    'cache:warm'
  ]
)]
final class WarmCacheCommand extends Command
{
  use OutputHelper;

  private const string MODULE_ASSETS_CACHE_FILE =
    BASE_PATH . '/storage/framework/cache/module_assets.cache';
  private const string COMPILED_HOOKS_FILE =
    BASE_PATH . '/storage/framework/cache/compiled_hooks.php';

  public function __construct(
    private readonly Container $container,
    private readonly ModuleAssetManager $moduleAssetManager
  ) {
  }

  public function execute(array $args): int
  {
    $this->info("Warming application caches...");

$this->warmModuleRegistry();
        $this->warmCompiledHooks();
        $this->warmModuleAssets();
        $this->warmAttributeDiscovery();
        $this->warmRolePermissionCache();

    $this->info("Application caches warmed successfully.");
    return 0;
  }

  private function warmModuleRegistry(): void
  {
    $this->info("Rebuilding module registry...");

    try {
      /** @var Loader $moduleLoader */
      $moduleLoader = $this->container->get(Loader::class);
      $moduleLoader->loadModules();

      $registry = $moduleLoader->getSortedModuleRegistry();
      $moduleCount = count($registry);

      $this->success("Module registry rebuilt successfully ({$moduleCount} modules).");
    } catch (\Exception $e) {
      $this->error("Failed to rebuild module registry: " . $e->getMessage());
    }
  }

  private function warmCompiledHooks(): void
  {
    $this->info("Compiling lifecycle hooks...");

    try {
      ModuleSetup::compileHooks();

      if (FileExistenceCache::exists(self::COMPILED_HOOKS_FILE)) {
        $this->success("Lifecycle hooks compiled successfully.");
      } else {
        $this->warning("Compiled hooks file was not created.");
      }
    } catch (\Exception $e) {
      $this->error("Failed to compile hooks: " . $e->getMessage());
    }
  }

  private function warmModuleAssets(): void
  {
    $this->info("Rebuilding module assets cache...");

    try {
      if (FileExistenceCache::exists(self::MODULE_ASSETS_CACHE_FILE)) {
        unlink(self::MODULE_ASSETS_CACHE_FILE);
      }

      $reflection = new \ReflectionClass(ModuleAssetManager::class);
      $manifestProperty = $reflection->getProperty('manifest');
      $manifestProperty->setAccessible(true);
      $manifestProperty->setValue(null, []);

      ModuleAssetManager::initialize();

      if (FileExistenceCache::exists(self::MODULE_ASSETS_CACHE_FILE)) {
        $this->success("Module assets cache rebuilt successfully.");
      } else {
        $this->warning("Module assets cache file was not created (no module assets found).");
      }
    } catch (\Exception $e) {
      $this->error("Failed to rebuild module assets cache: " . $e->getMessage());
    }
  }

private function warmAttributeDiscovery(): void
    {
        $this->info("Warming attribute discovery cache...");

        try {
            $discoveryService = new AttributeDiscoveryService();
            $cacheFile = $discoveryService->getCacheFilePath();

if (FileExistenceCache::exists($cacheFile)) {
                $discoveryService->clearCache();
                $this->success("Attribute discovery cache warmed successfully.");
            } else {
                $this->warning("Attribute discovery cache file does not exist.");
            }

            $this->success("Attribute discovery cache warmed successfully.");
        } catch (\Exception $e) {
            $this->error("Failed to warm attribute discovery cache: " . $e->getMessage());
        }
    }

    private function warmRolePermissionCache(): void
    {
        $this->info("Warming role/permission cache...");

        try {
            $container = new \Forge\Core\DI\Container();
            $cacheService = $container->get(\App\Modules\ForgeAuth\Services\RolePermissionCacheService::class);
            $cacheService->warmCache();
            $this->success("Role/Permission cache warmed successfully.");
        } catch (\Exception $e) {
            $this->error("Failed to warm role/permission cache: " . $e->getMessage());
        }
    }
}
