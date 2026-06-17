<?php

declare(strict_types=1);

namespace Forge\Traits;

use Forge\Exceptions\InvalidBooleanException;
use Forge\Exceptions\MissingEnvVariableException;

trait HasEnvironmentVariables
{
    /**
     * Get an environment variable as a string.
     */
    protected function getEnvVar(string $key, ?string $default = null, bool $strict = false): ?string
    {
        if (isset($_ENV[$key])) {
            return trim($_ENV[$key]);
        }

        if ($strict) {
            throw new MissingEnvVariableException($key);
        }

        return $default;
    }
    /**
    * Get an environment variable as an integer.
    */
    protected function getIntEnv(string $key, ?int $default = null, bool $strict = false): ?int
    {
        $value = $this->getEnvVar($key, null, $strict);
        return ($value !== null) ? (int)$value : $default;
    }

    /**
     * Get an environment variable as a boolean.
     * Accepts "true", "false", "1", "0" (case-insensitive).
     */
    protected function getBoolEnv(string $key, ?bool $default = null, bool $strict = false): ?bool
    {
        $value = $this->getEnvVar($key, null, $strict);
        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new InvalidBooleanException($key, $value),
        };
    }

    /**
     * Get an environment variable as a float.
     */
    protected function getFloatEnv(string $key, ?float $default = null, bool $strict = false): ?float
    {
        $value = $this->getEnvVar($key, null, $strict);
        return ($value !== null) ? (float) $value : $default;
    }

    /**
     * Get an environment variable as an array (comma-separated values).
     */
    protected function getArrayEnv(string $key, ?array $default = null, bool $strict = false): ?array
    {
        $value = $this->getEnvVar($key, null, $strict);
        return ($value !== null) ? array_map('trim', explode(',', $value)) : $default;
    }
}
