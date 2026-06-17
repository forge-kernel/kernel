<?php

declare(strict_types=1);

namespace Forge\Traits;

use Forge\Core\Helpers\FileExistenceCache;

trait FileHelper
{
    protected function ensureDirectoryExists(string $path): void
    {
        $pathExist = FileExistenceCache::exists($path);
        if (!$pathExist) {
            mkdir($path, 0755, true);
        }
    }

    protected function recursiveGlob(string $pattern): array
    {
        if (!str_contains($pattern, '**')) {
            return glob($pattern, GLOB_BRACE) ?: [];
        }

        $files = [];
        $pattern = rtrim($pattern, '/');

        [$base, $suffix] = explode('**', $pattern, 2);
        $base = rtrim($base, '/');

        if (!is_dir($base)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = str_replace($base . '/', '', $file->getPathname());
            $matchPattern = str_replace('**', '*', ltrim($suffix, '/'));
            if ($matchPattern === '') {
                $files[] = $file->getPathname();
            } elseif (fnmatch($matchPattern, $relative)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
