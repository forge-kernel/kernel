<?php

declare(strict_types=1);

namespace Forge\Core\Observability;

final readonly class Span
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public float $startTime,
        public ?float $endTime = null,
        public array $metadata = [],
        public array $tags = [],
        public ?string $parentId = null,
        public string $status = 'ok',
        public ?string $error = null,
    ) {
    }

    public function duration(): float
    {
        return ($this->endTime ?? microtime(true)) - $this->startTime;
    }

    public function durationMs(): float
    {
        return $this->duration() * 1000;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parentId,
            'name' => $this->name,
            'type' => $this->type,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'duration_ms' => $this->endTime !== null ? round($this->durationMs(), 3) : null,
            'metadata' => $this->metadata,
            'tags' => $this->tags,
            'status' => $this->status,
            'error' => $this->error,
        ];
    }
}
