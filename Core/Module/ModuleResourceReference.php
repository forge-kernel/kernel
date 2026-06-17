<?php
declare(strict_types=1);

namespace Forge\Core\Module;

final class ModuleResourceReference
{
    public function __construct(
        public ?string $module,
        public string $name
    ) {
    }
}