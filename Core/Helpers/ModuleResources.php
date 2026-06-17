<?php

declare(strict_types=1);

namespace Forge\Core\Helpers;

use Forge\Core\Services\ModuleAssetManager;

final class ModuleResources
{
    /**
     * Get an asset from a given module and asset type
     */
    public static function pathTo(string $module, string $resource = 'css'): string
    {
        return "/assets/modules/$module/$resource";
    }

    /**
     * Autoload css styles from given module public resource
     */
    public static function loadStyles(string $module, array $options = ['media' => 'screen']): string
    {
        ModuleAssetManager::initialize();
        $styles = [];

        foreach (ModuleAssetManager::getStyles($module) as $css) {
            $version = $options['version'] ?? $css['mtime'];
            $realPath = BASE_PATH . '/public' . $css['path'];
            $contents = file_get_contents($realPath);
            $integrity = base64_encode(hash('sha256', $contents, true));

            $attrs = [
                'href' => $css['path'] . "?v={$version}",
                'rel' => 'stylesheet',
                'integrity' => "sha256-{$integrity}",
                'crossorigin' => 'anonymous',
            ] + $options;

            $styles[] = '<link ' . implode(' ', array_map(
                fn ($k, $v) => "{$k}=\"{$v}\"",
                array_keys($attrs),
                $attrs
            )) . '>';
        }

        return implode("\n", $styles);
    }

    /**
         * Autoload js script from a given module public resource
         */
    public static function loadScripts(string $module, array $options = ['async' => true]): string
    {
        ModuleAssetManager::initialize();
        $scripts = [];

        foreach (ModuleAssetManager::getScripts($module) as $js) {
            $version = $options['version'] ?? $js['mtime'];
            $attrs = [
                'src' => $js['path'] . "?v={$version}",
                'type' => 'module'
            ] + $options;

            if (!isset($attrs['async'])) {
                $attrs['defer'] = true;
            }

            $scripts[] = '<script ' . implode(' ', array_map(
                fn ($k, $v) => is_bool($v) ? $k : "{$k}=\"{$v}\"",
                array_keys($attrs),
                $attrs
            )) . '></script>';
        }

        return implode("\n", $scripts);
    }
}
