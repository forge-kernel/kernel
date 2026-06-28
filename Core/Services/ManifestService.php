<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\DI\Attributes\Injectable;

#[Injectable]
final class ManifestService
{
    public function readModulesManifest(string $manifestPath): ?array
    {
        if (!file_exists($manifestPath)) {
            return null;
        }

        $content = file_get_contents($manifestPath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    public function readFrameworkManifest(string $manifestPath): ?array
    {
        if (!file_exists($manifestPath)) {
            return null;
        }

        $content = file_get_contents($manifestPath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    public function writeModulesManifest(string $manifestPath, array $data): bool
    {
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($jsonContent === false) {
            return false;
        }

        $dir = dirname($manifestPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($manifestPath, $jsonContent) !== false;
    }

    public function writeFrameworkManifest(string $manifestPath, array $data): bool
    {
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($jsonContent === false) {
            return false;
        }

        $dir = dirname($manifestPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($manifestPath, $jsonContent) !== false;
    }

    public function moduleVersionExists(string $manifestPath, string $moduleName, string $version): bool
    {
        $manifest = $this->readModulesManifest($manifestPath);
        if (!$manifest) {
            return false;
        }

        return isset($manifest[$moduleName]['versions'][$version]);
    }

    public function frameworkVersionExists(string $manifestPath, string $version): bool
    {
        $manifest = $this->readFrameworkManifest($manifestPath);
        if (!$manifest) {
            return false;
        }

        return isset($manifest['versions'][$version]);
    }
}
