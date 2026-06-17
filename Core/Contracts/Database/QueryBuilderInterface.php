<?php

declare(strict_types=1);

namespace Forge\Core\Contracts\Database;

interface QueryBuilderInterface
{
    public function lockForUpdate(): self;

    public function getConnection(): DatabaseConnectionInterface;

    public function setTable(string $table): self;

    public function select(string ...$columns): self;

    public function whereRaw(string $sql, array $params = []): self;

    public function selectRaw(string $expression, array $params = []): self;

    public function whereNull(string $column): self;

    public function whereNotNull(string $column): self;

    public function orderBy(string $column, string $direction = "ASC"): self;

    public function limit(int $count): self;

    public function offset(int $count): self;

    public function createTableFromAttributes(string $table, array $columns, array $indexes = []): string;

    public function get(): array;

    public function execute(string $sql): void;

    public function getRaw(): array;

    public function insert(array $data): int;

    public function update(array $data): int;

    public function delete(): int;

    public function find(int $id): ?array;

    public function where(string $column, string $operator, mixed $value): self;

    public function whereIn(string $column, array $values): self;

    public function whereNotIn(string $column, array $values): self;

    public function first(): ?array;

    public function leftJoin(
        string $table,
        string $first,
        string $operator,
        string $second
    ): self;

    public function join(
        string $table,
        string $first,
        string $operator,
        string $second,
        string $type = "INNER"
    ): self;

    public function rightJoin(
        string $table,
        string $first,
        string $operator,
        string $second
    ): self;

    public function groupBy(string ...$columns): self;

    public function having(string $column, string $operator, mixed $value): self;

    public function exists(): bool;

    public function reset(): self;

    public function transaction(callable $callback): mixed;

    public function beginTransaction(): self;

    public function inTransaction(): bool;

    public function commit(): self;

    public function rollback(): self;

    public function count(string $column = "*"): int;

    public function sum(string $column): float;

    public function avg(string $column): float;

    public function min(string $column): float;

    public function max(string $column): float;

    public function table(?string $name): string|self;

    public function createTable(
        string $tableName,
        array $columns,
        bool $ifNotExists = false
    ): string;

    public function createIndex(
        string $indexName,
        array $columns,
        bool $unique = false
    ): string;

    public function dropTable(string $tableName): string;

    public function getSql(): string;
}
