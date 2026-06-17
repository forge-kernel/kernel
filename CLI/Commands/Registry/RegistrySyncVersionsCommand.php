<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\GitService;
use Forge\Core\Services\ManifestService;
use Forge\Core\Services\ModuleMetadataService;
use Forge\Core\Services\RegistryReadmeService;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\TemplateGenerator;
use Forge\Traits\StringHelper;

#[Cli(
    command: 'dev:registry:sync:versions',
    description: 'Bulk update all module versions in registry',
    usage: 'dev:registry:sync:versions',
    examples: [
        'dev:registry:sync:versions',
    ]
)]
final class RegistrySyncVersionsCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    public function __construct(
        private readonly RegistryService $registryService,
        private readonly ManifestService $manifestService,
        private readonly ModuleMetadataService $metadataService,
        private readonly RegistryReadmeService $readmeService,
        private readonly GitService $gitService,
        private readonly TemplateGenerator $templateGenerator
    ) {
    }

    public function execute(array $args): int
    {
        $this->wizard($args);

        if (
            !$this->registryService->isRegistryConfigured('modules') &&
            !$this->registryService->isRegistryDirectoryInitialized('modules')
        ) {
            $this->error('Modules registry not found or not configured.');
            $this->info('Run: php forge.php dev:registry:init --type=modules');
            return 1;
        }

        $registryPath = $this->registryService->getRegistryPath('modules');
        $manifestPath = $registryPath . '/modules.json';
        $sourceModulesPath = BASE_PATH . '/modules';

        $manifest = $this->manifestService->readModulesManifest($manifestPath);
        if (!$manifest || !is_array($manifest)) {
            $this->error('Failed to read modules.json.');
            return 1;
        }

        $this->info('Reading module versions from source files...');
        $updated = false;
        $updatedModules = [];

        foreach ($manifest as $moduleNameKebab => $moduleData) {
            $moduleNamePascal = $this->kebabToPascal($moduleNameKebab);
            $entryFile = $this->findModuleEntryFile($sourceModulesPath, $moduleNamePascal);

            if (!$entryFile) {
                continue;
            }

            $metadata = $this->metadataService->extractFromFile($entryFile);
            if (!$metadata || !isset($metadata['version'])) {
                $this->warning("Could not extract version for {$moduleNamePascal}, skipping...");
                continue;
            }

            $currentVersion = $metadata['version'];
            $latestInManifest = $moduleData['latest'] ?? null;

            if ($currentVersion !== $latestInManifest) {
                $this->info("Updating {$moduleNameKebab}: {$latestInManifest} -> {$currentVersion}");
                $manifest[$moduleNameKebab]['latest'] = $currentVersion;
                $updated = true;
                $updatedModules[] = [
                    'name' => $moduleNameKebab,
                    'old' => $latestInManifest ?? 'none',
                    'new' => $currentVersion,
                ];
            }
        }

        if (!$updated) {
            $this->info('All module versions are already up to date.');
            return 0;
        }

        $this->info('Updating modules.json...');
        if (!$this->manifestService->writeModulesManifest($manifestPath, $manifest)) {
            $this->error('Failed to update modules.json.');
            return 1;
        }

        $this->info('Updating README module list...');
        $modules = $this->readmeService->readAllModulesFromRegistry($registryPath, $manifestPath, $sourceModulesPath);
        $readmePath = $registryPath . '/README.md';
        if (!$this->readmeService->updateModuleListTable($readmePath, $modules)) {
            $this->warning('Failed to update README, but modules.json was updated.');
        }

        if ($this->gitService->isGitRepository($registryPath)) {
            // Show summary of updated modules
            if (!empty($updatedModules)) {
                $this->info('Modules updated:');
                foreach ($updatedModules as $module) {
                    $this->line("  - {$module['name']}: {$module['old']} -> {$module['new']}");
                }
                $this->line('');
            }

            // Prompt for commit message
            $defaultMessage = 'Sync all module versions';
            $commitMessage = $this->templateGenerator->askQuestion(
                'Enter commit message (or press Enter for default): ',
                $defaultMessage
            );

            $this->info('Committing changes...');
            $this->gitService->addAll($registryPath);
            if (!$this->gitService->commit($registryPath, $commitMessage)) {
                $this->warning('Failed to commit changes, but files were updated.');
            }
        }

        $this->success('All module versions synced successfully!');
        return 0;
    }

    private function findModuleEntryFile(string $basePath, string $moduleName): ?string
    {
        $checkedPaths = [];
        $possiblePaths = [
            "{$basePath}/{$moduleName}/src/{$moduleName}Module.php",
            "{$basePath}/{$moduleName}/src/{$moduleName}.php",
        ];

        foreach ($possiblePaths as $path) {
            $checkedPaths[] = $path;
            if (file_exists($path) && $this->hasModuleAttribute($path)) {
                return $path;
            }
        }

        $dir = "{$basePath}/{$moduleName}/src";
        if (!is_dir($dir)) {
            $this->warning("Module entry file not found for {$moduleName}. Checked paths:");
            foreach ($checkedPaths as $path) {
                $this->line("  - {$path}");
            }
            return null;
        }

        // Search all PHP files and check for #[Module] attribute
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getPathname();
                $checkedPaths[] = $filePath;

                // Skip files we already checked
                if (in_array($filePath, $possiblePaths, true)) {
                    continue;
                }

                if ($this->hasModuleAttribute($filePath)) {
                    return $filePath;
                }
            }
        }

        // Log warning with checked paths
        $this->warning("Module entry file not found for {$moduleName}. Checked paths:");
        foreach ($checkedPaths as $path) {
            $this->line("  - {$path}");
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

    private function kebabToPascal(string $kebab): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $kebab)));
    }
}
