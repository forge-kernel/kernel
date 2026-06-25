<?php

declare(strict_types=1);

namespace Forge\Core\Observability;

final class Trace
{
    private string $id;
    private string $name;
    private float $startTime;
    private ?float $endTime = null;

    /** @var Span[] */
    private array $spans = [];

    private array $metadata = [];
    private array $tags = [];
    private int $spanCount = 0;
    private int $queryCount = 0;
    private int $errorCount = 0;
    private int $slowQueryCount = 0;
    private ?int $peakMemoryBytes = null;
    private bool $sampled = false;

    public function __construct(string $name, array $metadata = [])
    {
        $this->id = $this->generateId();
        $this->name = $name;
        $this->startTime = microtime(true);
        $this->metadata = $metadata;
    }

    public function addSpan(Span $span): Span
    {
        $this->spans[] = $span;
        $this->spanCount++;

        if ($span->type === 'db') {
            $this->queryCount++;
            $durationMs = $span->durationMs();
            if ($durationMs >= ObservabilityConfig::slowQueryMs()) {
                $this->slowQueryCount++;
            }
        }

        if ($span->status === 'error') {
            $this->errorCount++;
        }

        return $span;
    }

    public function startSpan(string $name, string $type = 'custom', array $metadata = [], ?string $parentId = null): Span
    {
        $span = new Span(
            id: $this->generateId(),
            name: $name,
            type: $type,
            startTime: microtime(true),
            metadata: $metadata,
            parentId: $parentId,
        );
        return $this->addSpan($span);
    }

    public function end(array $metadata = []): void
    {
        $this->endTime = microtime(true);
        if ($metadata !== []) {
            $this->metadata = array_merge($this->metadata, $metadata);
        }
        $this->peakMemoryBytes = memory_get_peak_usage(true);
    }

    public function setSampled(bool $sampled): void
    {
        $this->sampled = $sampled;
    }

    public function isSampled(): bool
    {
        return $this->sampled;
    }

    public function getDurationMs(): float
    {
        $end = $this->endTime ?? microtime(true);
        return ($end - $this->startTime) * 1000;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getEndTime(): ?float
    {
        return $this->endTime;
    }

    public function getSpans(): array
    {
        return $this->spans;
    }

    public function getSpanCount(): int
    {
        return $this->spanCount;
    }

    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function getSlowQueryCount(): int
    {
        return $this->slowQueryCount;
    }

    public function getPeakMemoryBytes(): ?int
    {
        return $this->peakMemoryBytes;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setTag(string $key, string $value): void
    {
        $this->tags[$key] = $value;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function serializeSpans(): string
    {
        if (!$this->sampled || $this->spans === []) {
            return '[]';
        }
        return json_encode(array_map(fn(Span $s) => $s->toArray(), $this->spans), JSON_THROW_ON_ERROR);
    }

    public function serializeTags(): string
    {
        return json_encode($this->tags, JSON_THROW_ON_ERROR);
    }

    public function toArray(): array
    {
        $requestMethod = $this->metadata['request_method'] ?? null;
        $requestPath = $this->metadata['request_path'] ?? null;
        $statusCode = $this->metadata['status_code'] ?? null;
        $status = $this->metadata['status'] ?? ($this->errorCount > 0 ? 'error' : 'ok');
        $userId = $this->metadata['user_id'] ?? null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'started_at' => $this->startTime,
            'ended_at' => $this->endTime,
            'duration_ms' => round($this->getDurationMs(), 3),
            'request_method' => $requestMethod,
            'request_path' => $requestPath,
            'status_code' => $statusCode,
            'status' => $status,
            'span_count' => $this->spanCount,
            'query_count' => $this->queryCount,
            'error_count' => $this->errorCount,
            'slow_query_count' => $this->slowQueryCount,
            'peak_memory_bytes' => $this->peakMemoryBytes,
            'sampled' => $this->sampled ? 1 : 0,
            'spans' => $this->sampled ? $this->serializeSpans() : null,
            'tags' => $this->serializeTags(),
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
