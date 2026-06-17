<?php

declare(strict_types=1);

namespace Forge\Core\Contracts\Database;

use PDO;
use PDOStatement;

interface DatabaseConnectionInterface
{
    public function getPdo(): PDO;

    public function exec(string $statement): int|false;

    public function prepare(string $statement): PDOStatement;

    public function query(string $statement): PDOStatement;

    public function beginTransaction(): bool;

    public function commit(): bool;

    public function rollBack(): bool;

    public function getDriver(): string;
}