<?php

declare(strict_types=1);

namespace Forge\Core\Observability\Processor;

use Forge\Core\Observability\ObservabilityConfig;
use Forge\Core\Observability\Trace;

final class SamplingProcessor
{
    public static function shouldSample(Trace $trace): bool
    {
        if (ObservabilityConfig::strategy() === 'always') {
            return true;
        }

        if ($trace->getErrorCount() > 0) {
            return true;
        }

        if ($trace->getSlowQueryCount() > 0) {
            return true;
        }

        if ($trace->getDurationMs() >= ObservabilityConfig::slowThresholdMs()) {
            return true;
        }

        return (mt_rand() / mt_getrandmax()) < ObservabilityConfig::baseRate();
    }
}
