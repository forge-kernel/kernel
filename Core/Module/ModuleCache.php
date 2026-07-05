<?php

declare(strict_types=1);

namespace Forge\Core\Module;

use Forge\CLI\Application;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Attributes\Command as CommandAttr;
use Forge\CLI\Attributes\CoreCommand;
use Forge\CLI\Command;
use Forge\Core\Config\Config;
use Forge\Core\DI\Attributes\Injectable as InjectableAttr;
use Forge\Core\DI\Attributes\Service as ServiceAttr;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Helpers\Logger;
use Forge\Core\Helpers\Version;
use Forge\Core\Module\Attributes\Compatibility;
use Forge\Core\Module\Attributes\ConfigDefaults;
use Forge\Core\Module\Attributes\LifecycleHook as LifecycleHookAttr;
use Forge\Core\Module\Attributes\Module;
use Forge\Core\Module\Attributes\Provides;
use Forge\Core\Module\Attributes\Repository;
use Forge\Core\Module\Attributes\Requires;
use Forge\Core\Module\Attributes\Structure;
use Forge\Core\Module\Helpers\ModuleFileDiscovery;
use Forge\Core\Module\ModuleLoader\Loader;
use Forge\Core\Services\AttributeDiscoveryService;
use Forge\Core\Structure\StructureResolver;
use ReflectionClass;

final class ModuleCache
{
    private const string CACHE_FILE = BASE_PATH . '/storage/framework/cache/module_registrations.php';

    public static function exists(): bool
    {
        return FileExistenceCache::exists(self::CACHE_FILE);
    }

    public static function load(): ?array
    {
        if (!self::exists()) {
            return null;
        }
        try {
            $data = @include self::CACHE_FILE;
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Logger::log("ModuleCache: failed to load cache file", $e->getMessage());
            return null;
        }
    }

    public static function isValid(?array $cache = null): bool
    {
        if ($cache === null) {
            $cache = self::load();
        }
        if ($cache === null) {
            return false;
        }
        $dirMtimes = $cache['dir_mtimes'] ?? [];
        if (empty($dirMtimes)) {
            return false;
        }
        foreach ($dirMtimes as $dir => $cachedMtime) {
            if (!is_dir($dir)) {
                return false;
            }
            $currentMtime = @filemtime($dir);
            if ($currentMtime === false || $currentMtime > $cachedMtime) {
                return false;
            }
        }
        return true;
    }

    public static function build(Container $container, array $moduleMetas, array $moduleDirectories): array
    {
        $modules = [];
        $sorted = $moduleMetas;
        uasort($sorted, fn(array $a, array $b): int => ($a['order'] ?? PHP_INT_MAX) <=> ($b['order'] ?? PHP_INT_MAX));

        foreach ($sorted as $name => $meta) {
            $className = $meta['class'] ?? '';
            if (!$className || !class_exists($className, false)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);
            } catch (\ReflectionException $e) {
                Logger::log("ModuleCache: failed to reflect class '{$className}' for module '{$name}'", $e->getMessage());
                continue;
            }

            $moduleData = [
                'class' => $className,
                'path' => $moduleDirectories[$name] ?? '',
                'order' => $meta['order'] ?? PHP_INT_MAX,
                'type' => $meta['type'] ?? 'module',
                'core' => $meta['core'] ?? false,
            ];

            $configAttr = $reflection->getAttributes(ConfigDefaults::class)[0] ?? null;
            $moduleData['config_defaults'] = $configAttr ? $configAttr->newInstance()->defaults : [];

            $structureAttr = $reflection->getAttributes(Structure::class)[0] ?? null;
            $moduleData['structure'] = $structureAttr ? $structureAttr->newInstance()->structure : null;

            $compatAttr = $reflection->getAttributes(Compatibility::class)[0] ?? null;
            $moduleData['compatibility'] = $compatAttr ? [
                'framework' => $compatAttr->newInstance()->framework,
                'php' => $compatAttr->newInstance()->php,
            ] : null;

            $repoAttr = $reflection->getAttributes(Repository::class)[0] ?? null;
            $moduleData['repository'] = $repoAttr ? [
                'type' => $repoAttr->newInstance()->type,
                'url' => $repoAttr->newInstance()->url,
            ] : null;

            $hooks = [];
            foreach ($reflection->getMethods() as $method) {
                foreach ($method->getAttributes(LifecycleHookAttr::class) as $attr) {
                    $inst = $attr->newInstance();
                    $hooks[] = [
                        'method' => $method->getName(),
                        'hook' => $inst->hook->value,
                        'forSelf' => $inst->forSelf,
                    ];
                }
            }
            $moduleData['lifecycle_hooks'] = $hooks;

            $reqInterfaces = [];
            $reqModules = [];
            foreach ($reflection->getAttributes(Requires::class) as $attr) {
                $inst = $attr->newInstance();
                if ($inst->interface !== null) {
                    $reqInterfaces[$inst->interface] = $inst->version;
                }
                if ($inst->module !== null) {
                    $reqModules[$inst->module] = $inst->version;
                }
            }
            $moduleData['requires_interfaces'] = $reqInterfaces;
            $moduleData['requires_modules'] = $reqModules;

            $provides = [];
            foreach ($reflection->getAttributes(Provides::class) as $attr) {
                $inst = $attr->newInstance();
                $provides[] = ['interface' => $inst->interface, 'class' => $className];
            }

            $moduleData['has_register'] = $reflection->hasMethod('register');

            list($services, $serviceProvides) = self::discoverServicesAndProvides($reflection, $name);
            $provides = array_merge($provides, $serviceProvides);
            $moduleData['services'] = $services;
            $moduleData['provides'] = $provides;

            $moduleData['commands'] = self::discoverCommands($reflection, $name);

            $modules[$name] = $moduleData;
        }

