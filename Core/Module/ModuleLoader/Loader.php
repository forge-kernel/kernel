<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\CLI\Application;
use Forge\CLI\Traits\OutputHelper;
use Forge\Core\Config\Config;
use Forge\Core\DI\Attributes\Service;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Module\Attributes\LifecycleHook;
use Forge\Core\Module\Attributes\Module;
use Forge\Core\Module\Helpers\ModuleFileDiscovery;
use Forge\Core\Module\HookManager;
use Forge\Core\Module\LifecycleHookName;
use Forge\Core\Module\ModuleCommandCache;
use Forge\Traits\ModuleHelper;
use Forge\Traits\NamespaceHelper;
use ReflectionClass;

#[Service]
final class Loader
{
    use NamespaceHelper;
    use ModuleHelper;
    use OutputHelper;

    private array $modules = [];
    private array $moduleRequirements = [];
    private array $moduleRegistry = [];
    private ?array $sortedRegistryCache = null;
    private string $registryFilePath =
        BASE_PATH . "/storage/framework/cache/module_registry.php";
    private array $earlyHooksRegistered = [];
    private bool $earlyHooksDiscovered = false;
    private array $modulesWithCommands = [];

    /***
     * @var Application $cliApplication
     */

    public function __construct(
        private readonly Container $container,
        private readonly Config    $config,
    )
    {
        $this->loadModuleRegistry();
    }

    private function loadModuleRegistry(): void
    {
        $registryExist = FileExistenceCache::exists($this->registryFilePath);
        if ($registryExist) {
            $registry = include $this->registryFilePath;
            $this->moduleRegistry = is_array($registry) ? $registry : [];
            $this->invalidateSortedCache();
            $this->cleanupRegistry();
        }
    }

    /**
     * Invalidates the sorted registry cache.
     * Should be called whenever the module registry is modified.
     */
    private function invalidateSortedCache(): void
    {
        $this->sortedRegistryCache = null;
    }

    private function cleanupRegistry(): void
    {
        $hasChanges = false;

        foreach ($this->moduleRegistry as $key => $moduleInfo) {
            if (
                !isset($moduleInfo["path"]) ||
                !FileExistenceCache::isDir($moduleInfo["path"])
            ) {
                unset($this->moduleRegistry[$key]);
                $hasChanges = true;
                $this->info("Removing missing module: $key");
            }
        }

        if ($hasChanges) {
            $this->invalidateSortedCache();
            $this->saveModuleRegistry();
        }
    }

    private function saveModuleRegistry(): void
    {
        $fp = fopen($this->registryFilePath, "c+");
        if ($fp && flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite(
                $fp,
                "<?php return " . var_export($this->moduleRegistry, true) . ";",
            );
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        } else {
            file_put_contents(
                $this->registryFilePath,
                "<?php return " . var_export($this->moduleRegistry, true) . ";",
            );
        }
    }

    /**
     * Discover and register EARLY_BOOT and BEFORE_MODULE_LOAD hooks before modules are loaded.
     * This allows modules to hook into the bootstrap process early.
     * This method is idempotent - it can be called multiple times safely.
     */
    public function discoverEarlyHooks(): void
    {
        if ($this->earlyHooksDiscovered) {
            return;
        }

        $moduleDirectory = BASE_PATH . "/modules";
        $modules = ModuleFileDiscovery::discoverModulesInDirectory(
            $moduleDirectory,
        );

        foreach ($modules as $module) {
            $directoryName = basename($module["path"]);
            $this->registerModuleAutoloadPath($directoryName, $module["path"]);
            $this->registerEarlyHooksForModule($module);
        }

        $this->earlyHooksDiscovered = true;
    }

