<?php

declare(strict_types=1);

namespace Forge\Core\Cache\Drivers;

use Forge\Core\Cache\CacheDriverInterface;

final class MemoryCacheDriver implements CacheDriverInterface
{
    private array $storage = [];

    public function keys(): array
    {
        return array_keys($this->storage);
    }

    public function getRawEntry(string $key): ?array
    {
        return $this->storage[$key] ?? null;
    }

    public function get(string $key): mixed
    {
        $entry = $this->storage[$key] ?? null;
        if (!$entry) {
            return null;
        }
        return $entry['value'];
    }

    public function getExpired(string $key): mixed
    {
        $entry = $this->storage[$key] ?? null;
        if (!$entry) {
            return null;
        }

        if (!isset($entry['expires_at']) || $entry['expires_at'] > time()) {
            return null;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->storage[$key] = [
            'value' => $value,
            'expires_at' => $ttl ? time() + $ttl : null,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->storage[$key]);
    }

    public function clear(): void
    {
        $this->storage = [];
    }
}
