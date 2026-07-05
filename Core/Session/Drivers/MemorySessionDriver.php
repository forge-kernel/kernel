<?php

declare(strict_types=1);

namespace Forge\Core\Session\Drivers;

use Forge\Core\Session\SessionDriverInterface;
use SessionHandlerInterface;

final class MemorySessionDriver implements SessionDriverInterface, SessionHandlerInterface
{
    private static array $storage = [];
    private static array $timestamps = [];
    private bool $initialized = false;

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
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        if (isset(self::$storage[$id])) {
            self::$timestamps[$id] = time();
        }
        return self::$storage[$id] ?? '';
    }

    public function write(string $id, string $data): bool
    {
        self::$storage[$id] = $data;
        self::$timestamps[$id] = time();
        return true;
    }

    public function destroy(string $id): bool
    {
        unset(self::$storage[$id], self::$timestamps[$id]);
        return true;
    }

    public function gc(int $maxLifetime): int|false
    {
        $expired = 0;
        $cutoff = time() - $maxLifetime;

        foreach (self::$timestamps as $id => $timestamp) {
            if ($timestamp < $cutoff) {
                unset(self::$storage[$id], self::$timestamps[$id]);
                $expired++;
            }
        }

        return $expired;
    }
}
