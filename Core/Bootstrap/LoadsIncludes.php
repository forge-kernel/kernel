<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\Structure\StructureResolver;

trait LoadsIncludes
{
    private static function loadIncludes(): void
    {
        $resolver = new StructureResolver();
        $includes = $resolver->getAppPaths('includes');

        foreach ($includes as $file) {
            $path = BASE_PATH . '/' . $file;
            if (is_file($path)) {
                require_once $path;
            }
        }
    }
}
