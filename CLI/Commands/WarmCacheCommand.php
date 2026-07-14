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
use Forge\Core\Module\ModuleCache;
use Forge\Core\Module\ModuleLoader\Loader;
use Forge\Core\Structure\StructureResolver;
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

    private const string COMPILED_HOOKS_FILE =
        BASE_PATH . '/storage/framework/cache/compiled_hooks.php';

    public function __construct(
        private readonly Container $container
    ) {
    }

    public function execute(array $args): int
    {
        $this->info("Warming application caches...");

        ModuleCache::clear();

        $this->warmModuleRegistry();
        $this->warmCompiledHooks();
        $this->warmModuleCaches();

        $this->info("Application caches warmed successfully.");
        return 0;
    }

    private function warmModuleRegistry(): void
    {
        $this->info("Rebuilding module registry...");

        try {
            /** @var Loader $moduleLoader */
            $moduleLoader = $this->container->get(Loader::class);
            $moduleLoader->resetModules();
            $moduleLoader->loadModules();
            $moduleLoader->loadCoreModules();

            $registry = $moduleLoader->getSortedModuleRegistry();
            $moduleCount = count($registry);

            ModuleCache::buildAndSave(
                $this->container,
                $moduleLoader->getModuleMetas(),
                $moduleLoader->getModuleDirectories()
            );

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


}