        $dirMtimes = [];
        foreach ($moduleDirectories as $dir) {
            if (is_dir($dir)) {
                $dirMtimes[$dir] = @filemtime($dir) ?: 0;
            }
        }

        return [
            'modules' => $modules,
            'dir_mtimes' => $dirMtimes,
            'built_at' => time(),
        ];
    }

    public static function buildAndSave(Container $container, array $moduleMetas, array $moduleDirectories): void
    {
        $data = self::build($container, $moduleMetas, $moduleDirectories);

        $cacheDir = dirname(self::CACHE_FILE);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $content = '<?php return ' . var_export($data, true) . ';';
        file_put_contents(self::CACHE_FILE, $content);
    }

    public static function clear(): bool
    {
        if (FileExistenceCache::exists(self::CACHE_FILE)) {
            @unlink(self::CACHE_FILE);
            return true;
        }
        return false;
    }

    /**
     * @return array{array, array} [services, provides]
     */
    private static function discoverServicesAndProvides(ReflectionClass $reflection, string $moduleName): array
    {
        $modulePath = dirname($reflection->getFileName());
        $moduleNamespace = $reflection->getNamespaceName();
        $files = ModuleFileDiscovery::discoverPhpFilesInModule($modulePath, $moduleNamespace);

        $services = [];
        $provides = [];

        foreach ($files as $file) {
            if (str_starts_with($file['namespace'], $moduleNamespace . '\\Tests')) {
                continue;
            }
            $fqcn = $file['className'];
            if (!class_exists($fqcn, false)) {
                continue;
            }

            try {
                $classReflection = new ReflectionClass($fqcn);
                $hasService = !empty($classReflection->getAttributes(ServiceAttr::class))
                    || !empty($classReflection->getAttributes(InjectableAttr::class));

                if ($hasService) {
                    $attr = $classReflection->getAttributes(ServiceAttr::class)[0]
                        ?? $classReflection->getAttributes(InjectableAttr::class)[0]
                        ?? null;
                    $serviceId = $attr ? ($attr->newInstance()->id ?? $fqcn) : $fqcn;
                    $singleton = $attr ? $attr->newInstance()->singleton : true;
                    $services[] = ['class' => $fqcn, 'id' => $serviceId, 'singleton' => $singleton];
                }

                foreach ($classReflection->getAttributes(Provides::class) as $attr) {
                    $inst = $attr->newInstance();
                    $provides[] = ['interface' => $inst->interface, 'class' => $fqcn];
                }
            } catch (\ReflectionException $e) {
                continue;
            }
        }

        return [$services, $provides];
    }

    private static function discoverCommands(ReflectionClass $reflection, string $moduleName): array
    {
        $commands = [];

        try {
            $discoveryService = new AttributeDiscoveryService();
            $classMap = $discoveryService->discover(
                ["modules/{$moduleName}/src"],
                [Cli::class, CommandAttr::class]
            );

            foreach ($classMap as $className => $metadata) {
                $hasCli = in_array(Cli::class, $metadata['attributes'] ?? [], true);
                $hasCmd = in_array(CommandAttr::class, $metadata['attributes'] ?? [], true);
                if (!$hasCli && !$hasCmd) {
                    continue;
                }
                if (!is_subclass_of($className, Command::class)) {
                    continue;
                }
                $commands[] = $className;
            }
        } catch (\Throwable $e) {
            Logger::log("ModuleCache: failed to discover commands for module '{$moduleName}'", $e->getMessage());
        }

        return $commands;
    }
}
