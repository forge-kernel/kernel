<?php

declare(strict_types=1);

namespace Forge\Traits;

trait StringHelper
{
    /**
     * Convert a string to camelCase.
     *
     * @param string $string
     * @return string
     */
    public static function toCamelCase(string $string): string
    {
        return lcfirst(self::toPascalCase($string));
    }

    /**
     * Convert a string to PascalCase.
     *
     * @param string $string
     * @return string
     */
    public static function toPascalCase(string $string): string
    {
        return str_replace(
            " ",
            "",
            ucwords(str_replace(["-", "_"], " ", $string)),
        );
    }

    /**
     * Convert a string to snake_case.
     *
     * @param string $string
     * @return string
     */
    public static function toSnakeCase(string $string): string
    {
        return strtolower(preg_replace("/(?<!^)[A-Z]/", '_$0', $string));
    }

    /**
     * Convert a string to kebab-case.
     *
     * @param string $string
     * @return string
     */
    public static function toKebabCase(string $string): string
    {
        return strtolower(preg_replace("/(?<!^)[A-Z]/", '-$0', $string));
    }

    /**
     * Convert a string to Title Case.
     *
     * @param string $string
     * @return string
     */
    public static function toTitleCase(string $string): string
    {
        return ucwords(strtolower($string));
    }

    /**
     * Check if a string is in CamelCase.
     *
     * @param string $string
     * @return bool
     */
    public static function isCamelCase(string $string): bool
    {
        return preg_match('/^[a-z]+([A-Z][a-z]*)*$/', $string) === 1;
    }

    /**
     * Check if a string is in PascalCase.
     *
     * @param string $string
     * @return bool
     */
    public static function isPascalCase(string $string): bool
    {
        return preg_match('/^[A-Z][a-z]*([A-Z][a-z]*)*$/', $string) === 1;
    }

    /**
     * Check if a string is in snake_case.
     *
     * @param string $string
     * @return bool
     */
    public static function isSnakeCase(string $string): bool
    {
        return preg_match('/^[a-z]+(_[a-z]+)*$/', $string) === 1;
    }

    /**
     * Check if a string is in kebab-case.
     *
     * @param string $string
     * @return bool
     */
    public static function isKebabCase(string $string): bool
    {
        return preg_match('/^[a-z]+(-[a-z]+)*$/', $string) === 1;
    }

    /**
     * Truncate a string to a specified length.
     *
     * @param string $string
     * @param int $length
     * @param string $suffix
     * @return string
     */
    public static function truncate(
        string $string,
        int $length,
        string $suffix = "...",
    ): string {
        if (strlen($string) <= $length) {
            return $string;
        }

        return substr($string, 0, $length) . $suffix;
    }

    /**
     * Slugify a string.
     *
     * @param string $string
     * @return string
     */
    public static function slugify(string $string): string
    {
        return strtolower(
            trim(preg_replace("/[^A-Za-z0-9-]+/", "-", $string), "-"),
        );
    }

    /**
     * Convert a string to its plural form.
     *
     * @param string $string
     * @return string
     */
    public static function toPlural(string $string): string
    {
        $lower = strtolower($string);

        $irregular = [
            "child" => "children",
            "man" => "men",
            "woman" => "women",
            "mouse" => "mice",
            "goose" => "geese",
            "tooth" => "teeth",
            "foot" => "feet",
            "person" => "people",
            "ox" => "oxen",
        ];

        if (isset($irregular[$lower])) {
            return self::matchCase($string, $irregular[$lower]);
        }

        if (preg_match('/[^aeiou]y$/i', $string)) {
            return substr($string, 0, -1) . "ies";
        }

        if (preg_match('/(s|x|z|ch|sh)$/i', $string)) {
            return $string . "es";
        }

        if (preg_match('/(f|fe)$/i', $string)) {
            return preg_replace('/(f|fe)$/i', "ves", $string);
        }

        return $string . "s";
    }

    /**
     * Match original string casing (basic).
     */
    private static function matchCase(string $original, string $new): string
    {
        if (ctype_upper($original)) {
            return strtoupper($new);
        }

        if (ctype_upper($original[0])) {
            return ucfirst($new);
        }

        return $new;
    }
}
