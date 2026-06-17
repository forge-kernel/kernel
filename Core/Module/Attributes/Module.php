<?php

declare(strict_types=1);

namespace Forge\Core\Module\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Module
{
  public function __construct(
    public ?string $name = null,
    public string $version = '0.1.0',
    public ?string $description = null,
    public int $order = PHP_INT_MAX,
    public bool $core = false,
    public ?bool $isCli = false,
    public ?string $type = '',
    public ?string $author = null,
    public ?string $license = null,
    public ?array $tags = null,
  ) {
  }
}
