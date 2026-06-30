<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\Contracts\EventDispatcherInterface;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Module\HookManager;
use Forge\Core\Module\LifecycleHookName;
use Forge\Core\DI\Attributes\Injectable;

#[Injectable]
final class ServiceRegistrationCache
{
    private const string CACHE_FILE = BASE_PATH . '/storage/framework/cache/service_registrations.php';

    public static function load(): ?array
    {
        if (!is_file(self::CACHE_FILE)) {
            return null;
        }
        try {
            $data = @include self::CACHE_FILE;
            if (!is_array($data)) {
                return null;
            }
            return $data;
        } catch (\Throwable) {
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
        $scannedDirs = $cache['dir_mtimes'] ?? [];
        if (empty($scannedDirs)) {
            return false;
        }
        foreach ($scannedDirs as $dir => $cachedMtime) {
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

    public static function buildAndSave(array $services, array $tags, array $eventListeners, array $lifecycleHooks, array $scannedDirs, array $registerBindings = []): void
    {
        $cacheDir = dirname(self::CACHE_FILE);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $dirMtimes = [];
        foreach ($scannedDirs as $dir) {
            if (is_dir($dir)) {
                $dirMtimes[$dir] = @filemtime($dir) ?: 0;
            }
        }

        $data = [
            'services' => $services,
            'tags' => $tags,
            'event_listeners' => $eventListeners,
            'lifecycle_hooks' => $lifecycleHooks,
            'register_bindings' => $registerBindings,
            'dir_mtimes' => $dirMtimes,
        ];

        $content = '<?php return ' . var_export($data, true) . ';';
        file_put_contents(self::CACHE_FILE, $content);
    }

    public static function restore(Container $container, array $cache): void
    {
        foreach ($cache['services'] ?? [] as $id => $config) {
            if (!$container->has($id)) {
                $container->bind($id, $config['class'], $config['singleton']);
            }
        }

        foreach ($cache['tags'] ?? [] as $tag => $abstracts) {
            $container->tag($tag, $abstracts);
        }

        if (!empty($cache['event_listeners'])) {
            try {
                $eventDispatcher = $container->get(EventDispatcherInterface::class);
            } catch (\Throwable) {
                return;
            }
            foreach ($cache['event_listeners'] as $eventClass => $listeners) {
                foreach ($listeners as $listener) {
                    $instance = $container->has($listener['class'])
                        ? $container->get($listener['class'])
                        : $container->make($listener['class']);
                    $eventDispatcher->addListener($eventClass, [$instance, $listener['method']]);
                }
            }
        }

        foreach ($cache['register_bindings'] ?? [] as $binding) {
            if (!$container->has($binding['id'])) {
                $container->bind($binding['id'], $binding['concrete'], $binding['singleton']);
            }
        }

        foreach ($cache['lifecycle_hooks'] ?? [] as $hookName => $callbacks) {
            try {
                $lifecycleHook = LifecycleHookName::from($hookName);
            } catch (\Throwable) {
                continue;
            }
            foreach ($callbacks as $callback) {
                try {
                    $instance = $container->has($callback['class'])
                        ? $container->get($callback['class'])
                        : $container->make($callback['class']);
                    HookManager::addHook($lifecycleHook, [$instance, $callback['method']]);
                } catch (\Throwable) {
                }
            }
        }
    }

    public static function clear(): void
    {
        if (FileExistenceCache::exists(self::CACHE_FILE)) {
            @unlink(self::CACHE_FILE);
        }
    }

    public static function getCacheFilePath(): string
    {
        return self::CACHE_FILE;
    }
}
