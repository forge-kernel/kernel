<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\Config\Config;
use Forge\Core\DI\Attributes\Discoverable;
use Forge\Core\DI\Attributes\Injectable;
use Forge\Core\DI\Attributes\Service;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\ModuleHelper;
use Forge\Core\Module\Attributes\LifecycleHook;
use Forge\Core\Module\HookManager;
use Forge\Core\Services\AttributeDiscoveryService;
use Forge\Core\Services\ServiceRegistrationCache;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

final class ServiceDiscoverSetup
{
    private const string CLASS_MAP_CACHE_FILE =
        BASE_PATH . "/storage/framework/cache/class-map.php";

    /**
     * @throws ReflectionException
     * @throws MissingServiceException
     * @throws ResolveParameterException
     */
    public static function setup(Container $container): void
    {
        $cache = ServiceRegistrationCache::load();
        if ($cache !== null && ServiceRegistrationCache::isValid($cache)) {
            ServiceRegistrationCache::restore($container, $cache);
            ReflectionCacheService::saveCache();
            return;
        }

        $discoveryService = new AttributeDiscoveryService();
        $basePaths = self::getBasePaths($container);

        $attributeClasses = [
            Service::class,
            Discoverable::class,
            Injectable::class,
            LifecycleHook::class,
        ];

        $classMap = $discoveryService->discover($basePaths, $attributeClasses, false);

        $filesToCheck = [];
        foreach ($classMap as $className => $metadata) {
            if (!class_exists($className)) {
                $filepath = $metadata['file'] ?? '';
                if ($filepath) {
                    $filesToCheck[] = $filepath;
                }
            }
        }

        if (!empty($filesToCheck)) {
            FileExistenceCache::preload($filesToCheck);
        }

        // Preload all reflection classes to reduce overhead
        $classNames = array_keys($classMap);
        ReflectionCacheService::preloadClassReflections($classNames);

        $cacheServices = [];
        $cacheLifecycleHooks = [];

        foreach ($classMap as $className => $metadata) {
            if (!class_exists($className)) {
                $filepath = $metadata['file'] ?? '';
                if ($filepath && FileExistenceCache::exists($filepath)) {
                    try {
                        require_once $filepath;
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }

            if (!class_exists($className)) {
                continue;
            }

            try {
                $reflectionClass = ReflectionCacheService::getClassReflection($className);
                if (self::hasServiceAttribute($reflectionClass)) {
                    self::registerService($reflectionClass, $container);

                    $serviceAttr = ReflectionCacheService::getClassAttributes($reflectionClass, Service::class);
                    $discoverableAttr = ReflectionCacheService::getClassAttributes($reflectionClass, Discoverable::class);
                    $injectableAttr = ReflectionCacheService::getClassAttributes($reflectionClass, Injectable::class);
                    $attr = $serviceAttr[0] ?? $discoverableAttr[0] ?? $injectableAttr[0] ?? null;
                    if ($attr) {
                        $inst = $attr->newInstance();
                        $cacheServices[$inst->id ?? $reflectionClass->getName()] = [
                            'class' => $reflectionClass->getName(),
                            'singleton' => $inst->singleton,
                        ];
                    } else {
                        $cacheServices[$reflectionClass->getName()] = [
                            'class' => $reflectionClass->getName(),
                            'singleton' => true,
                        ];
                    }
                }

                self::registerServiceLifecycleHooks($reflectionClass, $container, $cacheLifecycleHooks);
            } catch (ReflectionException $e) {

            }
        }
        self::generateLegacyClassMapCache($classMap);

        $fullPaths = array_map(fn(string $p): string => BASE_PATH . '/' . ltrim($p, '/'), $basePaths);
        ServiceRegistrationCache::buildAndSave($cacheServices, [], [], $cacheLifecycleHooks, $fullPaths);

        // Save reflection cache for next request
        ReflectionCacheService::saveCache();
    }

    /**
     * Get base paths to scan for services
     * Excludes disabled modules from discovery
     *
     * @return array<string>
     */
    private static function getBasePaths(Container $container): array
    {
        $config = null;
        try {
            if ($container->has(Config::class)) {
                $config = $container->get(Config::class);
            }
        } catch (\Throwable $e) {
        }

        return OptimizedDirectoryScanner::getServiceDiscoveryPaths($config);
    }

    /**
     * Check if a class has Service or Discoverable attribute
     */
    private static function hasServiceAttribute(ReflectionClass $reflectionClass): bool
    {
        return !empty(ReflectionCacheService::getClassAttributes($reflectionClass, Service::class)) ||
            !empty(ReflectionCacheService::getClassAttributes($reflectionClass, Discoverable::class)) ||
            !empty(ReflectionCacheService::getClassAttributes($reflectionClass, Injectable::class));
    }

    /**
     * @throws ReflectionException
     */
    private static function registerService(ReflectionClass $reflectionClass, Container $container): void
    {
        if (
            !$reflectionClass->isInterface() &&
            !$reflectionClass->isAbstract() &&
            self::hasServiceAttribute($reflectionClass)
        ) {
            $container->register($reflectionClass->getName());
        }
    }

    /**
     * Register lifecycle hooks from services (non-module classes)
     *
     * @throws ReflectionException
     * @throws MissingServiceException
     * @throws ResolveParameterException
     */
    private static function registerServiceLifecycleHooks(ReflectionClass $reflectionClass, Container $container, ?array &$cacheCollector = null): void
    {
        $hasModuleAttribute = !empty(ReflectionCacheService::getClassAttributes($reflectionClass, \Forge\Core\Module\Attributes\Module::class));
        if ($hasModuleAttribute) {
            return;
        }

        $methods = ReflectionCacheService::getClassMethods($reflectionClass, ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            $lifecycleHookAttributes = ReflectionCacheService::getMethodAttributes($method, LifecycleHook::class);
            foreach ($lifecycleHookAttributes as $attribute) {
                $hookInstance = $attribute->newInstance();
                $hookName = $hookInstance->hook;
                $methodName = $method->getName();

                $serviceInstance = $container->has($reflectionClass->getName())
                    ? $container->get($reflectionClass->getName())
                    : $container->make($reflectionClass->getName());

                $callback = [$serviceInstance, $methodName];
                HookManager::addHook($hookName, $callback);

                if ($cacheCollector !== null) {
                    $cacheCollector[$hookName->value][] = [
                        'class' => $reflectionClass->getName(),
                        'method' => $methodName,
                    ];
                }
            }
        }
    }

    /**
     * Generate legacy class map cache for backward compatibility
     *
     * @param array<string, array{file: string, mtime: int, attributes: array<string>}> $classMap
     */
    private static function generateLegacyClassMapCache(array $classMap): void
    {
        if (!FileExistenceCache::isDir(dirname(self::CLASS_MAP_CACHE_FILE))) {
            mkdir(dirname(self::CLASS_MAP_CACHE_FILE), 0777, true);
        }

        $legacyClassMap = [];
        foreach ($classMap as $className => $metadata) {
            $legacyClassMap[$className] = $metadata['file'] ?? '';
        }

        $cacheContent = "<?php return " . var_export($legacyClassMap, true) . ";";
        file_put_contents(self::CLASS_MAP_CACHE_FILE, $cacheContent);
    }
}
