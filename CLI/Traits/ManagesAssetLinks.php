<?php

declare(strict_types=1);

namespace Forge\CLI\Traits;

use Forge\Core\Structure\StructureResolver;
use Forge\Traits\StringHelper;
use InvalidArgumentException;

trait ManagesAssetLinks
{
    use StringHelper;

    protected function buildPaths(string $type, ?string $module = null): array
    {
        return match ($type) {
            'app' => [
                'target' => BASE_PATH . '/app/UI/assets',
                'link' => BASE_PATH . '/public/assets/app',
            ],
            'module' => [
                'target' => BASE_PATH . '/' . StructureResolver::resolveModulesRoot() . "/{$this->toPascalCase($module)}/src/UI/assets",
                'link' => BASE_PATH . "/public/assets/modules/" . $this->toKebabCase($module),
            ],
            default => throw new InvalidArgumentException("Invalid asset type: {$type}")
        };
    }

    protected function createLink(string $target, string $link): int
    {
        if (!is_dir($target)) {
            $this->error("Target directory [$target] does not exist.");
            return 1;
        }

        $dir = dirname($link);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            $this->success("Created directory: $dir");
        }

        if (file_exists($link) || is_link($link)) {
            $this->info("Link [$link] already exists.");
            return 0;
        }

        return symlink($target, $link)
            ? ($this->success("Created symlink: $link → $target") ?? 0)
            : ($this->error("Failed to create symlink: $link → $target") ?? 1);
    }

    protected function unlinkDirectory(string $path): int
    {
        if (!file_exists($path) && !is_link($path)) {
            $this->info("The [$path] link does not exist.");
            return 0;
        }

        if (!is_link($path)) {
            $this->error("The [$path] path is not a symbolic link.");
            return 1;
        }

        return unlink($path)
            ? ($this->success("The [$path] link has been removed.") ?? 0)
            : ($this->error("Failed to remove the [$path] link.") ?? 1);
    }
}