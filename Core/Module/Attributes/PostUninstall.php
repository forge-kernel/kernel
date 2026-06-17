<?php

declare(strict_types=1);

namespace Forge\Core\Module\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class PostUninstall
{
    public function __construct(
        public string $command,
        public array  $args = [],
    )
    {
    }
}