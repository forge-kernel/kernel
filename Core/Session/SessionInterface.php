<?php

declare(strict_types=1);

namespace Forge\Core\Session;

interface SessionInterface
{
    public function start(): void;
    public function save(): void;
    public function getId(): string;
    public function has(string $key): bool;
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function remove(string $key): void;
    public function clear(): void;
    public function regenerate(bool $deleteOldSession = true): void;
    public function isStarted(): bool;
    public function all(): array;
}
