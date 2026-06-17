<?php

declare(strict_types=1);

namespace Forge\Traits;

trait SecurityHelper
{
    /**
     * Sanitizes input data by escaping HTML entities for security purposes.
     *
     * This method recursively processes an array of data, ensuring that all string values
     * are properly escaped using htmlspecialchars with ENT_QUOTES and UTF-8 encoding.
     * Nested arrays are processed recursively to maintain structure.
     *
     * @param array $data The input array to be sanitized
     * @return array<string, string> An array with all string values escaped and nested arrays recursively sanitized
     */
    private static function sanitize(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitize($value);
            } else {
                $sanitized[$key] = htmlspecialchars(
                    $value,
                    ENT_QUOTES,
                    "UTF-8",
                );
            }
        }
        return $sanitized;
    }
}
