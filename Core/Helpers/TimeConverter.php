<?php

declare(strict_types=1);

namespace Forge\Core\Helpers;

final class TimeConverter
{
    /**
     * Convert minutes to milliseconds.
     *
     * @param int $minutes
     * @return int
     */
    public static function minutesToMilliseconds(int $minutes): int
    {
        return $minutes > 0 ? $minutes * 60 * 1000 : 0;
    }

    /**
     * Convert milliseconds to minutes.
     *
     * @param int $milliseconds
     * @return float
     */
    public static function millisecondsToMinutes(int $milliseconds): float
    {
        return $milliseconds > 0 ? $milliseconds / 1000 / 60 : 0.0;
    }
}
