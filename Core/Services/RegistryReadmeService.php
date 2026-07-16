<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\Structure\StructureResolver;

final class RegistryReadmeService
{
    public function __construct(
        private readonly ModuleMetadataService $metadataService,
        private readonly ManifestService $manifestService
    ) {
    }

    public function generateModuleListTable(array $modules): string
    {
        if (empty($modules)) {
            return "| Module | Description | Version | License | Author |\n|--------|-------------|---------|---------|--------|\n";
        }

        $table = "| Module | Description | Version | License | Author |\n";
        $table .= "|--------|-------------|---------|---------|--------|\n";

        foreach ($modules as $module) {
            $name = $module['name'] ?? 'N/A';
            $description = $module['description'] ?? '-';
            $version = $module['version'] ?? 'N/A';
            $license = $module['license'] ?? '-';
            $author = $module['author'] ?? '-';

            $table .= sprintf(
                "| %s | %s | %s | %s | %s |\n",
                $this->escapeTableValue($name),
                $this->escapeTableValue($description),
                $this->escapeTableValue($version),
                $this->escapeTableValue($license),
                $this->escapeTableValue($author)
            );
        }

        return $table;
    }

    public function updateModuleListTable(string $readmePath, array $modules): bool
    {
        if (!file_exists($readmePath)) {
            return false;
        }

        $content = file_get_contents($readmePath);
        if ($content === false) {
            return false;
        }

        $table = $this->generateModuleListTable($modules);
        $tableWithNote = $table . "\n*Module information is automatically generated from module source code.*\n";

        $pattern = '/## Available Modules\s*\n\n(.*?)(?=\n## |$)/s';
        $replacement = "## Available Modules\n\n{$tableWithNote}";

        if (preg_match($pattern, $content)) {
            $updatedContent = preg_replace($pattern, $replacement, $content);
        } else {
            $indexPattern = '/(## Index[^#]*\n\n)/s';
            if (preg_match($indexPattern, $content, $matches)) {
                $afterIndex = $matches[1];
                $updatedContent = str_replace(
                    $afterIndex,
                    $afterIndex . "## Available Modules\n\n{$tableWithNote}\n\n",
                    $content
                );
            } else {
                $updatedContent = $content . "\n\n## Available Modules\n\n{$tableWithNote}\n";
            }
        }

        return file_put_contents($readmePath, $updatedContent) !== false;
    }

    public function removeModuleFromTable(string $readmePath, string $moduleName): bool
    {
        if (!file_exists($readmePath)) {
            return false;
        }

        $content = file_get_contents($readmePath);
        if ($content === false) {
            return false;
        }

        $pattern = '/\| ' . preg_quote($this->escapeTableValue($moduleName), '/') . ' \|.*\n/';
        $updatedContent = preg_replace($pattern, '', $content);

        return file_put_contents($readmePath, $updatedContent) !== false;
    }

    public function updateModuleInTable(string $readmePath, string $moduleName, array $moduleInfo): bool
    {
        if (!file_exists($readmePath)) {
            return false;
        }

        $content = file_get_contents($readmePath);
        if ($content === false) {
            return false;
        }

        $name = $moduleInfo['name'] ?? $moduleName;
        $description = $moduleInfo['description'] ?? '-';
        $version = $moduleInfo['version'] ?? 'N/A';
        $license = $moduleInfo['license'] ?? '-';
        $author = $moduleInfo['author'] ?? '-';

        $newRow = sprintf(
            "| %s | %s | %s | %s | %s |\n",
            $this->escapeTableValue($name),
            $this->escapeTableValue($description),
            $this->escapeTableValue($version),
            $this->escapeTableValue($license),
            $this->escapeTableValue($author)
        );

        $pattern = '/\| ' . preg_quote($this->escapeTableValue($moduleName), '/') . ' \|.*\n/';
        if (preg_match($pattern, $content)) {
            $updatedContent = preg_replace($pattern, $newRow, $content);
        } else {
            $tablePattern = '/(## Available Modules\s*\n\n\| Module \|.*\n\|-+\|.*\n)/s';
            if (preg_match($tablePattern, $content, $matches)) {
                $updatedContent = str_replace($matches[1], $matches[1] . $newRow, $content);
            } else {
                return false;
            }
        }

        return file_put_contents($readmePath, $updatedContent) !== false;
    }

