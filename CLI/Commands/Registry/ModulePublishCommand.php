<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Config\Environment;
use Forge\Core\Services\GitService;
use Forge\Core\Services\RegistryService;
use Forge\Traits\StringHelper;

#[Cli(
    command: 'module:publish',
    description: 'Publish module registry changes to remote repository',
    usage: 'dev:module:publish [--name=ModuleName]',
    examples: [
        'dev:module:publish',
        'dev:module:publish --name=ForgeLogger',
    ]
)]
final class ModulePublishCommand extends Command
{
    use CliGenerator;
    use StringHelper;
    
    #[Arg(name: 'name', description: 'Module name (optional, publishes all if not specified)', required: false)]
    private ?string $name = null;
    
    public function __construct(
        private readonly RegistryService $registryService,
        private readonly GitService $gitService
    ) {}
    
    public function execute(array $args): int
    {
        $this->wizard($args);
        
        if (!$this->registryService->isRegistryConfigured('modules') && 
            !$this->registryService->isRegistryDirectoryInitialized('modules')) {
            $this->error('Modules registry not found or not configured.');
            $this->info('Run: php forge.php dev:registry:init --type=modules');
            return 1;
        }
        
        $registryPath = $this->registryService->getRegistryPath('modules');
        $config = $this->registryService->getRegistryConfig('modules');
        
        if (!$config) {
            $this->info('Auto-detecting modules registry configuration from git repository...');
            $config = $this->registryService->getRegistryConfigOrDetect('modules');
        }
        
        if (!$config) {
            $this->error('Registry configuration not found and could not be auto-detected.');
            $this->info('Run: php forge.php dev:registry:init --type=modules');
            return 1;
        }
        
        if (!isset($config['url']) || $config['url'] === null) {
            $this->error('Registry remote URL not found. Cannot publish without a remote repository.');
            $this->info('Run: php forge.php dev:registry:config --type=modules --url=<repository_url>');
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
        
        $this->success("Module registry published successfully!");
        return 0;
    }
}

