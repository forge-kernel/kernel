<?php

declare(strict_types=1);

namespace Forge\Core\Helpers;

final class FileExistenceCache
{
    private static array $cache = [];
    private static array $statCache = [];

    public static function exists(string $path): bool
    {
        return self::$cache[$path] ??= file_exists($path);
    }

    public static function isFile(string $path): bool
    {
        $key = 'f:' . $path;
        return self::$cache[$key] ??= is_file($path);
    }

    public static function isDir(string $path): bool
    {
        $key = 'd:' . $path;
        return self::$cache[$key] ??= is_dir($path);
    }

    public static function isReadable(string $path): bool
    {
        $key = 'r:' . $path;
        return self::$cache[$key] ??= is_readable($path);
    }

    public static function isWritable(string $path): bool
    {
        $key = 'w:' . $path;
        return self::$cache[$key] ??= is_writable($path);
    }

    public static function getMtime(string $path): ?int
    {
        $key = 'm:' . $path;
        if (array_key_exists($key, self::$statCache)) {
            return self::$statCache[$key];
        }
        if (!file_exists($path)) {
            return self::$statCache[$key] = null;
        }
        $stat = stat($path);
        return self::$statCache[$key] = ($stat ? $stat['mtime'] : null);
    }

    public static function getSize(string $path): ?int
    {
        $key = 's:' . $path;
        if (array_key_exists($key, self::$statCache)) {
            return self::$statCache[$key];
        }
        if (!is_file($path)) {
            return self::$statCache[$key] = null;
        }
        $stat = stat($path);
        return self::$statCache[$key] = ($stat ? $stat['size'] : null);
    }

    public static function clear(): void
    {
        self::$cache = [];
        self::$statCache = [];
    }

    public static function clearPath(string $path): void
    {
        unset(
            self::$cache[$path],
            self::$cache['f:' . $path],
            self::$cache['d:' . $path],
            self::$cache['r:' . $path],
            self::$cache['w:' . $path],
            self::$statCache['m:' . $path],
            self::$statCache['s:' . $path],
        );
    }

    public static function hasFilesChanged(array $filesWithMtime): bool
    {
        if (empty($filesWithMtime)) {
            return false;
        }

        self::preload(array_keys($filesWithMtime));

        foreach ($filesWithMtime as $file => $expectedMtime) {
            if (!self::exists($file)) {
                return true;
            }

            $currentMtime = self::getMtime($file);
            if ($currentMtime !== $expectedMtime) {
                return true;
            }
        }

        return false;
    }

    public static function preload(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            if (isset(self::$cache[$path])) {
                continue;
            }

            $exists = file_exists($path);
            self::$cache[$path] = $exists;

            if ($exists) {
                $isFile = is_file($path);
                self::$cache['f:' . $path] = $isFile;
                self::$cache['d:' . $path] = !$isFile && is_dir($path);
                self::$cache['r:' . $path] = is_readable($path);
                self::$cache['w:' . $path] = is_writable($path);
                $stat = stat($path);
                if ($stat) {
                    self::$statCache['m:' . $path] = $stat['mtime'];
                    self::$statCache['s:' . $path] = $stat['size'];
                }
            } else {
                self::$cache['f:' . $path] = false;
                self::$cache['d:' . $path] = false;
                self::$cache['r:' . $path] = false;
                self::$cache['w:' . $path] = false;
            }
        }
    }

    public static function getCacheStats(): array
    {
        return [
            'file_checks' => count(self::$cache),
            'stat_checks' => count(self::$statCache),
            'total_memory_estimate' => (count(self::$cache) + count(self::$statCache)) * 100,
        ];
    }
}
