<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\TemplateGenerator;

#[Cli(
    command: 'dev:starter:init',
    description: 'Initialize a starter registry with wizard',
    usage: 'dev:starter:init',
    examples: [
        'dev:starter:init',
    ]
)]
final class StarterInitCommand extends Command
{
    use CliGenerator;

    public function __construct(
        private readonly RegistryService $registryService,
        private readonly TemplateGenerator $templateGenerator
    ) {
    }

    public function execute(array $args): int
    {
        if ($this->registryService->isRegistryDirectoryInitialized('starter')) {
            $registryPath = $this->registryService->getRegistryPath('starter');
            $messages = [];
            $messages[] = "Starter registry already exists at: {$registryPath}";
            $messages[] = "This directory contains a git repository and may have existing data.";
            $messages[] = "Initializing will:";
            $messages[] = "Create/overwrite initial structure files";
            $messages[] = "Set/update git remote origin";
            $messages[] = "Create an initial commit (if not already committed)";
            $messages[] = "Potentially overwrite existing configuration";

            $this->showDangerBox('DESTRUCTIVE ACTION WARNING', $messages, 'This action may cause data loss if the registry is already in use!');

            $confirm = $this->templateGenerator->askQuestion(
                'Type "yes, initialize" to proceed with starter registry initialization (or press Enter to cancel): ',
                ''
            );

            if (strtolower(trim($confirm)) !== 'yes, initialize') {
                $this->info('Starter registry initialization cancelled. Existing registry left untouched.');
                return 0;
            }
            $this->line("");
        }

        $url = $this->templateGenerator->askQuestion(
            'Git repository URL: ',
            ''
        );

        if (empty($url)) {
            $this->error('Git repository URL is required.');
            return 1;
        }

        $branch = $this->templateGenerator->askQuestion(
            'Branch name: ',
            'main'
        );

        $isPrivateInput = $this->templateGenerator->askQuestion(
            'Is this a private repository? (yes/no): ',
            'no'
        );
        $isPrivate = in_array(strtolower($isPrivateInput), ['yes', 'y', '1', 'true'], true);

        $this->info("Initializing starter registry...");

        try {
            $this->registryService->initializeRegistry(
                'starter',
                $url,
                $branch,
                $isPrivate
            );

            $this->success("Starter registry initialized successfully!");
            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to initialize starter registry: " . $e->getMessage());
            return 1;
        }
    }
}
