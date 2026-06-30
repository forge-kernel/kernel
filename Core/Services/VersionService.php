<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\DI\Attributes\Injectable;
use InvalidArgumentException;

#[Injectable]
final class VersionService
{
    public function detectModuleVersion(string $moduleName): string
    {
        $entryFilePath = $this->findModuleEntryFile($moduleName);
        if (!$entryFilePath) {
            return '0.1.0';
        }

        return $this->extractVersionFromModuleAttribute($entryFilePath);
    }

    private function findModuleEntryFile(string $moduleName): ?string
    {
        $modulesDir = BASE_PATH . '/' . \Forge\Core\Structure\StructureResolver::resolveModulesRoot();
        $possiblePaths = [
            "$modulesDir/$moduleName/src/{$moduleName}Module.php",
            "$modulesDir/$moduleName/src/{$moduleName}.php",
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        $dir = "$modulesDir/$moduleName/src";
        if (is_dir($dir)) {
            $files = glob("$dir/*Module.php");
            if (!empty($files)) {
                return $files[0];
            }
        }

        return null;
    }

    private function extractVersionFromModuleAttribute(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return '0.1.0';
        }

        $pattern = '/#\[\s*Module\s*\(.*?version\s*:\s*["\']([^"\']+)["\']/s';
        if (preg_match($pattern, $content, $matches)) {
            return $matches[1];
        }

        return '0.1.0';
    }

    public function detectFrameworkVersion(): string
    {
        $versionFile = BASE_PATH . "/kernel/Core/Bootstrap/Version.php";
        if (!file_exists($versionFile)) {
            return '0.1.0';
        }

        $content = file_get_contents($versionFile);
        if (preg_match('/define\s*\(\s*["\']KERNEL_VERSION["\']\s*,\s*["\']([^"\']+)["\']/', $content, $matches)) {
            return $matches[1];
        }

        return '0.1.0';
    }

    public function suggestNextVersion(string $currentVersion, string $type): string
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $currentVersion, $matches)) {
            throw new InvalidArgumentException("Invalid version format: {$currentVersion}");
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        $patch = (int) $matches[3];

        return match ($type) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            'patch' => $major . '.' . $minor . '.' . ($patch + 1),
            default => throw new InvalidArgumentException("Invalid version type: {$type}")
        };
    }

    public function updateModuleVersion(string $moduleName, string $version): void
    {
        $this->updateModuleEntryFileVersion($moduleName, $version);
    }

    public function updateFrameworkVersion(string $version): void
    {
        $versionFile = BASE_PATH . "/kernel/Core/Bootstrap/Version.php";
        if (!file_exists($versionFile)) {
            throw new InvalidArgumentException("Version.php not found: {$versionFile}");
        }

        $content = file_get_contents($versionFile);
        $content = preg_replace(
            '/define\s*\(\s*["\']KERNEL_VERSION["\']\s*,\s*["\'][^"\']+["\']\s*\)\s*;/',
            "define(\"KERNEL_VERSION\", \"{$version}\");",
            $content
        );

        if ($content === null) {
            throw new \RuntimeException("Failed to update framework version in Version.php");
        }

        file_put_contents($versionFile, $content);
    }

    public function compareVersions(string $version1, string $version2): int
    {
        return version_compare($version1, $version2);
    }

    public function updateModuleEntryFileVersion(string $moduleName, string $version): void
    {
        $modulesDir = BASE_PATH . '/' . \Forge\Core\Structure\StructureResolver::resolveModulesRoot();
        $possiblePaths = [
            "$modulesDir/$moduleName/src/{$moduleName}Module.php",
            "$modulesDir/$moduleName/src/{$moduleName}.php",
        ];

        $entryFilePath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $entryFilePath = $path;
                break;
            }
        }

        if (!$entryFilePath) {
            $dir = "$modulesDir/$moduleName/src";
            if (is_dir($dir)) {
                $files = glob("$dir/*Module.php");
                if (!empty($files)) {
                    $entryFilePath = $files[0];
                }
            }
        }

        if (!$entryFilePath || !file_exists($entryFilePath)) {
            throw new InvalidArgumentException("Module entry file not found for module: {$moduleName}");
        }

        $content = file_get_contents($entryFilePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read module entry file: {$entryFilePath}");
        }

        $pattern = '/(#\[\s*Module\s*\(.*?version\s*:\s*["\'])([^"\']+)(["\'])/s';

        if (!preg_match($pattern, $content)) {
            throw new \RuntimeException("Failed to update version in module entry file: {$entryFilePath}. Pattern not found.");
        }

        $updatedContent = preg_replace_callback($pattern, function ($matches) use ($version) {
            return $matches[1] . $version . $matches[3];
        }, $content);

        if ($updatedContent === null) {
            throw new \RuntimeException("Failed to update version in module entry file: {$entryFilePath}. Replacement failed.");
        }

        if ($updatedContent === $content) {
            return;
        }

        $providesPattern = '/(#\[\s*Provides\s*\(.*?version\s*:\s*["\'])([^"\']+)(["\'])/s';

        if (preg_match($providesPattern, $updatedContent)) {
            $updatedContent = preg_replace_callback($providesPattern, function ($matches) use ($version) {
                return $matches[1] . $version . $matches[3];
            }, $updatedContent);

            if ($updatedContent === null) {
                throw new \RuntimeException("Failed to update version in #[Provides] attribute: {$entryFilePath}");
            }
        }

        if ($updatedContent !== $content) {
            if (file_put_contents($entryFilePath, $updatedContent) === false) {
                throw new \RuntimeException("Failed to write updated module entry file: {$entryFilePath}");
            }
        }
    }
}
