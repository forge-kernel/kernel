<?php

declare(strict_types=1);

namespace Forge\Traits;

/**
 * Trait that provides lifecycle hooks for cache operations.
 * These hooks can be used to perform actions when cache hits, misses, saves, expires, or errors occur.
 */
trait CacheLifecycleHooks
{
    /**
     * Called when a cache hit occurs.
     *
     * @param object $instance The instance that triggered the cache hit
     * @param array $args Additional arguments passed to the cache operation
     * @param string $key The cache key that was hit
     * @param mixed $data The data retrieved from cache
     */
    public static function onCacheHit($instance, $args, $key, $data): void
    {
        echo "Cache hit for {$key}\n";
    }

    /**
     * Called when a cache miss occurs.
     *
     * @param object $instance The instance that triggered the cache miss
     * @param array $args Additional arguments passed to the cache operation
     * @param string $key The cache key that was missed
     */
    public static function onCacheMiss($instance, $args, $key): void
    {
        echo "Cache miss for {$key}\n";
    }

    /**
     * Called when cache data is saved.
     *
     * @param object $instance The instance that triggered the cache save
     * @param array $args Additional arguments passed to the cache operation
     * @param string $key The cache key being saved
     * @param mixed $data The data being saved to cache
     */
    public static function onCacheSave($instance, $args, $key, $data): void
    {
        echo "Saved to cache {$key}\n";
    }

    /**
     * Called when a cache expires.
     *
     * @param object $instance The instance that triggered the cache expiration
     * @param array $args Additional arguments passed to the cache operation
     * @param string $key The cache key that expired
     * @param mixed $data The data that was in the cache before expiration
     */
    public static function onCacheExpired($instance, $args, $key, $data): void
    {
        echo "Cache for {$key}\n expired";
    }

    /**
     * Called when a cache error occurs.
     *
     * @param object $instance The instance that triggered the cache error
     * @param array $args Additional arguments passed to the cache operation
     * @param string $key The cache key that caused the error
     * @param \Exception $e The exception that was thrown
     */
    public static function onCacheError($instance, $args, $key, $e): void
    {
        echo "Cache error for {$key}: {$e->getMessage()}\n";
    }
}
