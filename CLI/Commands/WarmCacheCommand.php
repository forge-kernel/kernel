<?php

declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\OutputHelper;
use Forge\Core\Bootstrap\ModuleSetup;
use Forge\Core\Contracts\Cache\CacheWarmerInterface;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Module\ModuleLoader\Loader;
use Forge\Core\Module\ModuleCommandCache;
use Forge\Core\Bootstrap\OptimizedDirectoryScanner;
use Forge\Core\Services\AttributeDiscoveryService;
use Forge\Core\Services\ModuleAssetManager;
use Forge\Core\Services\ServiceRegistrationCache;
use ReflectionClass;

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
    $this->warmModuleCaches();
    $this->warmModuleCommandCache();
        $this->warmHelperMap();
        $this->warmServiceRegistrations();

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
                FileExistenceCache::clearPath(self::MODULE_ASSETS_CACHE_FILE);
            }

            $reflection = new \ReflectionClass(ModuleAssetManager::class);
            $manifestProperty = $reflection->getProperty('manifest');
            $manifestProperty->setAccessible(true);
            $manifestProperty->setValue(null, []);

            ModuleAssetManager::initialize();

            FileExistenceCache::clearPath(self::MODULE_ASSETS_CACHE_FILE);
            if (file_exists(self::MODULE_ASSETS_CACHE_FILE)) {
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
            }

            $config = $this->container->get(\Forge\Core\Config\Config::class);
            $basePaths = OptimizedDirectoryScanner::getServiceDiscoveryPaths($config);

            $attributeClasses = [
                \Forge\Core\DI\Attributes\Service::class,
                \Forge\Core\DI\Attributes\Discoverable::class,
                \Forge\Core\DI\Attributes\Injectable::class,
                \Forge\Core\Module\Attributes\LifecycleHook::class,
            ];

            $discoveryService->discover($basePaths, $attributeClasses, false);

            $this->success("Attribute discovery cache warmed successfully.");
        } catch (\Exception $e) {
            $this->error("Failed to warm attribute discovery cache: " . $e->getMessage());
        }
    }

  private function warmModuleCaches(): void
  {
    $this->info("Warming module caches...");

    try {
      $warmers = $this->container->getAll(CacheWarmerInterface::class);

      if (empty($warmers)) {
        $this->warning("No module cache warmers found.");
        return;
      }

      foreach ($warmers as $warmer) {
        $name = (new ReflectionClass($warmer))->getShortName();
        $this->info("  {$name}...");
        $warmer->warmCache();
      }

      $this->success("Module caches warmed successfully (" . count($warmers) . " warmer(s)).");
    } catch (\Exception $e) {
      $this->error("Failed to warm module caches: " . $e->getMessage());
    }
  }


    private function warmModuleCommandCache(): void
    {
        $this->info("Warming module command cache...");

        try {
            ModuleCommandCache::clear();

            $moduleLoader = $this->container->get(Loader::class);
            $moduleLoader->loadModules();

            $sortedRegistry = $moduleLoader->getSortedModuleRegistry();
            foreach ($sortedRegistry as $moduleInfo) {
                $moduleName = basename($moduleInfo["path"]);
                if (!$moduleLoader->isModuleDisabled($moduleName)) {
                    $moduleLoader->loadModuleByName($moduleInfo["name"]);
                }
            }

            $modulesWithCommands = $moduleLoader->getModulesWithCommands();
            if (!empty($modulesWithCommands)) {
                ModuleCommandCache::buildAndSave($modulesWithCommands);
                $this->success("Module command cache warmed successfully (" . count($modulesWithCommands) . " modules with commands).");
            } else {
                $this->warning("No modules with commands found.");
            }
        } catch (\Exception $e) {
            $this->error("Failed to warm module command cache: " . $e->getMessage());
        }
    }

    private function warmHelperMap(): void
    {
        $this->info("Warming helper map cache...");

        $cacheFile = BASE_PATH . '/storage/framework/cache/helper-map.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        \Forge\Core\Bootstrap\HelperDiscoverSetup::setup();

        if (file_exists($cacheFile)) {
            $this->success("Helper map cache warmed successfully.");
        } else {
            $this->warning("Helper map cache was not created (no helper files found).");
        }
    }

    private function warmServiceRegistrations(): void
    {
        $this->info("Warming service registration cache...");

        try {
            ServiceRegistrationCache::clear();

            \Forge\Core\Bootstrap\ServiceDiscoverSetup::setup($this->container);

            $cacheFile = ServiceRegistrationCache::getCacheFilePath();
            if (file_exists($cacheFile)) {
                $this->success("Service registration cache warmed successfully.");
            } else {
                $this->warning("Service registration cache was not created.");
            }
        } catch (\Exception $e) {
            $this->error("Failed to warm service registration cache: " . $e->getMessage());
        }
    }
}
