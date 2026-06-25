<?php

declare(strict_types=1);

namespace Forge\Core\Observability;

final class ObservabilityConfig
{
    public static function enabled(): bool
    {
        return (bool) config('forge_observability.enabled', true);
    }

    public static function strategy(): string
    {
        return (string) config('forge_observability.sampling.strategy', 'adaptive');
    }

    public static function baseRate(): float
    {
        return (float) config('forge_observability.sampling.base_rate', 0.1);
    }

    public static function slowThresholdMs(): float
    {
        return (float) config('forge_observability.sampling.slow_threshold_ms', 200);
    }

    public static function slowQueryMs(): float
    {
        return (float) config('forge_observability.sampling.slow_query_ms', 100);
    }

    public static function retentionDays(): int
    {
        return (int) config('forge_observability.storage.retention_days', 7);
    }
}
