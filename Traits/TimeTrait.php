<?php

declare(strict_types=1);

namespace Forge\Traits;

trait TimeTrait
{
    public function toMilliseconds(string $input): int
    {
        if (preg_match('/^(\d+)(ms|s|m|h)$/', $input, $matches)) {
            $value = (int)$matches[1];
            $unit  = $matches[2];

            return match ($unit) {
                'ms' => $value,
                    's'  => $value * 1000,
                    'm'  => $value * 60_000,
                    'h'  => $value * 3_600_000,
            };
        }

        throw new \InvalidArgumentException("Invalid time format: {$input}");
    }
}
