<?php

declare(strict_types=1);

namespace Forge\Core\Config;

final class Environment
{
    public static ?self $instance = null;
    private array $config = [];

    private function __construct()
    {
        $this->config = array_merge([
            'APP_ENV' => 'development',
            'APP_DEBUG' => 'true',
        ], $_ENV);
    }

    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function hydrate(array $data): void
    {
        $this->config = array_merge($this->config, $data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function isDevelopment(): bool
    {
        return $this->get('APP_ENV') === 'development';
    }
    public function isDebugEnabled(): bool
    {
        return filter_var($this->get('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN);
    }
}
