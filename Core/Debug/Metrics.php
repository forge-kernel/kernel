<?php

declare(strict_types=1);

namespace Forge\Core\Debug;

class Metrics
{
    private static array $timers = [];
    private static ?bool $enabled = null;

    public static function isEnabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        self::$enabled = (bool) ($_ENV['APP_METRICS_ENABLED'] ?? getenv('APP_METRICS_ENABLED') ?? false);
        return self::$enabled;
    }

    public static function start(string $key): void
    {
        if (!self::isEnabled()) {
            return;
        }


        self::$timers[$key] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage()
        ];
    }

    public static function stop(string $key): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (!isset(self::$timers[$key])) {
            return;
        }

        self::$timers[$key]['end'] = microtime(true);
        self::$timers[$key]['memory_end'] = memory_get_usage();
        self::$timers[$key]['duration'] = self::$timers[$key]['end'] - self::$timers[$key]['start'];
        self::$timers[$key]['memory_used'] = self::$timers[$key]['memory_end'] - self::$timers[$key]['memory_start'];
    }

    public static function addGlobalTimer(string $key): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (!defined('FORGE_START')) {
            define('FORGE_START', microtime(true));
        }

        $startTime = FORGE_START;
        $endTime = microtime(true);

        self::$timers[$key] = [
            'start' => $startTime,
            'end' => $endTime,
            'duration' => $endTime - $startTime,
            'memory_used' => memory_get_usage() - memory_get_usage(true)
        ];
    }

    public static function get(): array
    {
        if (!self::isEnabled()) {
            return [];
        }
        return self::$timers;
    }

    /**
     * Returns all timers, including in-progress ones with live duration/memory.
     * Useful when called from inside a view that is being rendered.
     */
    public static function getLive(): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        $now = microtime(true);
        $currentMem = memory_get_usage();
        $result = [];

        foreach (self::$timers as $key => $data) {
            if (isset($data['duration'])) {
                $result[$key] = $data;
            } else {
                $result[$key] = [
                    'start' => $data['start'],
                    'duration' => $now - $data['start'],
                    'memory_used' => $currentMem - ($data['memory_start'] ?? $currentMem),
                ];
            }
        }

        return $result;
    }

    public static function reset(): void
    {
        self::$timers = [];
        self::$enabled = null;
    }

    public static function print(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        foreach (self::$timers as $key => $data) {
            echo sprintf(
                "%s: %.5f sec, %d KB\n",
                $key,
                $data['duration'] ?? 0,
                ($data['memory_used'] ?? 0) / 1024
            );
        }
    }
}
