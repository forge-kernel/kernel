<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\GitService;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\TemplateGenerator;

#[Cli(
    command: 'registry:config',
    description: 'Configure registry settings (git origin, branch, etc.)',
    usage: 'dev:registry:config [--type=framework|modules]',
    examples: [
        'dev:registry:config --type=modules',
        'dev:registry:config --type=framework',
    ]
)]
final class RegistryConfigCommand extends Command
{
    use CliGenerator;
    
    #[Arg(name: 'type', description: 'Registry type (framework or modules)', required: false)]
    private ?string $type = null;
    
    public function __construct(
        private readonly RegistryService $registryService,
        private readonly GitService $gitService,
        private readonly TemplateGenerator $templateGenerator
    ) {}
    
    public function execute(array $args): int
    {
        $this->wizard($args);
        
        if (!$this->type) {
            $this->type = $this->templateGenerator->askQuestion(
                'Registry type (framework/modules): ',
                'modules'
            );
        }
        
        if (!in_array($this->type, ['framework', 'modules'], true)) {
            $this->error('Invalid registry type. Must be "framework" or "modules".');
            return 1;
        }
        
        $config = $this->registryService->getRegistryConfig($this->type);
        
        if ($config) {
            $this->info("Current configuration for {$this->type} registry:");
            $this->line("URL: " . ($config['url'] ?? 'Not set'));
            $this->line("Branch: " . ($config['branch'] ?? 'Not set'));
            $this->line("Private: " . ($config['private'] ? 'Yes' : 'No'));
            $this->line("Path: " . ($config['path'] ?? 'Not set'));
            $this->line("");
        } else {
            $this->info("No configuration found for {$this->type} registry.");
            $this->line("");
        }
        
        $url = $this->templateGenerator->askQuestion(
            'Git repository URL: ',
            $config['url'] ?? ''
        );
        
        if (empty($url)) {
            $this->error('Git repository URL is required.');
            return 1;
        }
        
        $branch = $this->templateGenerator->askQuestion(
            'Branch name: ',
            $config['branch'] ?? 'main'
        );
        
        $isPrivateInput = $this->templateGenerator->askQuestion(
            'Is this a private repository? (yes/no): ',
            $config['private'] ? 'yes' : 'no'
        );
        $isPrivate = in_array(strtolower($isPrivateInput), ['yes', 'y', '1', 'true'], true);
        
        $path = $this->registryService->getRegistryPath($this->type);
        
        $this->info("Updating registry configuration...");
        
        $configPath = BASE_PATH . '/config/registry.php';
        $allConfig = [];

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($configPath, true);
        }
        \Forge\Core\Helpers\FileExistenceCache::clearPath($configPath);
        if (file_exists($configPath)) {
            $allConfig = require $configPath;
            \Forge\Core\Helpers\FileExistenceCache::clearPath($configPath);
        }

        $allConfig[$this->type] = [
            'url' => $url,
            'branch' => $branch,
            'private' => $isPrivate,
            'path' => $path,
        ];

        $content = "<?php\n\nreturn [\n";
        foreach ($allConfig as $key => $entry) {
            $relativePath = str_replace(BASE_PATH, '', $entry['path']);
            $content .= "    '{$key}' => [\n";
            $content .= "        'url' => '" . addslashes($entry['url']) . "',\n";
            $content .= "        'branch' => '" . addslashes($entry['branch']) . "',\n";
            $content .= "        'private' => " . ($entry['private'] ? 'true' : 'false') . ",\n";
            $content .= "        'path' => BASE_PATH . '" . addslashes($relativePath) . "',\n";
            $content .= "    ],\n";
        }
        $content .= "];\n";

        file_put_contents($configPath, $content);
        
        if ($this->registryService->isRegistryConfigured($this->type)) {
            $this->gitService->setRemote($path, 'origin', $url);
        }
        
        $this->success("Registry configuration updated successfully!");
        return 0;
    }
}

