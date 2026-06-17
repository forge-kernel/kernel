<?php

declare(strict_types=1);

namespace Forge\Core\Session\Drivers;

use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Session\SessionDriverInterface;

final class FileSessionDriver implements SessionDriverInterface
{
    private bool $initialized = false;

    public function __construct(
        private string $savePath = BASE_PATH . '/storage/sessions'
    ) {
        if (!FileExistenceCache::isDir($this->savePath)) {
            mkdir($this->savePath, 0755, true);
        }
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!$this->initialized) {
            ini_set('session.save_handler', 'files');
            ini_set('session.save_path', $this->savePath);
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
}
