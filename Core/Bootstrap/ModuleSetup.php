<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Closure;
use Forge\Core\Config\Config;
use Forge\Core\Debug\Metrics;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Module\Attributes\Module;
use Forge\Core\Module\HookManager;
use Forge\Core\Module\LifecycleHookName;
use Forge\Core\Module\ModuleLoader\Loader;
use Forge\Core\Session\SessionInterface;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;
use ReflectionClass;
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
        $moduleLoader->loadModules();

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

        if ($container->has(SessionInterface::class)) {
            $session = $container->get(SessionInterface::class);
            $session->start();
        }

        Metrics::start("module_discovery");
        /*** @var Loader $moduleLoader */
        $moduleLoader = $container->get(Loader::class);

        $moduleLoader->discoverEarlyHooks();

        HookManager::triggerHook(LifecycleHookName::BEFORE_MODULE_LOAD);

        $moduleLoader->loadModules();
        self::loadCoreModules($moduleLoader);

        Metrics::stop("module_discovery");

        if (
            PHP_SAPI === "cli" ||
            !FileExistenceCache::exists(self::$compiledHooksFile)
        ) {
            self::compileHooks();
        }

        HookManager::triggerHook(LifecycleHookName::AFTER_MODULE_LOAD);
        self::$modulesLoaded = true;

        return $container;
    }

    /**
     * @throws ReflectionException
     */
    private static function loadCoreModules(Loader $moduleLoader): void
    {
        $moduleRegistry = $moduleLoader->getSortedModuleRegistry();

        $classNames = array_column($moduleRegistry, "name");
        ReflectionCacheService::preloadClassReflections($classNames);

        foreach ($moduleRegistry as $moduleInfo) {
            $reflectionClass = ReflectionCacheService::getClassReflection(
                $moduleInfo["name"],
            );
            $attributes = ReflectionCacheService::getClassAttributes(
                $reflectionClass,
                Module::class,
            );

            if (!empty($attributes)) {
                $moduleInstance = $attributes[0]->newInstance();
                if ($moduleInstance->type === "core") {
                    $moduleName = basename($moduleInfo["path"]);
                    // Skip disabled modules
                    if ($moduleLoader->isModuleDisabled($moduleName)) {
                        continue;
                    }
                    if (!$moduleLoader->isModuleLoaded($moduleInfo["name"])) {
                        $moduleLoader->loadModuleByName($moduleInfo["name"]);
                    }
                }
            }
        }
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
                        error_log(
                            "✗ Invalid class/method names: class=" .
                            gettype($className) .
                            ", method=" .
                            gettype($methodName),
                        );
                    }
                } else {
                    error_log(
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

        if (FileExistenceCache::exists($compiledFile)) {
            include $compiledFile;
        }
    }
}
