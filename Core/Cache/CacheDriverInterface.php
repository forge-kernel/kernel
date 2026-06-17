<?php

declare(strict_types=1);

namespace Forge\Core\Cache;

interface CacheDriverInterface
{
    public function keys(): array;

    public function get(string $key): mixed;

    public function getRawEntry(string $key): ?array;

    public function getExpired(string $key): mixed;

    public function set(string $key, mixed $value, ?int $ttl = null): void;

    public function delete(string $key): void;

    public function clear(): void;
}
