<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Config\Environment;
use Forge\Core\Services\GitService;
use Forge\Core\Services\RegistryService;

#[Cli(
    command: 'dev:kernel:publish',
    description: 'Publish kernel registry changes to remote repository',
    usage: 'dev:kernel:publish',
    examples: [
        'dev:kernel:publish',
    ]
)]
final class FrameworkPublishCommand extends Command
{
    use CliGenerator;

    public function __construct(
        private readonly RegistryService $registryService,
        private readonly GitService $gitService
    ) {
    }

    public function execute(array $args): int
    {
        if (
            !$this->registryService->isRegistryConfigured('kernel') &&
            !$this->registryService->isRegistryDirectoryInitialized('kernel')
        ) {
            $this->error('Kernel registry not found or not configured.');
            $this->info('Run: php forge.php dev:registry:init --type=kernel');
            return 1;
        }

        $registryPath = $this->registryService->getRegistryPath('kernel');
        $config = $this->registryService->getRegistryConfig('kernel');

        if (!$config) {
            $this->info('Auto-detecting kernel registry configuration from git repository...');
            $config = $this->registryService->getRegistryConfigOrDetect('kernel');
        }

        if (!$config) {
            $this->error('Registry configuration not found and could not be auto-detected.');
            $this->info('Run: php forge.php dev:registry:init --type=kernel');
            return 1;
        }

        if (!isset($config['url']) || $config['url'] === null) {
            $this->error('Registry remote URL not found. Cannot publish without a remote repository.');
            $this->info('Run: php forge.php dev:registry:config --type=kernel --url=<repository_url>');
            return 1;
        }

        $branch = $config['branch'] ?? 'main';
        $env = Environment::getInstance();
        $token = $config['private'] ? $env->get('GITHUB_TOKEN') : null;

        $this->info("Pushing changes to remote repository...");

        if (!$this->gitService->push($registryPath, $branch, $token)) {
            $this->error("Failed to push changes.");
            return 1;
        }

        $this->success("Kernel registry published successfully!");
        return 0;
    }
}
