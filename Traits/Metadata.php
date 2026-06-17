<?php

declare(strict_types=1);

namespace Forge\Traits;

trait Metadata
{
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function setMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
        $this->save();
    }

    public function hasMetadata(string $key): bool
    {
        return isset($this->metadata[$key]);
    }
}
