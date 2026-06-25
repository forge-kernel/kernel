<?php

declare(strict_types=1);

namespace Forge\Core\Observability\Instrumentation;

use Forge\Core\Debug\Metrics;
use Forge\Core\Observability\Span;
use Forge\Core\Observability\Trace;

final class MetricsBridge
{
    public static function appendToTrace(Trace $trace): void
    {
        if (!Metrics::isEnabled()) {
            return;
        }

        $metrics = Metrics::getLive();
        if ($metrics === []) {
            return;
        }

        foreach ($metrics as $key => $data) {
            $start = $data['start'] ?? microtime(true);
            $duration = $data['duration'] ?? 0;
            $trace->addSpan(new Span(
                id: self::generateId(),
                name: 'metric.' . $key,
                type: 'metric',
                startTime: (float) $start,
                endTime: (float) ($start + $duration),
                metadata: [
                    'memory_used' => $data['memory_used'] ?? 0,
                ],
            ));
        }
    }

    private static function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
