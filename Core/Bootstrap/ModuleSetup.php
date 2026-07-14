<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Closure;
use Forge\Core\Config\Config;
use Forge\Core\Debug\Metrics;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\Logger;
use Forge\Core\Module\HookManager;
use Forge\Core\Module\LifecycleHookName;
use Forge\Core\Module\ModuleCache;
use Forge\Core\Module\ModuleLoader\Loader;
use Forge\Core\Structure\StructureResolver;
use Forge\Core\Session\SessionInterface;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;
use ReflectionException;
use ReflectionFunction;

final class ModuleSetup
{
    private static bool $modulesLoaded = false;
    private static string $compiledHooksFile;

    /**
     * @throws ReflectionException
     * @throws MissingServiceException
     * @throws ResolveParameterException
     */
    public static function preloadModules(Container $container): void
    {
        /*** @var Loader $moduleLoader */
        $moduleLoader = $container->get(Loader::class);

        $moduleLoader->preloadCliModules();
    }

    /**
     * @throws ReflectionException
     * @throws MissingServiceException
     * @throws ResolveParameterException
     */
    public static function loadModules(Container $container): Container
    {
        if (self::$modulesLoaded) {
            return $container;
        }

        self::$compiledHooksFile =
            BASE_PATH . "/storage/framework/cache/compiled_hooks.php";

        $container->singleton(Loader::class, function () use ($container) {
            return new Loader(
                container: $container,
                config: $container->get(Config::class),
            );
        });

        if (!$container->has(StructureResolver::class)) {
            $container->singleton(StructureResolver::class, fn() => new StructureResolver());
        }

        if ($container->has(SessionInterface::class)) {
            $session = $container->get(SessionInterface::class);
            $session->start();
        }

        Metrics::start("module_setup_early_hooks");
        /*** @var Loader $moduleLoader */
        $moduleLoader = $container->get(Loader::class);

        $moduleLoader->discoverEarlyHooks();
        Metrics::stop("module_setup_early_hooks");

        Metrics::start("before_module_load_trigger");
        HookManager::triggerHook(LifecycleHookName::BEFORE_MODULE_LOAD);
        Metrics::stop("before_module_load_trigger");

        Metrics::start("module_loading");
        $moduleLoader->loadModules();
        $moduleLoader->loadCoreModules();

        if (!ModuleCache::isValid()) {
            ModuleCache::buildAndSave($container, $moduleLoader->getModuleMetas(), $moduleLoader->getModuleDirectories());
        }
        Metrics::stop("module_loading");

        if (
            !FileExistenceCache::exists(self::$compiledHooksFile) ||
            self::isHooksCacheStale()
        ) {
            self::compileHooks();
        }

        Metrics::start("after_module_load_trigger");
        HookManager::triggerHook(LifecycleHookName::AFTER_MODULE_LOAD);
        Metrics::stop("after_module_load_trigger");
        self::$modulesLoaded = true;

        return $container;
    }

    /**
     * @throws ReflectionException
     */
    public static function compileHooks(): void
    {
        $compiledHooks = [];
        $hooks = HookManager::debugGetHooks();

        foreach ($hooks as $hookName => $callbacks) {
            $seen = [];
            foreach ($callbacks as $index => $callback) {
                if ($callback instanceof Closure) {
                    $reflection = new ReflectionFunction($callback);
                    $staticVars = $reflection->getStaticVariables();

                    if (
                        isset($staticVars["callback"]) &&
                        is_array($staticVars["callback"])
                    ) {
                        $originalCallback = $staticVars["callback"];
                        $className = is_object($originalCallback[0])
                            ? get_class($originalCallback[0])
                            : $originalCallback[0];
                        $methodName = $originalCallback[1];

                        if (is_string($className) && is_string($methodName)) {
                            $key = "$className::$methodName";
                            if (in_array($key, $seen)) {
                                continue;
                            }
                            $seen[] = $key;

                            $compiledHooks[$hookName][] = [
                                "type" => "method",
                                "class" => $className,
                                "method" => $methodName,
                            ];
                            continue;
                        }
                    }
                } elseif (is_array($callback) && count($callback) === 2) {
                    $className = is_object($callback[0])
                        ? get_class($callback[0])
                        : $callback[0];
                    $methodName = $callback[1];

                    if (is_string($className) && is_string($methodName)) {
                        $key = "$className::$methodName";
                        if (in_array($key, $seen)) {
                            continue;
                        }
                        $seen[] = $key;

                        $compiledHooks[$hookName][] = [
                            "type" => "method",
                            "class" => $className,
                            "method" => $methodName,
                        ];
                    } else {
                        Logger::log(
                            "✗ Invalid class/method names: class=" .
                            gettype($className) .
                            ", method=" .
                            gettype($methodName),
                        );
                    }
                } else {
                    Logger::log(
                        "✗ Unsupported callback type: " . gettype($callback),
                    );
                }
            }
        }

        $compiledFile =
            BASE_PATH . "/storage/framework/cache/compiled_hooks.php";
        $directory = dirname($compiledFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $content = "<?php return " . var_export($compiledHooks, true) . ";";

        file_put_contents($compiledFile, $content);

        HookManager::setCompiledHookData($compiledHooks);
    }

    private static function isHooksCacheStale(): bool
    {
        $cacheMtime = @filemtime(self::$compiledHooksFile);
        if ($cacheMtime === false) {
            return true;
        }

        $modulesPath = BASE_PATH . '/' . \Forge\Core\Structure\StructureResolver::resolveModulesRoot();
        if (!is_dir($modulesPath)) {
            return false;
        }

        foreach (scandir($modulesPath) as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $dir = $modulesPath . "/" . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            $dirMtime = @filemtime($dir);
            if ($dirMtime !== false && $dirMtime > $cacheMtime) {
                return true;
            }
        }

        return false;
    }
}
