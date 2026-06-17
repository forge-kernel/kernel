<?php

declare(strict_types=1);

namespace Forge\Core\Cache;

use App\Modules\ForgeSqlOrm\ORM\Model;
use Forge\Core\Cache\Drivers\FileCacheDriver;
use Forge\Core\Cache\Drivers\MemoryCacheDriver;
use Forge\Core\Cache\Drivers\SqliteCacheDriver;
use Forge\Core\Cache\Traits\CacheTrait;
use Forge\Core\Config\Environment;
use JsonException;

class CacheManager
{
    use CacheTrait;

    private CacheDriverInterface $driver;
    private array $tags = [];

    public function __construct(?string $driver = null)
    {
        $env = Environment::getInstance();
        $driver = $driver ?? $env->get('CACHE_DRIVER', 'sqlite');

        $this->driver = match ($driver) {
            'memory' => new MemoryCacheDriver(),
            'sqlite' => new SqliteCacheDriver(),
            default => new FileCacheDriver(),
        };
    }

    /**
     * @throws JsonException
     */
    public function get(string $key): mixed
    {
        $raw = $this->driver->get($key);
        return $this->handleData($raw);
    }

    public function tags(array $tags): self
    {
        $clone = clone $this;
        $clone->tags = $tags;
        return $clone;
    }

    public function clearTag(string $tag): void
    {
        foreach ($this->driver->keys() as $key) {
            $data = $this->driver->get($key);
            if (isset($data['tags']) && in_array($tag, $data['tags'])) {
                $this->driver->delete($key);
            }
        }
    }

    public function delete(string $key): void
    {
        $this->driver->delete($key);
    }

    /**
     * @throws JsonException
     */
    public function getExpired(string $key): mixed
    {
        $raw = $this->driver->getExpired($key);
        return $this->handleData($raw);
    }

    public function getRawEntry(string $key): mixed
    {
        return $this->driver->getRawEntry($key);
    }

    /**
     * @throws JsonException
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $class = null;
        $data = $value;

        if ($value instanceof Model) {
            $class = get_class($value);
            $data = $value->toArray();
        } elseif ($value instanceof \App\Modules\ForgeSqlOrm\ORM\Paginator) {
            $class = get_class($value);
            $data = $value->toArray();
        } else {
            $data = $value;
        }

        $payload = [
            'c' => $class,
            'd' => $data,
        ];

        $this->driver->set($key, json_encode($payload, JSON_THROW_ON_ERROR), $ttl);
    }

    public function clear(): void
    {
        $this->driver->clear();
    }
}
