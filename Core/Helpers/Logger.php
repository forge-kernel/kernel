<?php

declare(strict_types=1);

namespace Forge\Core\Helpers;

use Stringable;

final class Logger
{
    private const string LOG_FILE = BASE_PATH . '/storage/logs/kernel.log';
    private const int MAX_LOG_LENGTH = 10000;

    private static ?string $logPath = null;
    private static ?bool $isDev = null;

    public static function log(string|Stringable $message, ?string $context = null): void
    {
        $message = (string) $message;
        $truncated = mb_strlen($message) > self::MAX_LOG_LENGTH
            ? mb_substr($message, 0, self::MAX_LOG_LENGTH) . '...'
            : $message;

        $line = sprintf(
            '[%s] %s%s',
            date('Y-m-d H:i:s'),
            $truncated,
            $context ? ' | ' . $context : ''
        );

        $file = self::resolveLogPath();
        if ($file !== null) {
            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }

        if (self::isDev()) {
            fwrite(STDERR, $line . PHP_EOL);
        }
    }

    public static function setLogPath(string $path): void
    {
        self::$logPath = $path;
    }

    public static function setDevMode(bool $dev): void
    {
        self::$isDev = $dev;
    }

    private static function resolveLogPath(): ?string
    {
        if (self::$logPath !== null) {
            return self::$logPath;
        }

        if (!defined('BASE_PATH')) {
            return null;
        }

        $path = BASE_PATH . self::LOG_FILE;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return self::$logPath = $path;
    }

    private static function isDev(): bool
    {
        if (self::$isDev !== null) {
            return self::$isDev;
        }

        return self::$isDev = ($_ENV['APP_ENV'] ?? 'production') === 'development';
    }
}
