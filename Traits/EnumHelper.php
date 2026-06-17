<?php

declare(strict_types=1);

namespace Forge\Traits;

trait EnumHelper
{
    /**
     * Return an array of all enum values.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Create an enum instance from its name.
     *
     * @param string $name
     * @return static|null
     */
    public static function fromName(string $name): ?static
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }
        return null;
    }

    /**
     * Convert the enum to an associative array.
     *
     * @return array
     */
    public static function toArray(): array
    {
        $array = [];
        foreach (self::cases() as $case) {
            $array[$case->name] = $case->value;
        }
        return $array;
    }

    /**
     * Return an array of labels for each enum case.
     *
     * @return string[]
     */
    public static function labels(): array
    {
        return array_map(fn ($case) => $case->label(), self::cases());
    }

    /**
     * Return an array of descriptions for each enum case.
     *
     * @return string[]
     */
    public static function descriptions(): array
    {
        return array_map(fn ($case) => $case->description(), self::cases());
    }

    /**
     * Return an array of all values within an enum.
     *
     * @return array
     */
    public static function valueArray(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }

    /**
     * Return a comma-separated list of all values within an enum.
     *
     * @param string $separator
     * @return string
     */
    public static function valueList(string $separator = ', '): string
    {
        return implode($separator, self::valueArray());
    }
}
