<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\Helpers\FileExistenceCache;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;

final class ReflectionCacheService
{
    private const CACHE_FILE = BASE_PATH . '/storage/framework/cache/reflection_cache.php';
    
    private static array $classCache = [];
    private static array $methodCache = [];
    private static array $propertyCache = [];
    private static bool $loaded = false;

    /**
     * Get cached reflection class or create new one
     */
    public static function getClassReflection(string $className): ReflectionClass
    {
        if (isset(self::$classCache[$className])) {
            return self::$classCache[$className];
        }

        try {
            $reflection = new ReflectionClass($className);
            self::$classCache[$className] = $reflection;
            return $reflection;
        } catch (ReflectionException $e) {
            throw $e;
        }
    }

    /**
     * Get methods for a class efficiently
     */
    public static function getClassMethods(ReflectionClass $class, ?int $filter = null): array
    {
        $className = $class->getName();
        $cacheKey = $className . '_' . ($filter ?? 'all');

        if (isset(self::$methodCache[$cacheKey])) {
            return self::$methodCache[$cacheKey];
        }

        $methods = $class->getMethods($filter);
        self::$methodCache[$cacheKey] = $methods;
        
        return $methods;
    }

    /**
     * Get properties for a class efficiently
     */
    public static function getClassProperties(ReflectionClass $class, ?int $filter = null): array
    {
        $className = $class->getName();
        $cacheKey = $className . '_' . ($filter ?? 'all');

        if (isset(self::$propertyCache[$cacheKey])) {
            return self::$propertyCache[$cacheKey];
        }

        $properties = $class->getProperties($filter);
        self::$propertyCache[$cacheKey] = $properties;
        
        return $properties;
    }

    /**
     * Get attributes for a class efficiently
     */
    public static function getClassAttributes(ReflectionClass $class, ?string $attributeClass = null): array
    {
        $className = $class->getName();
        $cacheKey = $className . '_attrs_' . ($attributeClass ?? 'all');

        if (isset(self::$classCache[$cacheKey])) {
            return self::$classCache[$cacheKey];
        }

        $attributes = $attributeClass 
            ? $class->getAttributes($attributeClass)
            : $class->getAttributes();
            
        self::$classCache[$cacheKey] = $attributes;
        
        return $attributes;
    }

    /**
     * Get method attributes efficiently
     */
    public static function getMethodAttributes(ReflectionMethod $method, ?string $attributeClass = null): array
    {
        $className = $method->getDeclaringClass()->getName();
        $methodName = $method->getName();
        $cacheKey = $className . '_' . $methodName . '_attrs_' . ($attributeClass ?? 'all');

        if (isset(self::$methodCache[$cacheKey])) {
            return self::$methodCache[$cacheKey];
        }

        $attributes = $attributeClass 
            ? $method->getAttributes($attributeClass)
            : $method->getAttributes();
            
        self::$methodCache[$cacheKey] = $attributes;
        
        return $attributes;
    }

    /**
     * Get property attributes efficiently
     */
    public static function getPropertyAttributes(ReflectionProperty $property, ?string $attributeClass = null): array
    {
        $className = $property->getDeclaringClass()->getName();
        $propertyName = $property->getName();
        $cacheKey = $className . '_' . $propertyName . '_attrs_' . ($attributeClass ?? 'all');

        if (isset(self::$propertyCache[$cacheKey])) {
            return self::$propertyCache[$cacheKey];
        }

        $attributes = $attributeClass 
            ? $property->getAttributes($attributeClass)
            : $property->getAttributes();
            
        self::$propertyCache[$cacheKey] = $attributes;
        
        return $attributes;
    }

    /**
     * Load cache from disk if available
     */
    public static function loadCache(): void
    {
        if (self::$loaded) {
            return;
        }

        if (!FileExistenceCache::exists(self::CACHE_FILE)) {
            self::$loaded = true;
            return;
        }

        try {
            $data = include self::CACHE_FILE;
            if (is_array($data)) {
                self::$classCache = $data['classCache'] ?? [];
                self::$methodCache = $data['methodCache'] ?? [];
                self::$propertyCache = $data['propertyCache'] ?? [];
            }
        } catch (\Throwable $e) {
            // Cache corrupted, start fresh
            self::clearCache();
        }

        self::$loaded = true;
    }

    /**
     * Save cache to disk
     */
    public static function saveCache(): void
    {
        $directory = dirname(self::CACHE_FILE);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $data = [
            'classCache' => self::$classCache,
            'methodCache' => self::$methodCache,
            'propertyCache' => self::$propertyCache,
        ];

        $content = '<?php return ' . var_export($data, true) . ';';
        file_put_contents(self::CACHE_FILE, $content);
    }

    /**
     * Clear all reflection caches
     */
    public static function clearCache(): void
    {
        self::$classCache = [];
        self::$methodCache = [];
        self::$propertyCache = [];
        self::$loaded = true;

        if (FileExistenceCache::exists(self::CACHE_FILE)) {
            @unlink(self::CACHE_FILE);
        }
    }

    /**
     * Get cache statistics
     */
    public static function getCacheStats(): array
    {
        self::loadCache();
        
        return [
            'cache_exists' => FileExistenceCache::exists(self::CACHE_FILE),
            'class_cache_size' => count(self::$classCache),
            'method_cache_size' => count(self::$methodCache),
            'property_cache_size' => count(self::$propertyCache),
            'total_cached_items' => count(self::$classCache) + count(self::$methodCache) + count(self::$propertyCache)
        ];
    }

    /**
     * Preload reflections for multiple classes
     */
    public static function preloadClassReflections(array $classNames): void
    {
        foreach ($classNames as $className) {
            if (!isset(self::$classCache[$className]) && class_exists($className)) {
                try {
                    self::getClassReflection($className);
                } catch (ReflectionException $e) {
                    // Class doesn't exist or can't be reflected, skip
                    continue;
                }
            }
        }
    }
}