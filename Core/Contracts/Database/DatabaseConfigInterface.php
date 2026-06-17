<?php

declare(strict_types=1);

namespace Forge\Core\Contracts\Database;

interface DatabaseConfigInterface
{
    public function getDriver(): string;

    public function getDatabase(): string;

    public function getHost(): string;

    public function getUsername(): string;

    public function getPassword(): string;

    public function getPort(): int;

    public function getCharset(): string;

    public function getDsn(): string;

    public function getOptions(): array;
}