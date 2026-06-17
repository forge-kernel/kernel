<?php

declare(strict_types=1);

namespace Forge\Traits;

trait PathHelper
{
    private function stripBasePath(string $path): string
    {
        $basePath = realpath(BASE_PATH) . '/';
        $path = realpath($path);
        if ($path !== false && strpos($path, $basePath) === 0) {
            return substr($path, strlen($basePath));
        }
        return $path;
    }
}
