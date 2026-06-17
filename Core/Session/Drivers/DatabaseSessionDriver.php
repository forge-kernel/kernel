<?php

declare(strict_types=1);

namespace Forge\Core\Session\Drivers;

use Forge\Core\Config\Environment;
use Forge\Core\Contracts\Database\DatabaseConnectionInterface;
use Forge\Core\DI\Container;
use Forge\Core\Session\SessionDriverInterface;
use PDO;
use PDOException;
use RuntimeException;
use SessionHandlerInterface;

final class DatabaseSessionDriver implements SessionDriverInterface, SessionHandlerInterface
{
    private ?PDO $pdo = null;
    private bool $initialized = false;
    private int $lifetime;
    private string $driver = '';

    public function __construct()
    {
        $env = Environment::getInstance();
        $this->lifetime = (int) ($env->get('SESSION_LIFETIME', 1440) * 60);
    }

    private function getPdo(): PDO
    {
        if ($this->pdo === null) {
            try {
                $container = Container::getInstance();
                if (!$container->has(DatabaseConnectionInterface::class)) {
                    throw new RuntimeException('Database connection not available');
                }
                $connection = $container->get(DatabaseConnectionInterface::class);
                $this->pdo = $connection->getPdo();
                $this->driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $this->createTableIfNotExists();
            } catch (\Throwable $e) {
                throw new RuntimeException('Failed to get database connection for sessions: ' . $e->getMessage());
            }
        }
        return $this->pdo;
    }

    private function createTableIfNotExists(): void
    {
        $driver = $this->driver;

        if ($driver === 'mysql') {
            $sql = "CREATE TABLE IF NOT EXISTS sessions (
                id VARCHAR(255) PRIMARY KEY,
                data TEXT NOT NULL,
                last_activity INT NOT NULL,
                INDEX idx_last_activity (last_activity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        } elseif ($driver === 'pgsql') {
            $sql = "CREATE TABLE IF NOT EXISTS sessions (
                id VARCHAR(255) PRIMARY KEY,
                data TEXT NOT NULL,
                last_activity INTEGER NOT NULL
            )";
            $this->pdo->exec($sql);
            $sql = "CREATE INDEX IF NOT EXISTS idx_last_activity ON sessions(last_activity)";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS sessions (
                id VARCHAR(255) PRIMARY KEY,
                data TEXT NOT NULL,
                last_activity INT NOT NULL
            )";
        }

        $this->pdo->exec($sql);

        if ($driver !== 'pgsql') {
            try {
                $indexSql = "CREATE INDEX IF NOT EXISTS idx_last_activity ON sessions(last_activity)";
                $this->pdo->exec($indexSql);
            } catch (PDOException $e) {
            }
        }
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $this->getPdo();

        if (!$this->initialized) {
            session_set_save_handler($this, true);
            $this->initialized = true;
        }

        session_start();

        if (PHP_SAPI !== 'cli' && !headers_sent() && empty($_COOKIE[session_name()])) {
            session_regenerate_id(true);
        }
    }

    public function save(): void
    {
        session_write_close();
    }

    public function open(string $savePath, string $sessionName): bool
    {
        return $this->pdo !== null;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        try {
            $stmt = $this->getPdo()->prepare('SELECT data FROM sessions WHERE id = ?');
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            return $result ? $result['data'] : '';
        } catch (PDOException $e) {
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $driver = $this->driver;
            $timestamp = time();

            if ($driver === 'mysql') {
                $stmt = $this->getPdo()->prepare('INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = ?, last_activity = ?');
                return $stmt->execute([$id, $data, $timestamp, $data, $timestamp]);
            } elseif ($driver === 'pgsql') {
                $stmt = $this->getPdo()->prepare('INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, ?) ON CONFLICT(id) DO UPDATE SET data = EXCLUDED.data, last_activity = EXCLUDED.last_activity');
                return $stmt->execute([$id, $data, $timestamp]);
            } else {
                $stmt = $this->getPdo()->prepare('REPLACE INTO sessions (id, data, last_activity) VALUES (?, ?, ?)');
                return $stmt->execute([$id, $data, $timestamp]);
            }
        } catch (PDOException $e) {
            try {
                $stmt = $this->getPdo()->prepare('DELETE FROM sessions WHERE id = ?');
                $stmt->execute([$id]);
                $stmt = $this->getPdo()->prepare('INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, ?)');
                return $stmt->execute([$id, $data, time()]);
            } catch (PDOException $e2) {
                return false;
            }
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->getPdo()->prepare('DELETE FROM sessions WHERE id = ?');
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function gc(int $maxLifetime): int|false
    {
        try {
            $stmt = $this->getPdo()->prepare('DELETE FROM sessions WHERE last_activity < ?');
            $stmt->execute([time() - $this->lifetime]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            return false;
        }
    }
}
