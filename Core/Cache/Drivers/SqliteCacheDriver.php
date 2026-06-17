<?php

declare(strict_types=1);

namespace Forge\Core\Cache\Drivers;

use Forge\Core\Cache\CacheDriverInterface;
use Forge\Core\Helpers\FileExistenceCache;

final class SqliteCacheDriver implements CacheDriverInterface
{
    private \PDO $pdo;

    public function __construct(string $file = BASE_PATH . '/storage/database/cache.sqlite')
    {
        if (!FileExistenceCache::isDir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        $this->pdo = new \PDO('sqlite:' . $file);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY,
                value BLOB NOT NULL,
                expires_at INTEGER
            )"
        );
    }

    public function keys(): array
    {
        $stmt = $this->pdo->query("SELECT key FROM cache");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function getRawEntry(string $key): ?array
    {
        $stmt = $this->pdo->prepare("SELECT value, expires_at FROM cache WHERE key = :key");
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $row;
    }

    public function get(string $key): mixed
    {
        $stmt = $this->pdo->prepare("SELECT value, expires_at FROM cache WHERE key = :key");
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $row['value'];
    }

    public function getExpired(string $key): mixed
    {
        $stmt = $this->pdo->prepare("SELECT value, expires_at FROM cache WHERE key = :key");
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        if ($row['expires_at'] === null || $row['expires_at'] > time()) {
            return null;
        }

        return $row['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $expiresAt = $ttl ? time() + $ttl : null;
        $stmt = $this->pdo->prepare(
            "INSERT INTO cache (key, value, expires_at) VALUES (:key, :value, :expires_at)
             ON CONFLICT(key) DO UPDATE SET value = :value, expires_at = :expires_at"
        );
        $stmt->execute([
            'key' => $key,
            'value' => $value,
            'expires_at' => $expiresAt,
        ]);
    }

    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM cache WHERE key = :key");
        $stmt->execute(['key' => $key]);
    }

    public function clear(): void
    {
        $this->pdo->exec("DELETE FROM cache");
    }
}
