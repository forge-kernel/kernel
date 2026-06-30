<?php

declare(strict_types=1);

namespace Forge\Core\Observability;

use Forge\Core\Contracts\Database\DatabaseConnectionInterface;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\Logger;
use Forge\Core\Observability\Instrumentation\MetricsBridge;
use Forge\Core\Observability\Processor\SamplingProcessor;
use Forge\Core\Observability\Storage\DatabaseStorage;
use Forge\Core\Observability\Storage\StorageInterface;

final class ObservabilityManager
{
    private static ?self $instance = null;
    private ?Trace $currentTrace = null;
    private ?StorageInterface $storage = null;
    private bool $enabled;

    private function __construct()
    {
        $this->enabled = ObservabilityConfig::enabled();
    }

    public static function getInstance(): ?self
    {
        if (self::$instance === null) {
            $instance = new self();
            self::$instance = $instance->enabled ? $instance : null;
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function beginRequest(string $name, array $metadata = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->currentTrace = new Trace($name, $metadata);
    }

    public function endRequest(array $metadata = []): void
    {
        if (!$this->enabled || $this->currentTrace === null) {
            return;
        }

        $this->currentTrace->end($metadata);
        MetricsBridge::appendToTrace($this->currentTrace);

        $sampled = SamplingProcessor::shouldSample($this->currentTrace);
        $this->currentTrace->setSampled($sampled);

        try {
            $storage = $this->getStorage();
            if ($storage !== null) {
                $storage->saveTrace($this->currentTrace);
            }
        } catch (\Throwable $e) {
            Logger::log("ObservabilityManager: failed to save trace to storage", $e->getMessage());
        }

        $this->currentTrace = null;
    }

    public function recordQuery(string $sql, array $bindings, float $timeMs, string $origin = ''): void
    {
        if (!$this->enabled || $this->currentTrace === null) {
            return;
        }

        $isSlow = $timeMs >= ObservabilityConfig::slowQueryMs();
        $metadata = $isSlow ? [
            'sql' => $sql,
            'bindings' => $bindings,
        ] : [];

        $now = microtime(true);
        $span = new Span(
            id: $this->generateSpanId(),
            name: $origin !== '' ? $origin : 'db.query',
            type: 'db',
            startTime: $now - ($timeMs / 1000),
            endTime: $now,
            metadata: $metadata,
            tags: [
                'slow' => $isSlow ? 'true' : 'false',
                'origin' => $origin,
            ],
        );

        $this->currentTrace->addSpan($span);
    }

    public function currentTrace(): ?Trace
    {
        return $this->currentTrace;
    }

    private function getStorage(): ?StorageInterface
    {
        if ($this->storage !== null) {
            return $this->storage;
        }

        try {
            $container = Container::getInstance();
            if ($container->has(DatabaseConnectionInterface::class)) {
                $this->storage = new DatabaseStorage($container->get(DatabaseConnectionInterface::class));
                return $this->storage;
            }
        } catch (\Throwable $e) {
            Logger::log("ObservabilityManager: failed to resolve database storage", $e->getMessage());
        }

        return null;
    }

    private function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
