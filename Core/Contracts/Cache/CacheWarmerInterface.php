<?php

declare(strict_types=1);

namespace Forge\Core\Contracts\Cache;

/**
 * Contract for services that warm application caches during cache:warm.
 * Modules implement this interface to register their own cache warming logic
 * without coupling the kernel command to module-specific services.
 */
interface CacheWarmerInterface
{
    public function warmCache(): void;
}
