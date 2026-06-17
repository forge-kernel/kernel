<?php

declare(strict_types=1);

namespace Forge\Core\Cache\Attributes;

use Attribute;
use DateInterval;

/**
 * Advanced caching DSL attribute.
 *
 *  #[Cache(
 *      key: 'user-{id}-{slug|substr:0,10}',   // arg, filter, function
 *      ttl: 3600,                             // int, env, callback
 *      driver: 'redis',                       // array/apcu/redis/psr6/psr16/null
 *      tags: ['user-{id}', 'global'],         // invalidated together
 *      condition: 'args["active"] === true',  // php expression
 *      stale: 0.10,                           // 10 % probability to return stale
 *      negativeTtl: 300,                      // how long to cache null/false
 *      serializer: 'igbinary',                // php/igbinary/msgpack/json
 *      onHit: [MyListener::class, 'onHit'],
 *      onMiss: fn() => logger()->info('miss')
 *  )]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Cache
{
    public function __construct(
        public ?string $key = null,
        public string|int|\DateInterval|null $ttl = null,
        public string|array|null $tags = null,
        public mixed $condition = null,
        public string|null $driver = null,
        public float $stale = 0.0,
        public int $negativeTtl = 60,
        public string $serializer = "php",
        public mixed $onHit = null,
        public mixed $onMiss = null,
        public mixed $onSave = null,
        public mixed $onError = null,
        public mixed $onExpired = null,
    ) {}

    public function resolveKey(
        object $instance,
        \ReflectionMethod $method,
        array $args,
    ): string {
        $template = $this->key ?? $this->defaultKey($instance, $method, $args);
        return $this->interpolate($template, $instance, $args);
    }

    public function resolveTtl(object $instance, array $args): ?int
    {
        $ttl = $this->ttl;
        if ($ttl instanceof \DateInterval) {
            $dt = new \DateTime();
            $dt->add($ttl);
            return $dt->getTimestamp() - time();
        }
        if (is_string($ttl) && str_contains($ttl, "env(")) {
            $ttl = $this->interpolate($ttl, $instance, $args);
        }
        if (is_string($ttl) && str_starts_with($ttl, "@")) {
            $ttl = $this->evaluate(substr($ttl, 1), $instance, $args);
        }
        return is_numeric($ttl) ? (int) $ttl : null;
    }

    public function shouldCache(object $instance, array $args): bool
    {
        if ($this->condition === null) {
            return true;
        }
        if (is_callable($this->condition)) {
            return ($this->condition)($instance, $args);
        }
        return (bool) $this->evaluate($this->condition, $instance, $args);
    }

    public function resolveTags(object $instance, array $args): array
    {
        $tags = (array) ($this->tags ?? []);
        return array_map(
            fn($t) => $this->interpolate((string) $t, $instance, $args),
            $tags,
        );
    }

    private function defaultKey(
        object $instance,
        \ReflectionMethod $method,
        array $args,
    ): string {
        $hash = md5(serialize($args));
        return sprintf(
            "%s::%s:%s",
            $instance::class,
            $method->getName(),
            $hash,
        );
    }

    private function interpolate(
        string $template,
        object $instance,
        array $args,
    ): string {
        $template = str_replace("->", ".", $template);

        return preg_replace_callback(
            "/\{([\w.:@\-_|>\\\\()\[\]]+)\}/",
            function ($m) use ($instance, $args) {
                $expr = trim($m[1]);
                if (str_starts_with($expr, "env(")) {
                    return getenv(trim($expr, "env()'\"")) ?: "";
                }
                if (str_starts_with($expr, "const(")) {
                    return constant(trim($expr, "const()'\"")) ?: "";
                }
                if (str_starts_with($expr, "config(")) {
                    $key = trim($expr, "config()'\"");
                    return config($key) ?? "";
                }
                if (str_contains($expr, "|")) {
                    [$var, $filter] = explode("|", $expr, 2);
                    $value = $this->resolveVariable($var, $instance, $args);
                    return $this->applyFilter($value, $filter);
                }
                return (string) $this->resolveVariable($expr, $instance, $args);
            },
            $template,
        );
    }

    private function resolveVariable(
        string $name,
        object $instance,
        array $args,
    ): mixed {
        $name = str_replace("->", ".", $name);

        if (array_key_exists($name, $args)) {
            return $args[$name];
        }

        if (property_exists($instance, $name)) {
            return $instance->$name;
        }

        if (method_exists($instance, $name)) {
            return $instance->$name();
        }

        if (str_contains($name, ".")) {
            [$root, $rest] = explode(".", $name, 2);
            $rootVal = $this->resolveVariable($root, $instance, $args);
            return data_get($rootVal, $rest);
        }

        return "";
    }

    private function applyFilter(mixed $value, string $filter): string
    {
        [$fn, $params] = array_pad(explode(":", $filter, 2), 2, "");
        $args = $params === "" ? [] : explode(",", $params);
        return match ($fn) {
            "substr" => substr(
                (string) $value,
                (int) $args[0],
                (int) ($args[1] ?? PHP_INT_MAX),
            ),
            "md5" => md5(is_scalar($value) ? (string) $value : serialize($value)),
            "sha1" => sha1(is_scalar($value) ? (string) $value : serialize($value)),
            "lower" => strtolower((string) $value),
            "upper" => strtoupper((string) $value),
            default => (string) $value,
        };
    }

    private function evaluate(
        string $expression,
        object $instance,
        array $args,
    ): mixed {
        $_args = $args;
        return eval("return " . $expression . ";");
    }

    public function resolveKeyWithContext(
        object $instance,
        \ReflectionMethod $method,
        array $context,
    ): string {
        $template =
            $this->key ?? $this->defaultKey($instance, $method, $context);
        return $this->interpolate($template, $instance, $context);
    }
}
