<?php
declare(strict_types=1);

namespace Forge\CLI\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Arg
{
    public function __construct(
        public string               $name,
        public string               $description,
        public string|int|bool|null $default = null,
        public bool                 $required = true,
        public ?string              $validate = null,
        public ?string              $ask = null,
    )
    {
    }
}