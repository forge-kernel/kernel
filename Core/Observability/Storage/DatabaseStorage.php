<?php

declare(strict_types=1);

namespace Forge\Core\Observability\Storage;

use Forge\Core\Contracts\Database\DatabaseConnectionInterface;
use Forge\Core\Observability\Trace;

final class DatabaseStorage implements StorageInterface
{
    private const TABLE_NAME = 'observability_traces';
    private bool $tableEnsured = false;

    public function __construct(
        private readonly DatabaseConnectionInterface $connection
    ) {
    }

    public function saveTrace(Trace $trace): void
    {
        if (!$this->tableEnsured) {
            $this->ensureTable();
        }

        $data = $trace->toArray();

        $sql = "INSERT INTO " . self::TABLE_NAME . " (
            id, name, started_at, ended_at, duration_ms,
            request_method, request_path, status_code, status,
            span_count, query_count, error_count, slow_query_count,
            peak_memory_bytes, sampled, spans, tags, user_id, created_at
        ) VALUES (
            :id, :name, :started_at, :ended_at, :duration_ms,
            :request_method, :request_path, :status_code, :status,
            :span_count, :query_count, :error_count, :slow_query_count,
            :peak_memory_bytes, :sampled, :spans, :tags, :user_id, :created_at
        )";

        $this->connection->prepare($sql)->execute($data);
    }

    private function ensureTable(): void
    {
        $driver = $this->connection->getDriver();

        $ddl = match ($driver) {
            'sqlite' => $this->sqliteDdl(),
            'pgsql' => $this->pgsqlDdl(),
            default => $this->mysqlDdl(),
        };

        $this->connection->exec($ddl);
        $this->tableEnsured = true;
    }

    private function mysqlDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (
            id CHAR(32) NOT NULL,
            name VARCHAR(255) NOT NULL,
            started_at DECIMAL(16,6) NOT NULL,
            ended_at DECIMAL(16,6) NULL,
            duration_ms DECIMAL(10,3) NULL,
            request_method VARCHAR(10) NULL,
            request_path VARCHAR(512) NULL,
            status_code SMALLINT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'ok',
            span_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            query_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            error_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            slow_query_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            peak_memory_bytes INT UNSIGNED NULL,
            sampled TINYINT(1) NOT NULL DEFAULT 0,
            spans JSON NULL,
            tags JSON NULL,
            user_id VARCHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_obs_created_at (created_at),
            INDEX idx_obs_duration (duration_ms),
            INDEX idx_obs_status (status),
            INDEX idx_obs_status_code (status_code),
            INDEX idx_obs_sampled (sampled),
            INDEX idx_obs_request_path (request_path)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function sqliteDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (
            id CHAR(32) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            started_at DECIMAL(16,6) NOT NULL,
            ended_at DECIMAL(16,6) NULL,
            duration_ms DECIMAL(10,3) NULL,
            request_method VARCHAR(10) NULL,
            request_path VARCHAR(512) NULL,
            status_code INTEGER NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'ok',
            span_count INTEGER NOT NULL DEFAULT 0,
            query_count INTEGER NOT NULL DEFAULT 0,
            error_count INTEGER NOT NULL DEFAULT 0,
            slow_query_count INTEGER NOT NULL DEFAULT 0,
            peak_memory_bytes INTEGER NULL,
            sampled INTEGER NOT NULL DEFAULT 0,
            spans TEXT NULL,
            tags TEXT NULL,
            user_id VARCHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )";
    }

    private function pgsqlDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (
            id CHAR(32) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            started_at NUMERIC(16,6) NOT NULL,
            ended_at NUMERIC(16,6) NULL,
            duration_ms NUMERIC(10,3) NULL,
            request_method VARCHAR(10) NULL,
            request_path VARCHAR(512) NULL,
            status_code SMALLINT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'ok',
            span_count SMALLINT NOT NULL DEFAULT 0,
            query_count SMALLINT NOT NULL DEFAULT 0,
            error_count SMALLINT NOT NULL DEFAULT 0,
            slow_query_count SMALLINT NOT NULL DEFAULT 0,
            peak_memory_bytes INTEGER NULL,
            sampled SMALLINT NOT NULL DEFAULT 0,
            spans JSONB NULL,
            tags JSONB NULL,
            user_id VARCHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_obs_created_at ON " . self::TABLE_NAME . " (created_at);
        CREATE INDEX IF NOT EXISTS idx_obs_duration ON " . self::TABLE_NAME . " (duration_ms);
        CREATE INDEX IF NOT EXISTS idx_obs_status ON " . self::TABLE_NAME . " (status);
        CREATE INDEX IF NOT EXISTS idx_obs_status_code ON " . self::TABLE_NAME . " (status_code);
        CREATE INDEX IF NOT EXISTS idx_obs_sampled ON " . self::TABLE_NAME . " (sampled);
        CREATE INDEX IF NOT EXISTS idx_obs_request_path ON " . self::TABLE_NAME . " (request_path)";
    }
}
