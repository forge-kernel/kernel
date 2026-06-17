<?php
declare(strict_types=1);

namespace Forge\CLI\Traits;

trait CommandOptionTrait
{
    public function option(string $name, array $args): ?string
    {
        $prefix = '--' . $name;

        foreach ($args as $i => $arg) {
            if (str_starts_with($arg, $prefix . '=')) {
                return substr($arg, strlen($prefix) + 1);
            }

            if ($arg === $prefix && isset($args[$i + 1])) {
                return $args[$i + 1];
            }
            if ($arg === $prefix) {
                return '';
            }
        }

        return null;
    }

    public function flag(string $name, array $args): bool
    {
        return $this->option($name, $args) !== null;
    }
}