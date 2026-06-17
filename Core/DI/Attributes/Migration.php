<?php

declare(strict_types=1);

namespace Forge\Core\DI\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Migration
{
    public function __construct(
        public ?string $group = null,
        public ?string $scope = null
    ) {
    }
}

