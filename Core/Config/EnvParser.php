<?php

declare(strict_types=1);

namespace Forge\Core\Config;

use Forge\Core\DI\Attributes\Service;
use Forge\Core\Helpers\FileExistenceCache;

#[Service]
final class EnvParser
{
    public static function load(string $path): void
    {
        $pathExists = FileExistenceCache::exists($path);
        if (!$pathExists) {
            throw new \RuntimeException('.env file not found');
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            $value = self::stripInlineComment($value);

            if (getenv($name) !== false) {
                continue;
            }
            $_ENV[$name] = self::parseValue($value);
        }
    }

    private static function stripInlineComment(string $value): string
    {
        $value = trim($value);

        if (strpos($value, '#') === false) {
            return $value;
        }

        $inQuotes = false;
        $quoteChar = null;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if (($char === '"' || $char === "'") && ($i === 0 || $value[$i - 1] !== '\\')) {
                if (!$inQuotes) {
                    $inQuotes = true;
                    $quoteChar = $char;
                } elseif ($char === $quoteChar) {
                    $inQuotes = false;
                    $quoteChar = null;
                }
            } elseif ($char === '#' && !$inQuotes) {
                return trim(substr($value, 0, $i));
            }
        }

        return $value;
    }

    private static function parseValue(string $value): mixed
    {
        $value = trim($value, "'\" \t\n\r\0\x0B");

        if (strtolower($value) === 'true') {
            return true;
        }
        if (strtolower($value) === 'false') {
            return false;
        }
        if (is_numeric($value)) {
            return (strpos($value, '.') !== false) ? (float)$value : (int)$value;
        }

        if (strpos($value, '[') === 0 && strrpos($value, ']') === strlen($value) - 1) {
            $arrayString = substr($value, 1, -1);
            $items = array_map('trim', explode(',', $arrayString));
            return array_map([self::class, 'parseValue'], $items);
        }

        return $value;
    }
}
