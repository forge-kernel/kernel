<?php

declare(strict_types=1);

namespace Forge\Core\Module;

use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;

final class HookManager
{
    private static array $hooks = [];
    private static bool $compiledHooksLoaded = false;
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
        $compileFileExists = FileExistenceCache::exists($compiledFile);
        if ($compileFileExists) {
            $compiledHooks = include $compiledFile;

            foreach ($compiledHooks as $hookName => $hooks) {
                foreach ($hooks as $hook) {
                    if ($hook['type'] === 'method') {
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
                }
            }
        }
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
        self::debugResetHooks();
    }

    public static function debugResetHooks(): void
    {
        self::$hooks = [];
        self::$compiledHooksLoaded = false;
    }

    public static function debugGetHooks(): array
    {
        return self::$hooks;
    }
}
