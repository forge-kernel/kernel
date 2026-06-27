<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\TemplateGenerator;

#[Cli(
    command: 'dev:blueprint:init',
    description: 'Initialize a blueprint registry with wizard',
    usage: 'dev:blueprint:init',
    examples: [
        'dev:blueprint:init',
    ]
)]
final class BlueprintInitCommand extends Command
{
    use CliGenerator;

    public function __construct(
        private readonly RegistryService $registryService,
        private readonly TemplateGenerator $templateGenerator
    ) {
    }

    public function execute(array $args): int
    {
        if ($this->registryService->isRegistryDirectoryInitialized('blueprint')) {
            $registryPath = $this->registryService->getRegistryPath('blueprint');
            $messages = [];
            $messages[] = "Blueprint registry already exists at: {$registryPath}";
            $messages[] = "This directory contains a git repository and may have existing data.";
            $messages[] = "Initializing will:";
            $messages[] = "Create/overwrite initial structure files";
            $messages[] = "Set/update git remote origin";
            $messages[] = "Create an initial commit (if not already committed)";
            $messages[] = "Potentially overwrite existing configuration";

            $this->showDangerBox('DESTRUCTIVE ACTION WARNING', $messages, 'This action may cause data loss if the registry is already in use!');

            $confirm = $this->templateGenerator->askQuestion(
                'Type "yes, initialize" to proceed with blueprint registry initialization (or press Enter to cancel): ',
                ''
            );

            if (strtolower(trim($confirm)) !== 'yes, initialize') {
                $this->info('Blueprint registry initialization cancelled. Existing registry left untouched.');
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

        $this->info("Initializing blueprint registry...");

        try {
            $this->registryService->initializeRegistry(
                'blueprint',
                $url,
                $branch,
                $isPrivate
            );

            $this->success("Blueprint registry initialized successfully!");
            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to initialize blueprint registry: " . $e->getMessage());
            return 1;
        }
    }
}