    /**
     * Register EARLY_BOOT and BEFORE_MODULE_LOAD hooks for a module without fully loading it.
     */
    private function registerEarlyHooksForModule(array $moduleClass): void
    {
        $className = $moduleClass["name"];

        if (!class_exists($className)) {
            return;
        }

        try {
            $reflectionClass = ModuleFileDiscovery::getReflectionClass(
                $className,
            );

            $moduleAttributes = $reflectionClass->getAttributes(Module::class);
            if (empty($moduleAttributes)) {
                return;
            }

            $moduleAttribute = $moduleAttributes[0]->newInstance();
            $moduleName = $moduleAttribute->name;

            foreach ($reflectionClass->getMethods() as $method) {
                $lifecycleHookAttributes = $method->getAttributes(
                    LifecycleHook::class,
                );

                foreach ($lifecycleHookAttributes as $attribute) {
                    $hookInstance = $attribute->newInstance();
                    $hookName = $hookInstance->hook;

                    if (
                        in_array(
                            $hookName,
                            [
                                LifecycleHookName::EARLY_BOOT,
                                LifecycleHookName::BEFORE_MODULE_LOAD,
                            ],
                            true,
                        )
                    ) {
                        $methodName = $method->getName();
                        $forSelf = $hookInstance->forSelf;

                        $callback = function (...$args) use (
                            $className,
                            $methodName,
                            $moduleName,
                            $forSelf,
                            $hookName,
                        ) {
                            try {
                                $moduleInstance = $this->container->make(
                                    $className,
                                );
                                if ($forSelf) {
                                    $passedModuleName = $args[0] ?? "";
                                    if ($passedModuleName === $moduleName) {
                                        return call_user_func_array(
                                            [$moduleInstance, $methodName],
                                            $args,
                                        );
                                    }
                                } else {
                                    return call_user_func_array(
                                        [$moduleInstance, $methodName],
                                        $args,
                                    );
                                }
                            } catch (\Throwable $e) {
                                error_log(
                                    "Error calling early hook {$hookName->value} on {$className}::{$methodName}: " .
                                    $e->getMessage(),
                                );
                                return null;
                            }
                        };

                        HookManager::addHook($hookName, $callback);

                        $hookKey = "{$className}::{$methodName}::{$hookName->value}";
                        $this->earlyHooksRegistered[$hookKey] = true;
                    }
                }
            }
        } catch (\ReflectionException $e) {
            error_log(
                "Error discovering early hooks for {$className}: " .
                $e->getMessage(),
            );
        } catch (\Throwable $e) {
            error_log(
                "Error discovering early hooks for {$className}: " .
                $e->getMessage(),
            );
        }
    }

    /**
     * Check if a hook was already registered during early discovery.
     */
    public function wasHookRegisteredEarly(
        string            $className,
        string            $methodName,
        LifecycleHookName $hookName,
    ): bool
    {
        $hookKey = "{$className}::{$methodName}::{$hookName->value}";
        return isset($this->earlyHooksRegistered[$hookKey]);
    }

