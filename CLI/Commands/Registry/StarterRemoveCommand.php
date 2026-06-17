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
    command: 'dev:starter:remove',
    description: 'Remove a starter from the registry',
    usage: 'dev:starter:remove --name=starter-name',
    examples: [
        'dev:starter:remove --name=blank',
    ]
)]
final class StarterRemoveCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    #[Arg(name: 'name', description: 'Starter name in kebab-case', required: true)]
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

        if (!$this->name) {
            $this->error('Starter name is required.');
            return 1;
        }

        if (
            !$this->registryService->isRegistryConfigured('starter') &&
            !$this->registryService->isRegistryDirectoryInitialized('starter')
        ) {
            $this->error('Starter registry not found or not configured.');
            $this->info('Run: php forge.php dev:starter:init');
            return 1;
        }

        $registryPath = $this->registryService->getRegistryPath('starter');
        $manifestPath = $registryPath . '/starters.json';
        $starterNameKebab = self::toKebabCase($this->name);
        $starterDir = $registryPath . '/starter-templates/' . $starterNameKebab;

        $manifest = $this->manifestService->readModulesManifest($manifestPath);
        $starters = $manifest['starters'] ?? [];

        if (!isset($starters[$starterNameKebab])) {
            $this->error("Starter '{$this->name}' not found in registry.");
            return 1;
        }

        $messages = [];
        $messages[] = "This will DELETE the following:";
        $messages[] = "  - Starter directory: starter-templates/{$starterNameKebab}/";
        $messages[] = "  - Starter entry from starters.json";
        $messages[] = "";
        $messages[] = "Starter: {$this->name}";
        $messages[] = "Latest version: " . ($starters[$starterNameKebab]['latest'] ?? 'N/A');

        $this->showDangerBox('DESTRUCTIVE ACTION WARNING', $messages, 'This action cannot be undone!');

        $confirm = $this->templateGenerator->askQuestion(
            "Type \"yes, remove {$this->name}\" to proceed or press Enter to cancel: ",
            ''
        );

        if (strtolower(trim($confirm)) !== strtolower("yes, remove {$this->name}")) {
            $this->info('Starter removal cancelled.');
            return 0;
        }

        $this->line('');

        if (is_dir($starterDir)) {
            $this->info("Removing starter directory: {$starterNameKebab}...");
            $this->removeDirectory($starterDir);
        }

        unset($starters[$starterNameKebab]);
        $manifest['starters'] = $starters;

        $this->info('Updating starters.json...');
        if (!$this->manifestService->writeModulesManifest($manifestPath, $manifest)) {
            $this->error('Failed to update starters.json.');
            return 1;
        }

        if ($this->gitService->isGitRepository($registryPath)) {
            $this->info('Committing changes...');
            $this->gitService->addAll($registryPath);
            if (!$this->gitService->commit($registryPath, "Remove starter {$this->name} from registry")) {
                $this->warning('Failed to commit changes, but starter was removed.');
            }
        }

        $this->success("Starter '{$this->name}' removed successfully!");
        return 0;
    }

    private function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }
}
