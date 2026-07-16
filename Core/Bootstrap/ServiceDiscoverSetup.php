<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\DI\Container;
use Forge\Core\Helpers\ModuleHelper;
use Forge\Core\Module\Attributes\LifecycleHook;
use Forge\Core\Module\HookManager;
use Forge\Core\Structure\StructureResolver;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

final class ServiceDiscoverSetup
{
    /**
     * @throws ReflectionException
     * @throws MissingServiceException
     * @throws ResolveParameterException
     */
    public static function setup(Container $container): void
    {
        foreach (self::getBasePaths() as ['path' => $path, 'namespace' => $namespace]) {
            self::scanDirectory($path, $namespace, $container);
        }
    }

    /**
     * @return array<int, array{path: string, namespace: string}>
     */
    private static function getBasePaths(): array
    {
        $structureResolver = new StructureResolver();
        $result = [];

        foreach ($structureResolver->getAppPaths('injectable') as $path) {
            $fullPath = BASE_PATH . '/' . $path;
            if (is_dir($fullPath)) {
                $result[] = [
                    'path' => $fullPath,
                    'namespace' => $structureResolver->getAppNamespace('injectable', $path),
                ];
            }
        }

        foreach ($structureResolver->getModulesRoots() as $modulesRoot) {
            $modulesPath = BASE_PATH . '/' . $modulesRoot;
            if (!is_dir($modulesPath)) {
                continue;
            }
            foreach (scandir($modulesPath) as $moduleName) {
                if ($moduleName === '.' || $moduleName === '..') {
                    continue;
                }
                if (ModuleHelper::isModuleDisabled($moduleName)) {
                    continue;
                }

                try {
                    foreach ($structureResolver->getModulePaths($moduleName, 'injectable') as $modulePath) {
                        $fullPath = $modulesPath . '/' . $moduleName . '/' . $modulePath;
                        if (is_dir($fullPath)) {
                            $result[] = [
                                'path' => $fullPath,
                                'namespace' => $structureResolver->getModuleNamespace($moduleName, 'injectable', $modulePath),
                            ];
                        }
                    }
                } catch (\InvalidArgumentException) {
                    continue;
                }
            }
        }

        return $result;
    }

    private static function scanDirectory(string $dir, string $namespace, Container $container): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($dir) + 1);
            $fqcn = $namespace . '\\' . str_replace('/', '\\', substr($relativePath, 0, -4));

            if (!class_exists($fqcn)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($fqcn);
                if (!$reflection->isAbstract() && !$reflection->isInterface()) {
                    $container->register($fqcn);
                }
                self::registerLifecycleHooks($reflection, $container);
            } catch (ReflectionException) {
                continue;
            }
        }
    }

    /**
     * @throws MissingServiceException
     * @throws ResolveParameterException
     */
    private static function registerLifecycleHooks(ReflectionClass $reflection, Container $container): void
    {
        if (!empty($reflection->getAttributes(\Forge\Core\Module\Attributes\Module::class))) {
            return;
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(LifecycleHook::class) as $attr) {
                $hookInstance = $attr->newInstance();
                $serviceInstance = $container->has($reflection->getName())
                    ? $container->get($reflection->getName())
                    : $container->make($reflection->getName());
                HookManager::addHook($hookInstance->hook, [$serviceInstance, $method->getName()]);
            }
        }
    }
}
