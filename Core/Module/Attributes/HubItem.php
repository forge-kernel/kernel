<?php

declare(strict_types=1);

namespace Forge\Core\Module\Attributes;

use Attribute;
use Forge\Core\Module\ForgeIcon;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class HubItem
{
    public function __construct(
        public string $label,
        public string $route,
        public ?ForgeIcon $icon,
        public int $order = 99,
        public ?array $permissions = []
    ) {
    }
}
