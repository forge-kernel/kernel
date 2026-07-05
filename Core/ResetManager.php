<?php
declare(strict_types=1);

namespace Forge\Core;

final class ResetManager
{
    /** @var list<\Closure|array{0: class-string, 1: string}> */
    private static array $before = [];

    /** @var list<\Closure|array{0: class-string, 1: string}> */
    private static array $after = [];

    public static function onBefore(\Closure|array $callback): void
    {
        self::$before[] = $callback;
    }

    public static function onAfter(\Closure|array $callback): void
    {
        self::$after[] = $callback;
    }

    public static function triggerBefore(): void
    {
        foreach (self::$before as $callback) {
            if ($callback instanceof \Closure) {
                $callback();
            } else {
                [$class, $method] = $callback;
                self::invokeArrayCallback($class, $method);
            }
        }
    }

    public static function triggerAfter(): void
    {
        foreach (self::$after as $callback) {
            if ($callback instanceof \Closure) {
                $callback();
            } else {
                [$class, $method] = $callback;
                self::invokeArrayCallback($class, $method);
            }
        }
    }

    private static function invokeArrayCallback(string $class, string $method): void
    {
        $refMethod = new \ReflectionMethod($class, $method);
        if ($refMethod->isStatic()) {
            $class::$method();
        } else {
            $instance = new $class();
            $instance->$method();
        }
    }
}
