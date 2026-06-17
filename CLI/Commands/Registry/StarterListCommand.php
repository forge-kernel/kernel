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
    command: 'dev:starter:list',
    description: 'List available starters and versions from registry',
    usage: 'dev:starter:list [--name=starter-name]',
    examples: [
        'dev:starter:list',
        'dev:starter:list --name=blank',
    ]
)]
final class StarterListCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    #[Arg(name: 'name', description: 'Starter name to filter (optional)', required: false)]
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

        $registryPath = $this->registryService->getRegistryPath('starter');
        $manifestPath = $registryPath . '/starters.json';

        if (!file_exists($manifestPath)) {
            $this->error('Starter manifest not found.');
            return 1;
        }

        $manifest = $this->manifestService->readModulesManifest($manifestPath);
        if (!$manifest || !isset($manifest['starters']) || empty($manifest['starters'])) {
            $this->info('No starters found in registry.');
            return 0;
        }

        $starters = $manifest['starters'];

        if ($this->name) {
            $starterNameKebab = self::toKebabCase($this->name);
            if (!isset($starters[$starterNameKebab])) {
                $this->error("Starter '{$starterNameKebab}' not found in registry.");
                return 1;
            }

            $this->line("");
            $this->info("Starter: {$starterNameKebab}");
            $this->info("Name: " . ($starters[$starterNameKebab]['name'] ?? $starterNameKebab));
            $this->info("Description: " . ($starters[$starterNameKebab]['description'] ?? ''));
            $this->info("Latest Version: " . ($starters[$starterNameKebab]['latest'] ?? 'Not defined'));
            $this->line("");
            $this->info("Available Versions:");

            $versions = $starters[$starterNameKebab]['versions'] ?? [];
            foreach ($versions as $version => $details) {
                $versionFile = "starters/{$starterNameKebab}/{$version}/{$version}.zip";
                $commitMessage = $this->gitService->getLastCommitMessage($registryPath, $versionFile);

                if ($commitMessage) {
                    $this->line("  - {$version} - {$commitMessage}");
                } else {
                    $this->line("  - {$version}");
                }

                if (!empty($details['modules'])) {
                    $this->line("    Modules: " . implode(', ', array_keys($details['modules'])));
                }
            }
        } else {
            $this->line("");
            $this->info("Available Starters:");
            $this->line("");

            foreach ($starters as $starterName => $starterInfo) {
                $this->line("-----------------------------------");
                $this->info("Starter: {$starterName}");
                $this->info("Name: " . ($starterInfo['name'] ?? $starterName));
                $this->info("Description: " . ($starterInfo['description'] ?? ''));
                $this->info("Latest Version: " . ($starterInfo['latest'] ?? 'Not defined'));
                $this->line("Available Versions:");

                $versions = $starterInfo['versions'] ?? [];
                if (empty($versions)) {
                    $this->line("  No versions defined.");
                } else {
                    foreach ($versions as $version => $details) {
                        $versionFile = "starters/{$starterName}/{$version}/{$version}.zip";
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
