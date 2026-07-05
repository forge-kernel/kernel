<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\CLI\Application;
use Forge\CLI\Attributes\CoreCommand;
use Forge\CLI\Traits\OutputHelper;
use Forge\Core\Config\Config;
use Forge\Core\DI\Attributes\Service;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\Logger;
use Forge\Core\Helpers\Version;
use Forge\Core\Module\Attributes\LifecycleHook;
use Forge\Core\Module\Attributes\Module;
use Forge\Core\Module\Helpers\ModuleFileDiscovery;
use Forge\Core\Module\HookManager;
use Forge\Core\Module\LifecycleHookName;
use Forge\Core\Module\ModuleCache;
use Forge\Core\Module\ModuleCommandCache;
use Forge\Core\Structure\StructureResolver;
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

    /** @var array<string, string> [name => path] e.g. ['ForgeRouter' => '/path/modules/ForgeRouter'] */
    private array $moduleDirectories = [];

    /** @var array<string, array{class: string, order: int, type: string, core: bool}> */
    private array $moduleMetas = [];

    private array $earlyHooksRegistered = [];
    private bool $earlyHooksDiscovered = false;
    private array $modulesWithCommands = [];

    private ?string $modulesRootPath = null;

    public function __construct(
        private readonly Container $container,
        private readonly Config    $config,
    )
    {
    }

    private function getModulesRoot(): string
    {
        if ($this->modulesRootPath === null) {
            $this->modulesRootPath = BASE_PATH . '/' . StructureResolver::resolveModulesRoot();
        }
        return $this->modulesRootPath;
    }

    /**
     * Discover module directories by scanning the modules/ folder.
     * Convention: each subdirectory with src/{Name}Module.php is a module.
     *
     * @return array<string, string> [name => path]
     */
    private function discoverModuleDirectories(): array
    {
        $modulesDir = $this->getModulesRoot();
        if (!is_dir($modulesDir)) {
            return [];
        }

        $directories = [];
        foreach (scandir($modulesDir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$modulesDir/$entry";
            if (!is_dir($path)) {
                continue;
            }
            $classFile = "$path/src/{$entry}Module.php";
            if (is_file($classFile)) {
                $directories[$entry] = $path;
            }
        }

        return $directories;
    }

    private function resolveModuleClassName(string $moduleName): string
    {
        $modulesNamespace = StructureResolver::resolveModulesNamespace();
        return $modulesNamespace . '\\' . $moduleName . '\\' . $moduleName . 'Module';
    }

    /**
     * Load #[Module] attribute metadata from each discovered module class.
     */
    private function loadModuleMetas(): void
    {
        foreach ($this->moduleDirectories as $name => $path) {
            $className = $this->resolveModuleClassName($name);

            if (!class_exists($className)) {
                continue;
            }

            try {
                $reflection = ModuleFileDiscovery::getReflectionClass($className);
                $attrs = $reflection->getAttributes(Module::class);
                if (empty($attrs)) {
                    continue;
                }

                $attr = $attrs[0]->newInstance();
                $this->moduleMetas[$name] = [
                    'class' => $className,
                    'order' => $attr->order ?? PHP_INT_MAX,
                    'type' => $attr->type ?? 'module',
                    'core' => $attr->core ?? false,
                ];
            } catch (\ReflectionException $e) {
                Logger::log("Failed to read #[Module] attribute for {$className}", $e->getMessage());
            }
        }
    }

    /**
     * Returns modules sorted by #[Module(order: ...)].
     *
     * @return array<string, array{class: string, order: int, type: string, core: bool}>
     */
    private function getSortedModules(): array
    {
        $sorted = $this->moduleMetas;
        uasort($sorted, fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        return $sorted;
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

        $this->moduleDirectories = $this->discoverModuleDirectories();

        foreach ($this->moduleDirectories as $name => $path) {
            $this->registerModuleAutoloadPath($name, $path);
        }

        if ($this->isCompiledHooksCacheValid()) {
            $this->earlyHooksDiscovered = true;
            return;
        }

        foreach ($this->moduleDirectories as $name => $path) {
            $className = $this->resolveModuleClassName($name);
            $this->registerEarlyHooksForModule($className);
        }

        $this->earlyHooksDiscovered = true;
    }

    private function isCompiledHooksCacheValid(): bool
    {
        $compiledFile = BASE_PATH . '/storage/framework/cache/compiled_hooks.php';
        if (!FileExistenceCache::exists($compiledFile)) {
            return false;
        }
        $cacheMtime = @filemtime($compiledFile);
        if ($cacheMtime === false) {
            return false;
        }
        $modulesPath = $this->getModulesRoot();
        if (!is_dir($modulesPath)) {
            return false;
        }
        foreach (scandir($modulesPath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $modulesPath . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            $dirMtime = @filemtime($dir);
            if ($dirMtime !== false && $dirMtime > $cacheMtime) {
                return false;
            }
        }
        return true;
    }

    /**
     * Register EARLY_BOOT and BEFORE_MODULE_LOAD hooks for a module without fully loading it.
     */
    private function registerEarlyHooksForModule(string $className): void
    {
        if (!class_exists($className)) {
            return;
        }

        try {
            $reflectionClass = ModuleFileDiscovery::getReflectionClass($className);

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
                                Logger::log("Error calling early hook {$hookName->value} on {$className}::{$methodName}", $e->getMessage());
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
            Logger::log("Error discovering early hooks for {$className}", $e->getMessage());
        } catch (\Throwable $e) {
            Logger::log("Error discovering early hooks for {$className}", $e->getMessage());
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
        if (ModuleCache::isValid()) {
            $cache = ModuleCache::load();
            if ($cache !== null) {
                $this->restoreFromCache($cache);
                return;
            }
        }

        if (empty($this->moduleDirectories)) {
            $this->moduleDirectories = $this->discoverModuleDirectories();

            foreach ($this->moduleDirectories as $name => $path) {
                $this->registerModuleAutoloadPath($name, $path);
            }
        }

        if (!empty($this->moduleMetas)) {
            return;
        }

        $this->loadModuleMetas();

        $this->container->startRecording();
        $this->registerAllModules();
        $this->container->stopRecording();
    }

    private function registerAllModules(): void
    {
        foreach ($this->getSortedModules() as $name => $meta) {
            if ($meta['core']) {
                continue;
            }
            if (!$this->isModuleDisabled($name) && !isset($this->modules[$name])) {
                $this->loadModule($name);
            }
        }
    }

    public function loadCoreModules(): void
    {
        $coreModules = array_filter($this->moduleMetas, fn(array $m): bool => $m['core']);
        if (empty($coreModules)) {
            return;
        }

        foreach ($this->getSortedModules() as $name => $meta) {
            if (!$meta['core']) {
                continue;
            }
            if (!$this->isModuleDisabled($name) && !isset($this->modules[$name])) {
                $this->loadModule($name);
            }
        }
    }

    /**
     * Preload CLI modules. Called after loadModules() in CLI context.
     */
    public function preloadCliModules(): void
    {
        $cachedModules = ModuleCommandCache::getModulesWithCommands();

        if (!empty($cachedModules) && ModuleCommandCache::isValid()) {
            foreach ($this->getSortedModules() as $name => $meta) {
                if (
                    in_array($name, $cachedModules, true) &&
                    !$this->isModuleDisabled($name) &&
                    !isset($this->modules[$name])
                ) {
                    $this->loadModule($name);
                }
            }
            return;
        }

        foreach ($this->getSortedModules() as $name => $meta) {
            if (!$this->isModuleDisabled($name) && !isset($this->modules[$name])) {
                $this->loadModule($name);
            }
        }

        if (!empty($this->modulesWithCommands)) {
            ModuleCommandCache::buildAndSave($this->modulesWithCommands);
        }
    }

    /**
     * Retrieves the module registry sorted by the 'order' key.
     * Public API — returns same format as legacy implementation for backward compatibility.
     *
     * @return array<string, array{name: string, order: int, path: string, type: string}>
     */
    public function getSortedModuleRegistry(): array
    {
        $registry = [];
        foreach ($this->getSortedModules() as $name => $meta) {
            $path = $this->moduleDirectories[$name] ?? ($this->getModulesRoot() . '/' . $name);
            $registry[$meta['class']] = [
                'name' => $meta['class'],
                'order' => $meta['order'],
                'path' => $path,
                'type' => $meta['type'],
            ];
        }
        return $registry;
    }

    /**
     * Check if a module is disabled via configuration.
     */
    public function isModuleDisabled(string $moduleName): bool
    {
        $disabledModules = $this->config->get("app.disabled_modules", env('DISABLED_MODULES', []));
        return in_array($moduleName, $disabledModules, true);
    }

    public function loadModuleByName(string $fqcn): void
    {
        $moduleName = $this->findModuleNameByClass($fqcn);
        if ($moduleName === null || isset($this->modules[$moduleName])) {
            return;
        }

        $this->loadModule($moduleName);
        $this->checkModuleRequirements($moduleName);
    }

    private function findModuleNameByClass(string $fqcn): ?string
    {
        foreach ($this->moduleMetas as $name => $meta) {
            if ($meta['class'] === $fqcn) {
                return $name;
            }
        }

        // Fallback: extract from namespace convention
        $modulesNamespace = StructureResolver::resolveModulesNamespace();
        $prefix = $modulesNamespace . '\\';
        if (str_starts_with($fqcn, $prefix)) {
            $parts = explode('\\', substr($fqcn, strlen($prefix)));
            return $parts[0] ?? null;
        }

        return null;
    }

    private function loadModule(string $moduleName): void
    {
        $meta = $this->moduleMetas[$moduleName] ?? null;
        if ($meta === null) {
            return;
        }

        $className = $meta['class'];

        try {
            $reflectionClass = ModuleFileDiscovery::getReflectionClass($className);
            $attributes = $reflectionClass->getAttributes(Module::class);

            if (!empty($attributes)) {
                $moduleInstance = $attributes[0]->newInstance();

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

        if (PHP_SAPI === 'cli') {
            new RegisterModuleCommand($this->container, $reflectionClass)->init();
        }
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
            $moduleName,
        );
        $requiresRegistrar->init($this->moduleRequirements);
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

    /**
     * Restore module state from cached data, skipping directory scanning and reflection.
     */
    private function restoreFromCache(array $cache): void
    {
        $modules = $cache['modules'] ?? [];
        if (empty($modules)) {
            return;
        }

        foreach ($modules as $name => $data) {
            $this->moduleDirectories[$name] = $data['path'];
            $this->registerModuleAutoloadPath($name, $data['path']);
            $this->moduleMetas[$name] = [
                'class' => $data['class'],
                'order' => $data['order'] ?? PHP_INT_MAX,
                'type' => $data['type'] ?? 'module',
                'core' => $data['core'] ?? false,
            ];
        }

        $sorted = $this->moduleMetas;
        uasort($sorted, fn(array $a, array $b): int => ($a['order'] ?? PHP_INT_MAX) <=> ($b['order'] ?? PHP_INT_MAX));

        foreach ($sorted as $name => $meta) {
            if (!isset($modules[$name])) {
                continue;
            }
            $data = $modules[$name];

            if ($this->isModuleDisabled($name)) {
                continue;
            }

            $className = $data['class'];

            if (isset($this->modules[$name])) {
                continue;
            }

            $this->modules[$name] = $className;

            if (!empty($data['config_defaults'])) {
                $this->config->mergeModuleDefaults($data['config_defaults']);
            }

            if (!empty($data['structure'])) {
                if ($this->container->has(StructureResolver::class)) {
                    $structureResolver = $this->container->get(StructureResolver::class);
                    $structureResolver->registerModuleStructure($name, $data['structure']);
                }
            }

            if (!empty($data['compatibility'])) {
                $compat = $data['compatibility'];
                if (isset($compat['framework'])) {
                    if (!Version::isVersionCompatible(Version::version(), $compat['framework'])) {
                        throw new \RuntimeException(
                            "Module '{$name}' is not compatible with the current framework version. " .
                            "Requires framework version: {$compat['framework']}, current version: " . Version::version()
                        );
                    }
                }
                if (isset($compat['php'])) {
                    if (!Version::isVersionCompatible(PHP_VERSION, $compat['php'])) {
                        throw new \RuntimeException(
                            "Module '{$name}' requires PHP version {$compat['php']} or higher. " .
                            "Your current PHP version is " . PHP_VERSION
                        );
                    }
                }
            }

            $hooksInstance = null;

            foreach ($data['lifecycle_hooks'] ?? [] as $hook) {
                if (in_array($hook['hook'], ['earlyBoot', 'beforeModuleLoad'], true)) {
                    continue;
                }
                $hooksInstance = $hooksInstance ?? $this->container->make($className);
                $hookName = LifecycleHookName::from($hook['hook']);
                $callback = [$hooksInstance, $hook['method']];
                if ($hook['forSelf']) {
                    $wrappedCallback = function (...$args) use ($name, $callback) {
                        $passedModuleName = $args[0] ?? '';
                        if ($passedModuleName === $name) {
                            call_user_func_array($callback, $args);
                        }
                    };
                    HookManager::addHook($hookName, $wrappedCallback);
                } else {
                    HookManager::addHook($hookName, $callback);
                }
            }

            foreach ($data['provides'] ?? [] as $provide) {
                if (!$this->container->has($provide['interface'])) {
                    $this->container->bind($provide['interface'], $provide['class']);
                }
            }

            foreach ($data['services'] ?? [] as $service) {
                $id = $service['id'] ?? $service['class'];
                if (!$this->container->has($id)) {
                    $this->container->bind($id, $service['class'], $service['singleton']);
                }
            }

            if (PHP_SAPI === 'cli' && !empty($data['commands'])) {
                $cliApplication = null;
                try {
                    if ($this->container->has(Application::class)) {
                        $cliApplication = $this->container->get(Application::class);
                    }
                } catch (\Throwable $e) {
                    Logger::log("Loader: failed to get CLI Application", $e->getMessage());
                }
                if ($cliApplication) {
                    $hasCommands = false;
                    foreach ($data['commands'] as $commandClass) {
                        $hasCoreCommand = false;
                        try {
                            $cmdReflection = new \ReflectionClass($commandClass);
                            $hasCoreCommand = !empty($cmdReflection->getAttributes(CoreCommand::class));
                        } catch (\ReflectionException $e) {
                        }
                        $prefix = $hasCoreCommand ? '' : 'modules:';
                        try {
                            $cliApplication->registerCommandClass($commandClass, $prefix);
                            $hasCommands = true;
                        } catch (\Throwable $e) {
                            Logger::log("Loader: failed to register cached command '{$commandClass}'", $e->getMessage());
                        }
                    }
                    if ($hasCommands) {
                        $this->recordModuleHasCommands($name);
                    }
                }
            }

            $reqInterfaces = $data['requires_interfaces'] ?? [];
            $reqModules = $data['requires_modules'] ?? [];
            if (!empty($reqInterfaces) || !empty($reqModules)) {
                $this->moduleRequirements[$name] = [
                    'interfaces' => $reqInterfaces,
                    'modules' => $reqModules,
                ];
            }

            $moduleInstance = $this->container->make($className);
            if (method_exists($moduleInstance, 'register')) {
                $moduleInstance->register($this->container);
            }

            if (isset($this->moduleRequirements[$name])) {
                $this->checkModuleRequirements($name);
            }

            HookManager::triggerHook(
                LifecycleHookName::AFTER_MODULE_REGISTER,
                $name,
                $className,
                $moduleInstance,
            );
        }
    }

    public function loadModuleByNamespace(string $namespacePrefix): void
    {
        $modulesNamespace = StructureResolver::resolveModulesNamespace();
        $prefix = $modulesNamespace . '\\';
        if (!str_starts_with($namespacePrefix, $prefix)) {
            return;
        }

        $moduleName = substr($namespacePrefix, strlen($prefix));
        if ($moduleName === false || $moduleName === '') {
            return;
        }

        // Strip any additional sub-namespace parts
        $parts = explode('\\', $moduleName);
        $moduleName = $parts[0];

        if (isset($this->modules[$moduleName])) {
            return;
        }

        // Ensure autoload path is registered
        if (isset($this->moduleDirectories[$moduleName])) {
            /** @psalm-suppress RedundantCondition */
            if (!isset($this->moduleMetas[$moduleName])) {
                $this->registerModuleAutoloadPath($moduleName, $this->moduleDirectories[$moduleName]);
                $className = $this->resolveModuleClassName($moduleName);
                if (class_exists($className)) {
                    $this->loadModuleMetas();
                }
            }
            $this->loadModule($moduleName);
            $this->checkModuleRequirements($moduleName);
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

    /**
     * Returns the internal module directories map.
     */
    public function getModuleDirectories(): array
    {
        return $this->moduleDirectories;
    }

    /**
     * Returns the internal module metas map.
     */
    public function getModuleMetas(): array
    {
        return $this->moduleMetas;
    }

    /**
     * Reset module state so a fresh full load can be triggered.
     */
    public function resetModules(): void
    {
        $this->modules = [];
        $this->moduleRequirements = [];
        $this->moduleDirectories = [];
        $this->moduleMetas = [];
        $this->earlyHooksRegistered = [];
        $this->earlyHooksDiscovered = false;
        $this->modulesWithCommands = [];
        $this->modulesRootPath = null;
    }
}
