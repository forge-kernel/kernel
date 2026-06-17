<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\GitService;
use Forge\Core\Services\ManifestService;
use Forge\Core\Services\RegistryService;

#[Cli(
    command: 'dev:kernel:list',
    description: 'List available Kernel versions from registry',
    usage: 'dev:kernel:list',
    examples: [
        'dev:kernel:list',
    ]
)]
final class FrameworkListCommand extends Command
{
    use CliGenerator;

    public function __construct(
        private readonly RegistryService $registryService,
        private readonly ManifestService $manifestService,
        private readonly GitService $gitService
    ) {
    }

    public function execute(array $args): int
    {
        $registryPath = $this->registryService->getRegistryPath('framework');
        $manifestPath = $registryPath . '/forge.json';

        if (!file_exists($manifestPath)) {
            $this->error('Framework manifest not found.');
            return 1;
        }

        $manifest = $this->manifestService->readFrameworkManifest($manifestPath);

        if (!$manifest || !isset($manifest['versions'])) {
            $this->info('No framework versions found in registry.');
            return 0;
        }

        $versions = $manifest['versions'];
        $latest = $versions['latest'] ?? null;

        $this->info("Available Forge Framework Versions:");
        $this->line("");
        $this->line("-----------------------------------");

        foreach ($versions as $versionName => $versionDetails) {
            if ($versionName !== 'latest') {
                $versionFile = 'versions/' . $versionName . '.zip';
                $commitMessage = $this->gitService->getLastCommitMessage($registryPath, $versionFile);

                if ($commitMessage) {
                    $this->line("- {$versionName} - {$commitMessage}");
                } else {
                    $this->line("- {$versionName}");
                }
            }
        }

        $this->line("-----------------------------------");
        $this->info("Latest Version: " . ($latest ?? 'Not defined'));

        return 0;
    }
}
