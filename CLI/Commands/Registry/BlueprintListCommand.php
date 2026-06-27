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
    command: 'dev:blueprint:list',
    description: 'List available blueprints and versions from registry',
    usage: 'dev:blueprint:list [--name=blueprint-name]',
    examples: [
        'dev:blueprint:list',
        'dev:blueprint:list --name=blank',
    ]
)]
final class BlueprintListCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    #[Arg(name: 'name', description: 'Blueprint name to filter (optional)', required: false)]
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

        $registryPath = $this->registryService->getRegistryPath('blueprint');
        $manifestPath = $registryPath . '/blueprints.json';

        if (!file_exists($manifestPath)) {
            $this->error('Blueprint manifest not found.');
            return 1;
        }

        $manifest = $this->manifestService->readModulesManifest($manifestPath);
        if (!$manifest || !isset($manifest['blueprints']) || empty($manifest['blueprints'])) {
            $this->info('No blueprints found in registry.');
            return 0;
        }

        $blueprints = $manifest['blueprints'];

        if ($this->name) {
            $blueprintNameKebab = self::toKebabCase($this->name);
            if (!isset($blueprints[$blueprintNameKebab])) {
                $this->error("Blueprint '{$blueprintNameKebab}' not found in registry.");
                return 1;
            }

            $this->line("");
            $this->info("Blueprint: {$blueprintNameKebab}");
            $this->info("Name: " . ($blueprints[$blueprintNameKebab]['name'] ?? $blueprintNameKebab));
            $this->info("Description: " . ($blueprints[$blueprintNameKebab]['description'] ?? ''));
            $this->info("Latest Version: " . ($blueprints[$blueprintNameKebab]['latest'] ?? 'Not defined'));
            $this->line("");
            $this->info("Available Versions:");

            $versions = $blueprints[$blueprintNameKebab]['versions'] ?? [];
            foreach ($versions as $version => $details) {
                $versionFile = "blueprints/{$blueprintNameKebab}/{$version}/{$version}.zip";
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
            $this->info("Available Blueprints:");
            $this->line("");

            foreach ($blueprints as $blueprintName => $blueprintInfo) {
                $this->line("-----------------------------------");
                $this->info("Blueprint: {$blueprintName}");
                $this->info("Name: " . ($blueprintInfo['name'] ?? $blueprintName));
                $this->info("Description: " . ($blueprintInfo['description'] ?? ''));
                $this->info("Latest Version: " . ($blueprintInfo['latest'] ?? 'Not defined'));
                $this->line("Available Versions:");

                $versions = $blueprintInfo['versions'] ?? [];
                if (empty($versions)) {
                    $this->line("  No versions defined.");
                } else {
                    foreach ($versions as $version => $details) {
                        $versionFile = "blueprints/{$blueprintName}/{$version}/{$version}.zip";
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
