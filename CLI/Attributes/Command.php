<?php
declare(strict_types=1);

namespace Forge\CLI\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Command
{
    public function __construct(
        public string  $command,
        public string  $description,
        public ?string $usage = null,
        public array   $examples = [],
    ) {}
}
