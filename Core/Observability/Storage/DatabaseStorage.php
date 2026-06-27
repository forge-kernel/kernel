<?php

declare(strict_types=1);

namespace Forge\Core\Observability\Storage;

use Forge\Core\Contracts\Database\DatabaseConnectionInterface;
use Forge\Core\Observability\Trace;

final class DatabaseStorage implements StorageInterface
{
    private const TABLE_NAME = 'observability_traces';

    public function __construct(
        private readonly DatabaseConnectionInterface $connection
    ) {
    }

    public function saveTrace(Trace $trace): void
    {
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
}
