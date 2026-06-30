<?php

declare(strict_types=1);

namespace Forge\Core\Helpers;

final class Version
{
    public static function version(): string
    {
        return KERNEL_VERSION;
    }

    public static function isVersionCompatible(string $currentVersion, string $requiredVersion): bool
    {
        $operator = '=';

        if (str_starts_with($requiredVersion, '>=')) {
            $operator = '>=';
            $requiredVersion = substr($requiredVersion, 2);
        } elseif (str_starts_with($requiredVersion, '<=')) {
            $operator = '<=';
            $requiredVersion = substr($requiredVersion, 2);
        } elseif (str_starts_with($requiredVersion, '^')) {
            $operator = '>=';
            $requiredVersion = substr($requiredVersion, 1);
            $upperBound = explode('.', $requiredVersion);
            $upperBound[1] = (int)$upperBound[1] + 1;
            $upperBound = implode('.', $upperBound);
            return version_compare($currentVersion, $requiredVersion, '>=') &&
                   version_compare($currentVersion, $upperBound, '<');
        } elseif (str_starts_with($requiredVersion, '~')) {
            $operator = '>=';
            $requiredVersion = substr($requiredVersion, 1);
            $upperBound = explode('.', $requiredVersion);
            $upperBound[1] = (int)$upperBound[1] + 1;
            $upperBound = implode('.', $upperBound);
            return version_compare($currentVersion, $requiredVersion, '>=') &&
                   version_compare($currentVersion, $upperBound, '<');
        } elseif (str_starts_with($requiredVersion, '=')) {
            $operator = '=';
            $requiredVersion = substr($requiredVersion, 1);
        }

        return version_compare($currentVersion, $requiredVersion, $operator);
    }

    public static function isPhpVersionCompatible(string $currentVersion, string $requiredVersion): bool
    {
        return version_compare($currentVersion, $requiredVersion, '>=');
    }
}