    public function loadModules(): void
    {
        $moduleDirectory = BASE_PATH . "/modules";

        if (!$this->isRegistryStale()) {
            $this->registerAutoloadPathsFromRegistry();
            return;
        }

        $modules = ModuleFileDiscovery::discoverModulesInDirectory(
            $moduleDirectory,
        );

        // Preload all module files for optimal performance
        ModuleFileDiscovery::preloadAllModuleFiles($modules);

        foreach ($modules as $module) {
            $directoryName = basename($module["path"]);
            $this->registerModuleAutoloadPath($directoryName, $module["path"]);
        }

        $hasChanges = false;
        foreach ($modules as $module) {
            $className = $module["name"];
            if (
                !isset($this->moduleRegistry[$className]) ||
                $this->moduleRegistry[$className]["path"] !== $module["path"]
            ) {
                $this->moduleRegistry[$className] = $module;
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            $this->invalidateSortedCache();
            $this->saveModuleRegistry();
        }
    }

    private function isRegistryStale(): bool
    {
        if (empty($this->moduleRegistry)) {
            return true;
        }

        $moduleDirectory = BASE_PATH . "/modules";
        if (!is_dir($moduleDirectory)) {
            return true;
        }

        $knownModules = [];
        foreach ($this->moduleRegistry as $moduleInfo) {
            $dirName = basename($moduleInfo["path"]);
            $knownModules[$dirName] = $moduleInfo["path"];
        }

        foreach (scandir($moduleDirectory) as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $dir = $moduleDirectory . "/" . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            if (!isset($knownModules[$entry])) {
                return true;
            }
            if (!is_dir($knownModules[$entry])) {
                return true;
            }
        }

        return false;
    }

    private function registerAutoloadPathsFromRegistry(): void
    {
        foreach ($this->moduleRegistry as $moduleInfo) {
            $directoryName = basename($moduleInfo["path"]);
            $this->registerModuleAutoloadPath($directoryName, $moduleInfo["path"]);
        }
    }

    public function preloadCliModules(): void
    {
        $cachedModules = ModuleCommandCache::getModulesWithCommands();

        if (!empty($cachedModules) && ModuleCommandCache::isValid()) {
            foreach ($this->getSortedModuleRegistry() as $moduleInfo) {
                $moduleName = basename($moduleInfo["path"]);
                if (
                    in_array($moduleName, $cachedModules, true) &&
                    !$this->isModuleDisabled($moduleName)
                ) {
                    $this->loadModuleByName($moduleInfo["name"]);
                }
            }
            return;
        }

        $hasCommands = false;
        foreach ($this->getSortedModuleRegistry() as $moduleInfo) {
            $moduleName = basename($moduleInfo["path"]);
            if (!$this->isModuleDisabled($moduleName)) {
                $this->loadModuleByName($moduleInfo["name"]);
            }
        }

        if (!empty($this->modulesWithCommands)) {
            ModuleCommandCache::buildAndSave($this->modulesWithCommands);
        }
    }

    /**
     * Retrieves the module registry sorted by the 'order' key.
     * Uses cached sorted result to avoid O(n log n) sorting on every call.
     *
     * @return array
     */
    public function getSortedModuleRegistry(): array
    {
        if ($this->sortedRegistryCache === null) {
            $sortedRegistry = $this->moduleRegistry;

            uasort($sortedRegistry, function ($a, $b) {
                $orderA = $a["order"] ?? PHP_INT_MAX;
                $orderB = $b["order"] ?? PHP_INT_MAX;

                if ($orderA === $orderB) {
                    return 0;
                }
                return $orderA < $orderB ? -1 : 1;
            });

            $this->sortedRegistryCache = $sortedRegistry;
        }

        return $this->sortedRegistryCache;
    }

    /**
     * Check if a module is disabled via configuration.
     */
    public function isModuleDisabled(string $moduleName): bool
    {
        $disabledModules = $this->config->get("app.disabled_modules", env('DISABLED_MODULES', []));
        return in_array($moduleName, $disabledModules, true);
    }

    public function loadModuleByName(string $moduleName): void
    {
        if (!isset($this->moduleRegistry[$moduleName])) {
            return;
        }

        $moduleInfo = $this->moduleRegistry[$moduleName];
        $this->loadModule($moduleInfo["path"], $moduleInfo);

        $this->checkModuleRequirements($moduleName);
    }

    private function loadModule(string $modulePath, array $moduleClass): void
    {
        $moduleName = basename($modulePath);
        $className = $moduleClass["name"];

        try {
            $reflectionClass = ModuleFileDiscovery::getReflectionClass(
                $className,
            );
            $attributes = $reflectionClass->getAttributes(Module::class);

            if (!empty($attributes)) {
                $moduleInstance = $attributes[0]->newInstance();
                if (!$moduleInstance->core) {
                    if ($this->isModuleDisabled($moduleName)) {
                        $this->info(
                            "Module {$moduleName} is disabled via config, skipping registration.",
                        );
                        return;
                    }

                    $this->registerModule(
                        $moduleName,
                        $className,
                        $moduleInstance,
                        $reflectionClass,
                    );
                }
            }
        } catch (\ReflectionException $e) {
            $this->error(
                "Failed to load module: $moduleName - " . $e->getMessage(),
            );
        }
    }

    private function registerModule(
        string          $moduleName,
        string          $className,
        Module          $moduleInstance,
        ReflectionClass $reflectionClass,
    ): void
    {
        $this->modules[$moduleName] = $className;

        new RegisterModuleCommand($this->container, $reflectionClass)->init();
        new RegisterModuleConfig($this->config, $reflectionClass)->init();
        if (
            $this->container->has(
                \Forge\Core\Structure\StructureResolver::class,
            )
        ) {
            $structureResolver = $this->container->get(
                \Forge\Core\Structure\StructureResolver::class,
            );
            new RegisterModuleStructure(
                $structureResolver,
                $reflectionClass,
            )->init();
        }
        new RegisterModuleHooks($this->container, $reflectionClass)->init();
        new RegisterModuleProvides($this->container, $reflectionClass)->init();
        $requiresRegistrar = new RegisterModuleRequires(
            $reflectionClass,
            $this->moduleRequirements,
        );
        $requiresRegistrar->init();
        $this->checkModuleRequirements($moduleName);
        new RegisterModuleCompatibility(
            $reflectionClass,
            $moduleInstance,
        )->init();
        new RegisterModuleRepository($reflectionClass)->init();

        $moduleInstance = $this->container->make($className);
        if (method_exists($moduleInstance, "register")) {
            $moduleInstance->register($this->container);
        }

        new RegisterModuleService($this->container, $reflectionClass)->init();

        HookManager::triggerHook(
            LifecycleHookName::AFTER_MODULE_REGISTER,
            $moduleName,
            $className,
            $moduleInstance,
        );
    }

    public function loadModuleByNamespace(string $namespacePrefix): void
    {
        foreach ($this->moduleRegistry as $className => $moduleInfo) {
            if (str_starts_with($className, $namespacePrefix)) {
                $moduleName = basename($moduleInfo["path"]);
                if (!isset($this->modules[$moduleName])) {
                    $this->loadModule($moduleInfo["path"], $moduleInfo);
                    $this->checkModuleRequirements($moduleName);
                }
                return;
            }
        }
    }

    public function isModuleLoaded(string $moduleClassName): bool
    {
        return isset($this->modules[$moduleClassName]);
    }

    public function recordModuleHasCommands(string $moduleName): void
    {
        if (!in_array($moduleName, $this->modulesWithCommands, true)) {
            $this->modulesWithCommands[] = $moduleName;
        }
    }

    public function getModulesWithCommands(): array
    {
        return $this->modulesWithCommands;
    }

    public function getModules(): array
    {
        return $this->modules;
    }

    public function loadCoreModule(string $modulePath): void
    {
        if (!FileExistenceCache::isDir($modulePath)) {
            echo "Module path does not exist: $modulePath";
            return;
        }

        $moduleName = basename($modulePath);

        // Check if module is disabled before loading
        if ($this->isModuleDisabled($moduleName)) {
            return;
        }

        $srcPath = "$modulePath/src";

        if (!FileExistenceCache::isDir($srcPath)) {
            echo "Module source path does not exist: $srcPath";
            return;
        }

        $this->registerModuleAutoloadPath($moduleName, $modulePath);

        $modules = ModuleFileDiscovery::discoverModulesInDirectory(
            dirname($srcPath),
        );
        $moduleClass = null;

        foreach ($modules as $module) {
            if (str_contains($module["path"], basename($modulePath))) {
                $moduleClass = $module;
                break;
            }
        }

        if ($moduleClass) {
            $this->loadModule($modulePath, $moduleClass);
        } else {
            echo "No module class found in: $srcPath";
        }
    }
}
