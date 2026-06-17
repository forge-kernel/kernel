<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\GitService;
use Forge\Core\Services\ManifestService;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\TemplateGenerator;
use Forge\Traits\StringHelper;

#[Cli(
    command: 'dev:module:list',
    description: 'List available module versions from registry',
    usage: 'dev:module:list [--name=ModuleName]',
    examples: [
        'dev:module:list',
        'dev:module:list --name=ForgeLogger',
    ]
)]
final class ModuleListCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    #[Arg(name: 'name', description: 'Module name to filter (optional)', required: false)]
    private ?string $name = null;

    public function __construct(
        private readonly RegistryService $registryService,
        private readonly ManifestService $manifestService,
        private readonly GitService $gitService,
        private readonly TemplateGenerator $templateGenerator
    ) {
    }

    public function execute(array $args): int
    {
        $this->wizard($args);

        $registryPath = $this->registryService->getRegistryPath('modules');
        $manifestPath = $registryPath . '/modules.json';

        if (!file_exists($manifestPath)) {
            $this->error('Modules manifest not found.');
            return 1;
        }

        $manifest = $this->manifestService->readModulesManifest($manifestPath);

        if (!$manifest || empty($manifest)) {
            $this->info('No modules found in registry.');
            return 0;
        }

        $selectedModule = $this->name;

        if (!$selectedModule) {
            $moduleNames = array_keys($manifest);
            sort($moduleNames);
            $moduleNames[] = 'All modules';

            $selected = $this->templateGenerator->selectFromList(
                "Select a module",
                $moduleNames,
                'All modules'
            );

            if ($selected === null) {
                $this->info('Selection cancelled.');
                return 0;
            }

            if ($selected === 'All modules') {
                $selectedModule = null;
            } else {
                $selectedModule = $selected;
            }
        }

        if ($selectedModule) {
            $moduleNameKebab = self::toKebabCase($selectedModule);
            if (!isset($manifest[$moduleNameKebab])) {
                $this->error("Module '{$moduleNameKebab}' not found in registry.");
                return 1;
            }

            $this->line("");
            $this->info("Module: {$moduleNameKebab}");
            $this->info("Latest Version: " . ($manifest[$moduleNameKebab]['latest'] ?? 'Not defined'));
            $this->line("");
            $this->info("Available Versions:");

            $versions = $manifest[$moduleNameKebab]['versions'] ?? [];
            foreach ($versions as $version => $details) {
                $versionFile = "modules/{$moduleNameKebab}/{$version}/{$version}.zip";
                $commitMessage = $this->gitService->getLastCommitMessage($registryPath, $versionFile);

                if ($commitMessage) {
                    $this->line("  - {$version} - {$commitMessage}");
                } else {
                    $this->line("  - {$version}");
                }
            }
        } else {
            $this->line("");
            $this->info("Available Modules and Versions:");
            $this->line("");

            foreach ($manifest as $moduleName => $moduleInfo) {
                $this->line("-----------------------------------");
                $this->info("Module: {$moduleName}");
                $this->info("Latest Version: " . ($moduleInfo['latest'] ?? 'Not defined'));
                $this->line("Available Versions:");

                $versions = $moduleInfo['versions'] ?? [];
                if (empty($versions)) {
                    $this->line("  No versions defined.");
                } else {
                    foreach ($versions as $version => $details) {
                        $versionFile = "modules/{$moduleName}/{$version}/{$version}.zip";
                        $commitMessage = $this->gitService->getLastCommitMessage($registryPath, $versionFile);

                        if ($commitMessage) {
                            $this->line("  - {$version} - {$commitMessage}");
                        } else {
                            $this->line("  - {$version}");
                        }
                    }
                }
                $this->line("");
            }
        }

        return 0;
    }
}
