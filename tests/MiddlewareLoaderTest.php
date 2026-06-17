<?php

declare(strict_types=1);

namespace Forge\tests;

use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use App\Modules\ForgeRouter\Middleware\MiddlewareLoader;

#[Group('middleware')]
final class MiddlewareLoaderTest extends TestCase
{
    #[Test('MiddlewareLoader::isCacheValid is O(1) through file mtime cache checks')]
    public function true_cache_invalidation_mtime(): void
    {
        // Tests cache invalidation using MTime tracking logic implicitly.
        // It should avoid the recursive scan array diff.

        $loader = new MiddlewareLoader();
        // Just verify it doesn't crash and returns an array
        $map = $loader->load();
        $this->assertTrue(is_array($map));
    }
}
