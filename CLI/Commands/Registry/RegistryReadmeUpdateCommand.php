<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\ManifestService;
use Forge\Core\Services\RegistryReadmeService;
use Forge\Core\Services\RegistryService;
use Forge\Core\Structure\StructureResolver;
use Forge\Traits\StringHelper;

#[Cli(
    command: 'dev:registry:readme:update',
    description: 'Update README format, philosophy, and regenerate module list table',
    usage: 'dev:registry:readme:update',
    examples: [
        'dev:registry:readme:update',
    ]
)]
final class RegistryReadmeUpdateCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    public function __construct(
        private readonly RegistryService $registryService,
        private readonly RegistryReadmeService $readmeService,
        private readonly ManifestService $manifestService
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
        $readmePath = $registryPath . '/README.md';
        $sourceModulesPath = BASE_PATH . '/' . StructureResolver::resolveModulesRoot();

        $this->info('Updating README format...');
        $this->readmeService->updateReadmeFormat($readmePath);

        $this->info('Adding registry setup instructions...');
        $this->readmeService->addRegistrySetupInstructions($readmePath);

        $this->info('Reading modules from registry...');
        $modules = $this->readmeService->readAllModulesFromRegistry($registryPath, $manifestPath, $sourceModulesPath);

        $this->info('Updating module list table...');
        if (!$this->readmeService->updateModuleListTable($readmePath, $modules)) {
            $this->error('Failed to update module list table.');
            return 1;
        }

        $this->success('README updated successfully!');
        return 0;
    }
}
