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
    command: 'framework:publish',
    description: 'Publish framework registry changes to remote repository',
    usage: 'dev:framework:publish',
    examples: [
        'dev:framework:publish',
    ]
)]
final class FrameworkPublishCommand extends Command
{
    use CliGenerator;
    
    public function __construct(
        private readonly RegistryService $registryService,
        private readonly GitService $gitService
    ) {}
    
    public function execute(array $args): int
    {
        if (!$this->registryService->isRegistryConfigured('framework') && 
            !$this->registryService->isRegistryDirectoryInitialized('framework')) {
            $this->error('Framework registry not found or not configured.');
            $this->info('Run: php forge.php dev:registry:init --type=framework');
            return 1;
        }
        
        $registryPath = $this->registryService->getRegistryPath('framework');
        $config = $this->registryService->getRegistryConfig('framework');
        
        if (!$config) {
            $this->info('Auto-detecting framework registry configuration from git repository...');
            $config = $this->registryService->getRegistryConfigOrDetect('framework');
        }
        
        if (!$config) {
            $this->error('Registry configuration not found and could not be auto-detected.');
            $this->info('Run: php forge.php dev:registry:init --type=framework');
            return 1;
        }
        
        if (!isset($config['url']) || $config['url'] === null) {
            $this->error('Registry remote URL not found. Cannot publish without a remote repository.');
            $this->info('Run: php forge.php dev:registry:config --type=framework --url=<repository_url>');
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
        
        $this->success("Framework registry published successfully!");
        return 0;
    }
}

