<?php

declare(strict_types=1);

use Forge\Core\Cache\CacheManager;
use Forge\Core\Config\Environment;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\Debuger;
use Forge\Core\Config\Config;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;

if (!function_exists("env")) {
    /**
     * Get environment value by key.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Environment::getInstance()->get($key, $default);
    }
}


if (!function_exists("cache")) {
    /**
     * Get cache data by key.
     *
     * @param string $key
     * @param mixed|null $value
     * @param int|null $ttl
     * @return mixed
     * @throws MissingServiceException
     * @throws ReflectionException
     * @throws ResolveParameterException
     */
    function cache(string $key, mixed $value = null, ?int $ttl = null): mixed
    {
        $cache = Container::getInstance()->make(
            CacheManager::class,
        );

        if (func_num_args() === 1) {
            return $cache->get($key);
        }

        $cache->set($key, $value, $ttl);
        return $value;
    }
}

if (!function_exists("config")) {
    /**
     * Get the config value by key.
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     * @throws MissingServiceException
     */
    function config(string $key, mixed $default = null): mixed
    {
        /** @var Config $config */
        $config = Container::getInstance()->make(Config::class);
        return $config->get($key, $default);
    }
}

if (!function_exists("request_host")) {
    /**
     * Get the current request host (domain + port if available).
     *
     */
    function request_host(): string
    {
        $host = $_SERVER["HTTP_HOST"] ?? "localhost";
        return strtolower(trim($host));
    }
}

if (!function_exists("get_data")) {
    /**
     * Get an item from an array or object using dot notation.
     *
     * @param mixed $target The array or object to retrieve from.
     * @param string $key The key, in dot notation (e.g., 'user.address.street').
     * @param mixed $default The default value to return if the key is not found.
     * @return mixed
     */
    function data_get(mixed $target, string $key, mixed $default = null): mixed
    {
        if (empty($key)) {
            return $target;
        }

        $keys = explode(".", $key);
        $current = $target;
        foreach ($keys as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } elseif (
                is_object($current) &&
                property_exists($current, $segment)
            ) {
                $current = $current->$segment;
            } else {
                return $default;
            }
        }

        return $current;
    }
}


if (!function_exists("e")) {
    /**
     * Escape HTML entities in a string.
     *
     * @param mixed $value The string to escape.
     * @return string Returns the escaped string.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");
    }
}

if (!function_exists("raw")) {
    /**
     * Output a value without escaping.
     *
     * @param mixed $value The value to output raw.
     * @return string Returns the raw string representation of the value.
     */
    function raw(mixed $value): string
    {
        return (string) $value;
    }
}

if (!function_exists("tap")) {
    function tap(mixed $value, callable $cb): mixed
    {
        $cb($value);
        return $value;
    }
}


if (!function_exists('dd')) {
    function dd(...$vars): void
    {
        Debuger::dumpAndExit(...$vars);
    }
}
