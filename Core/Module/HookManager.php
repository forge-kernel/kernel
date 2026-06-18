<?php

declare(strict_types=1);

namespace Forge\Core\Module;

use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;

final class HookManager
{
    private static array $hooks = [];
    private static bool $compiledHooksLoaded = false;
    private static ?array $compiledHookData = null;
    private static ?Container $container = null;

    public static function addHook(LifecycleHookName $hookName, callable|array $callback): void
    {
        $name = $hookName->value;

        if (!isset(self::$hooks[$name])) {
            self::$hooks[$name] = [];
        }

        foreach (self::$hooks[$name] as $registeredCallback) {
            if (
                is_array($registeredCallback) && is_array($callback) &&
                $registeredCallback[0] === $callback[0] && $registeredCallback[1] === $callback[1]
            ) {
                return;
            } elseif ($registeredCallback === $callback) {
                return;
            }
        }

        self::$hooks[$name][] = $callback;
    }

    public static function triggerHook(LifecycleHookName $hookName, ...$args): void
    {
        if (!self::$compiledHooksLoaded) {
            self::loadCompiledHooks();
            self::$compiledHooksLoaded = true;
        }

        $name = $hookName->value;
        self::resolveCompiledHooksFor($name);

        if (isset(self::$hooks[$name])) {
            foreach (self::$hooks[$name] as $callback) {
                if (is_callable($callback)) {
                    call_user_func_array($callback, $args);
                } elseif (is_array($callback) && count($callback) === 2 && method_exists($callback[0], $callback[1])) {
                    call_user_func_array($callback, $args);
                } else {
                    self::invalidateCompiledHooks();
                }
            }
        }
    }

    private static function loadCompiledHooks(): void
    {
        $compiledFile = BASE_PATH . '/storage/framework/cache/compiled_hooks.php';
        if (FileExistenceCache::exists($compiledFile)) {
            self::$compiledHookData = include $compiledFile;
        }
    }

    private static function resolveCompiledHooksFor(string $hookName): void
    {
        if (self::$compiledHookData === null || !isset(self::$compiledHookData[$hookName])) {
            return;
        }

        foreach (self::$compiledHookData[$hookName] as $hook) {
            if ($hook['type'] !== 'method') {
                continue;
            }

            if (self::isHookAlreadyRegistered($hookName, $hook['class'], $hook['method'])) {
                continue;
            }

            try {
                if (!method_exists($hook['class'], $hook['method'])) {
                    self::invalidateCompiledHooks();
                    return;
                }

                if (self::$container && self::$container->has($hook['class'])) {
                    $instance = self::$container->get($hook['class']);
                } elseif (self::$container) {
                    $instance = self::$container->make($hook['class']);
                } else {
                    $instance = new $hook['class']();
                }

                $callback = [$instance, $hook['method']];
                $name = LifecycleHookName::from($hookName);
                self::addHook($name, $callback);
            } catch (\Throwable $e) {
                error_log("Failed to load compiled hook {$hook['class']}::{$hook['method']}: " . $e->getMessage());
            }
        }

        unset(self::$compiledHookData[$hookName]);
    }

    private static function isHookAlreadyRegistered(string $hookName, string $class, string $method): bool
    {
        if (!isset(self::$hooks[$hookName])) {
            return false;
        }

        foreach (self::$hooks[$hookName] as $cb) {
            if (is_array($cb) && count($cb) === 2) {
                $cbClass = is_object($cb[0]) ? get_class($cb[0]) : $cb[0];
                if ($cbClass === $class && $cb[1] === $method) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function setCompiledHookData(array $data): void
    {
        self::$compiledHookData = $data;
        self::$compiledHooksLoaded = true;
    }

    public static function setContainer(Container $container): void
    {
        self::$container = $container;
    }

    public static function invalidateCompiledHooks(): void
    {
        $compiledFile = BASE_PATH . '/storage/framework/cache/compiled_hooks.php';
        if (file_exists($compiledFile)) {
            unlink($compiledFile);
        }
        self::$compiledHookData = null;
        self::debugResetHooks();
    }

    public static function debugResetHooks(): void
    {
        self::$hooks = [];
        self::$compiledHooksLoaded = false;
        self::$compiledHookData = null;
    }

    public static function debugGetHooks(): array
    {
        return self::$hooks;
    }
}
