<?php

declare(strict_types=1);

namespace Forge\Core\Session\Drivers;

use Forge\Core\Config\Environment;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Session\SessionDriverInterface;
use PDO;
use PDOException;
use RuntimeException;
use SessionHandlerInterface;

final class SqliteSessionDriver implements SessionDriverInterface, SessionHandlerInterface
{
    private ?PDO $pdo = null;
    private bool $initialized = false;
    private string $dbPath;
    private int $lifetime;

    public function __construct()
    {
        $env = Environment::getInstance();
        $dbPath = $env->get('SESSION_DB_PATH', BASE_PATH . '/storage/database/security.sqlite');

        if (str_starts_with($dbPath, '/') && !str_starts_with($dbPath, BASE_PATH)) {
            $dbPath = BASE_PATH . $dbPath;
        } elseif (!str_starts_with($dbPath, '/')) {
            $dbPath = BASE_PATH . '/' . $dbPath;
        }

        $this->dbPath = $dbPath;
        $this->lifetime = (int) ($env->get('SESSION_LIFETIME', 1440) * 60);
        $this->initializeDatabase();
    }

    private function initializeDatabase(): void
    {
        $dir = dirname($this->dbPath);
        if (!FileExistenceCache::isDir($dir)) {
            if (!@mkdir($dir, 0755, true) && !FileExistenceCache::isDir($dir)) {
                throw new RuntimeException('Failed to create session database directory: ' . $dir);
            }
        }

        if (!is_writable($dir)) {
            throw new RuntimeException('Session database directory is not writable: ' . $dir);
        }

        try {
            $this->pdo = new PDO('sqlite:' . $this->dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $this->pdo->exec('PRAGMA foreign_keys = ON;');
            $this->pdo->exec('PRAGMA busy_timeout = 2000;');

            $this->createTableIfNotExists();
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to initialize SQLite session database: ' . $e->getMessage());
        }
    }

    private function createTableIfNotExists(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS sessions (
            id TEXT PRIMARY KEY,
            data TEXT NOT NULL,
            last_activity INTEGER NOT NULL
        )";
        $this->pdo->exec($sql);

        $sql = "CREATE INDEX IF NOT EXISTS idx_last_activity ON sessions(last_activity)";
        $this->pdo->exec($sql);
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

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
            $stmt = $this->pdo->prepare('SELECT data FROM sessions WHERE id = ?');
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
            $stmt = $this->pdo->prepare('REPLACE INTO sessions (id, data, last_activity) VALUES (?, ?, ?)');
            return $stmt->execute([$id, $data, time()]);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE id = ?');
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function gc(int $maxLifetime): int|false
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE last_activity < ?');
            $stmt->execute([time() - $this->lifetime]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            return false;
        }
    }
}
