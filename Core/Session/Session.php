<?php

declare(strict_types=1);

namespace Forge\Core\Session;

use Forge\Core\DI\Attributes\Service;
use Forge\Traits\HasEnvironmentVariables;

#[Service]
final class Session implements SessionInterface
{
    use HasEnvironmentVariables;

    private array $config;
    private bool $started = false;

    public function __construct(private SessionDriverInterface $driver)
    {
        $this->config = [
            "name" => $this->getEnvVar("SESSION_NAME", "FORGE_SESSID"),
            "lifetime" => (int) ($_ENV["SESSION_LIFETIME"] ?? 1440),
            "path" => $this->getEnvVar("SESSION_PATH", "/"),
            "domain" => $this->getEnvVar("SESSION_DOMAIN", ""),
            "secure" => (bool) ($_ENV["SESSION_SECURE"] ?? false),
            "httponly" => (bool) ($_ENV["SESSION_HTTPONLY"] ?? true),
            "samesite" => $this->getEnvVar("SESSION_SAMESITE", "Lax"),
        ];
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if (PHP_SAPI === "cli") {
            $this->started = true;
            return;
        }

        session_set_cookie_params([
            "lifetime" => $this->config["lifetime"],
            "path" => $this->config["path"],
            "domain" => $this->config["domain"],
            "secure" => $this->config["secure"],
            "httponly" => $this->config["httponly"],
            "samesite" => $this->config["samesite"],
        ]);

        session_name($this->config["name"]);
        $this->driver->start();
        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function save(): void
    {
        if (!$this->started || $_SESSION === null) {
            return;
        }
        $this->driver->save();
    }

    public function getId(): string
    {
        return session_id();
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        setcookie(session_name(), "", 100);
        session_unset();
        session_destroy();
        $_SESSION = [];
        $this->started = false;

        $_SESSION = null;
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        if (PHP_SAPI === "cli") {
            return;
        }
        session_regenerate_id($deleteOldSession);
    }

    public function all(): array
    {
        return $_SESSION ?? [];
    }
}
