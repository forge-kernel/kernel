<?php

declare(strict_types=1);

namespace Forge\Core\Module\Traits;

trait IncludesFiles
{
    abstract protected function includes(): array;

    public function registerIncludes(): void
    {
        foreach ($this->includes() as $file) {
            if (is_file($file)) {
                require_once $file;
            }
        }
    }
}
