<?php

declare(strict_types=1);

namespace Forge\Core\Cache\Attributes;

use Attribute;

/**
 * Mark a service as non-cacheable to prevent cache wrapping.
 * Use this for services that contain unserializable dependencies
 * like database connections, file handles, or other resources.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class NoCache
{
    public function __construct(
        public ?string $reason = null
    ) {}
}