    public function readAllModulesFromRegistry(string $registryPath, string $manifestPath, string $sourceModulesPath): array
    {
        $manifest = $this->manifestService->readModulesManifest($manifestPath);
        if (!$manifest || !is_array($manifest)) {
            return [];
        }

        $modules = [];
        foreach ($manifest as $moduleNameKebab => $moduleData) {
            $moduleNamePascal = $this->kebabToPascal($moduleNameKebab);
            $latestVersion = $moduleData['latest'] ?? null;

            $entryFile = $this->findModuleEntryFile($sourceModulesPath, $moduleNamePascal);
            if (!$entryFile) {
                continue;
            }

            $metadata = $this->metadataService->extractFromFile($entryFile);
            if (!$metadata) {
                continue;
            }

            if ($latestVersion) {
                $metadata['version'] = $latestVersion;
            }

            $modules[] = $metadata;
        }

        usort($modules, function ($a, $b) {
            return strcmp($a['name'] ?? '', $b['name'] ?? '');
        });

        return $modules;
    }

    public function updateReadmeFormat(string $readmePath): bool
    {
        if (!file_exists($readmePath)) {
            return false;
        }

        $content = file_get_contents($readmePath);
        if ($content === false) {
            return false;
        }

        $content = preg_replace('/[📦⛏️✅🛠️👋]/u', '', $content);
        $content = preg_replace('/Hey\s+👋\s+/', '', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return file_put_contents($readmePath, $content) !== false;
    }

    public function addRegistrySetupInstructions(string $readmePath): bool
    {
        if (!file_exists($readmePath)) {
            return false;
        }

        $content = file_get_contents($readmePath);
        if ($content === false) {
            return false;
        }

        $setupSection = "## Adding This Registry\n\nTo use this registry in your Forge project, add it to `config/source_list.php`:\n\n```php\nreturn [\n    'registry' => [\n        [\n            'name' => 'kernel-module-registry',\n            'type' => 'git',\n            'url' => 'https://github.com/forge-kernel/kernel-module-registry',\n            'branch' => 'main',\n            'private' => false,\n            'description' => 'Forge Kernel Official Modules'\n        ]\n    ],\n    'cache_ttl' => 3600\n];\n```\n";

        if (strpos($content, '## Adding This Registry') !== false) {
            $pattern = '/## Adding This Registry.*?(?=\n## |$)/s';
            $content = preg_replace($pattern, $setupSection, $content);
        } else {
            $pattern = '/(## About This Registry.*?\n)/s';
            if (preg_match($pattern, $content, $matches)) {
                $content = str_replace($matches[1], $matches[1] . "\n" . $setupSection . "\n", $content);
            } else {
                $content .= "\n\n" . $setupSection . "\n";
            }
        }

        return file_put_contents($readmePath, $content) !== false;
    }

    public function cleanReadme(string $readmePath): bool
    {
        if (!file_exists($readmePath)) {
            return false;
        }

        $content = file_get_contents($readmePath);
        if ($content === false) {
            return false;
        }

        $content = preg_replace('/[📦⛏️✅🛠️👋🎉💡]/u', '', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return file_put_contents($readmePath, $content) !== false;
    }

    private function findModuleEntryFile(string $basePath, string $moduleName): ?string
    {
        $file = StructureResolver::findModuleEntryFileStatic($basePath, $moduleName);
        if ($file !== null && $this->hasModuleAttribute($file)) {
            return $file;
        }

        $dir = "{$basePath}/{$moduleName}/src";
        if (!is_dir($dir)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $filePath = $fileInfo->getPathname();
                if ($this->hasModuleAttribute($filePath)) {
                    return $filePath;
                }
            }
        }

        return null;
    }

    /**
     * Check if a PHP file contains the #[Module] attribute.
     */
    private function hasModuleAttribute(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return false;
        }

        // Check for #[Module( pattern - the attribute parser will handle the rest
        return preg_match('/#\[Module\s*\(/s', $content) === 1;
    }

    private function escapeTableValue(string $value): string
    {
        return str_replace(['|', "\n", "\r"], ['&#124;', ' ', ''], $value);
    }

    private function kebabToPascal(string $kebab): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $kebab)));
    }
}
