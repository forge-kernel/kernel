<?php

declare(strict_types=1);

namespace Forge\Core\Cache;

require_once __DIR__ . "/Attributes/Cache.php";

use Forge\Core\Cache\Attributes\Cache;
use Forge\Core\Cache\Traits\CacheTrait;
use Forge\Core\Contracts\EventDispatcherInterface;
use Forge\Core\DI\Container;
use Forge\Exceptions\MissingServiceException;
use ReflectionException;
use ReflectionMethod;
use Throwable;

final class CacheInterceptor
{
    use CacheTrait;

    private static array $methodCache = [];
    private static array $attributeCache = [];
    private static array $parameterCache = [];

    public function __construct(private CacheManager $cache)
    {
    }

    /**
     * Invoke a method through the cache layer.
     *
     * @throws ReflectionException
     */
    public function call(
        object $instance,
        string $methodName,
        array  $args = [],
    ): mixed
    {
        if ($instance instanceof ProxyMarkerInterface) {
            throw new \LogicException(
                "CacheInterceptor received the proxy instead of the real object. " .
                "Check ProxyGenerator::wrap().",
            );
        }

        $class = get_class($instance);
        $cacheKey = $class . '::' . $methodName;

        if (!isset(self::$methodCache[$cacheKey])) {
            if (!isset(self::$attributeCache[$class])) {
                $classReflection = new \ReflectionClass($instance);
                $cacheableMethods = [];
                foreach ($classReflection->getMethods() as $method) {
                    if ($method->getAttributes(Cache::class)) {
                        $cacheableMethods[] = $method->getName();
                    }
                }
                self::$attributeCache[$class] = $cacheableMethods;
            }

            if (!in_array($methodName, self::$attributeCache[$class], true)) {
                self::$methodCache[$cacheKey] = ['cached' => false];
            } else {
                $method = new ReflectionMethod($instance, $methodName);
                if (!isset(self::$parameterCache[$cacheKey])) {
                    self::$parameterCache[$cacheKey] = $method->getParameters();
                }

                self::$methodCache[$cacheKey] = [
                    'cached' => true,
                    'method' => $method,
                    'attributes' => $method->getAttributes(Cache::class),
                    'parameters' => self::$parameterCache[$cacheKey]
                ];
            }
        }

        $cachedMethod = self::$methodCache[$cacheKey];

        if (!$cachedMethod['cached']) {
            return (new ReflectionMethod($instance, $methodName))->invokeArgs($instance, $args);
        }

        $method = $cachedMethod['method'];
        $attributes = $cachedMethod['attributes'];

        if ($attributes === []) {
            return $method->invokeArgs($instance, $args);
        }

        /** @var Cache $attr */
        $attr = $attributes[0]->newInstance();

        if (!$attr->shouldCache($instance, $args)) {
            return $method->invokeArgs($instance, $args);
        }

        $manager =
            $attr->driver === null
                ? $this->cache
                : new CacheManager($attr->driver);

        $namedArgs = [];
        foreach ($cachedMethod['parameters'] as $i => $p) {
            $namedArgs[$p->getName()] = $args[$i] ?? $p->getDefaultValue();
        }

        $context = array_merge($namedArgs, get_object_vars($instance));
        $key = $attr->resolveKeyWithContext($instance, $method, $context);

        try {
            $cachedEntry = $manager->getRawEntry($key);
            $stale = null;
            $expired = null;

            if ($cachedEntry !== null) {
                $now = time();
                $expiresAt = $cachedEntry["expires_at"] ?? null;

                if ($expiresAt === null || $expiresAt > $now) {
                    if ($attr->onHit) {
                        ($attr->onHit)(
                            $instance,
                            $args,
                            $key,
                            $cachedEntry["value"],
                        );
                    }
                    return $this->handleData($cachedEntry["value"]);
                }

                if ($attr->stale > 0 && $expiresAt + $attr->stale > $now) {
                    $stale = $cachedEntry["value"];
                    $this->refreshAsync(
                        $instance,
                        $method,
                        $args,
                        $attr,
                        $manager,
                        $key,
                        $ttl ?? $attr->ttl,
                        $attr->tags
                            ? $attr->resolveTags($instance, $args)
                            : null,
                    );
                    if ($attr->onHit) {
                        ($attr->onHit)($instance, $args, $key, $stale);
                    }
                    return $this->handleData($stale);
                }

                $expired = $cachedEntry["value"];
                if ($attr->onExpired) {
                    ($attr->onExpired)($instance, $args, $key, $expired);
                }

                $this->refreshAsync(
                    $instance,
                    $method,
                    $args,
                    $attr,
                    $manager,
                    $key,
                    $ttl ?? $attr->ttl,
                    $attr->tags ? $attr->resolveTags($instance, $args) : null,
                );

                return $this->handleData($expired);
            }

            $cached = $manager->get($key);
            if ($cached !== null) {
                if ($attr->onHit) {
                    ($attr->onHit)($instance, $args, $key, $cached);
                }
                return $cached;
            }

            if ($attr->onMiss) {
                ($attr->onMiss)($instance, $args, $key);
            }

            $result = $method->invokeArgs($instance, $args);

            if ($result === null && $attr->negativeTtl !== null) {
                $manager->set($key, null, $attr->negativeTtl);
                if ($attr->onSave) {
                    ($attr->onSave)($instance, $args, $key, null);
                }
                return null;
            }

            $ttl = $attr->resolveTtl($instance, $args) ?? ($attr->ttl ?? 0);

            if ($attr->tags !== null && method_exists($manager, "tags")) {
                $manager
                    ->tags($attr->resolveTags($instance, $args))
                    ->set($key, $result, $ttl);
            } else {
                $manager->set($key, $result, $ttl);
            }

            if ($attr->onSave) {
                ($attr->onSave)($instance, $args, $key, $result);
            }

            return $result;
        } catch (Throwable $e) {
            if ($attr->onError) {
                ($attr->onError)($instance, $args, $key, $e);
            }

            throw $e;
        }
    }

    /**
     * @throws MissingServiceException
     * @throws EventException
     */
    private function refreshAsync(
        object           $instance,
        ReflectionMethod $method,
        array            $args,
        Cache            $attr,
        CacheManager     $manager,
        string           $key,
        ?int             $ttl = null,
        ?array           $tags = null,
    ): void
    {
        try {
            $dispatcher = Container::getInstance()->get(EventDispatcherInterface::class);
        } catch (\Throwable) {
            return;
        }

        $eventClass = 'App\Modules\ForgeEvents\Events\CacheRefreshEvent';

        $dispatcher->dispatch(
            new $eventClass(
                instance: $instance,
                method: $method->getName(),
                args: $args,
                key: $key,
                driver: $attr->driver,
                ttl: $ttl,
                tags: $tags,
            ),
        );
    }
}
