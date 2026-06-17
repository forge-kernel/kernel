<?php

declare(strict_types=1);

namespace Forge\Core\Cache\Drivers;

use Forge\Core\Cache\CacheDriverInterface;
use Forge\Core\Helpers\FileExistenceCache;

final class FileCacheDriver implements CacheDriverInterface
{
    private string $path;

    public function __construct(string $path = BASE_PATH . '/storage/framework/cache')
    {
        $this->path = rtrim($path, '/');
        if (!FileExistenceCache::isDir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    private function filePath(string $key): string
    {
        return $this->path . '/' . md5($key) . '.cache';
    }

    public function getRawEntry(string $key): ?array
    {
        $file = $this->filePath($key);
        if (!FileExistenceCache::isFile($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        return $data;
    }

    public function keys(): array
    {
        $files = glob(BASE_PATH . "/storage/framework/cache/*.json");
        return array_map(fn ($f) => basename($f, '.json'), $files);
    }

    /**
     * @throws \JsonException
     */
    public function get(string $key): mixed
    {
        $file = $this->filePath($key);
        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $data['value'];
    }

    /**
     * @throws \JsonException
     */
    public function getExpired(string $key): mixed
    {
        $file = $this->filePath($key);
        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['expires_at']) || $data['expires_at'] > time()) {
            return null;
        }

        return $data['value'];
    }

    /**
     * @throws \JsonException
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $file = $this->filePath($key);
        $expiresAt = $ttl ? time() + $ttl : null;

        $payload = json_encode([
            'value' => $value,
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR);

        file_put_contents($file, $payload, LOCK_EX);
    }

    public function delete(string $key): void
    {
        $file = $this->filePath($key);
        if (is_file($file)) {
            unlink($file);
        }
    }

    public function clear(): void
    {
        $files = glob($this->path . '/*.cache');
        if (!empty($files)) {
            FileExistenceCache::preload($files);
            foreach ($files as $f) {
                if (FileExistenceCache::exists($f)) {
                    unlink($f);
                }
            }
        }
    }
}
