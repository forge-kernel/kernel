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
    command: 'dev:blueprint:remove',
    description: 'Remove a blueprint from the registry',
    usage: 'dev:blueprint:remove --name=blueprint-name',
    examples: [
        'dev:blueprint:remove --name=blank',
    ]
)]
final class BlueprintRemoveCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    #[Arg(name: 'name', description: 'Blueprint name in kebab-case', required: true)]
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
            $this->error('Blueprint name is required.');
            return 1;
        }

        if (
            !$this->registryService->isRegistryConfigured('blueprint') &&
            !$this->registryService->isRegistryDirectoryInitialized('blueprint')
        ) {
            $this->error('Blueprint registry not found or not configured.');
            $this->info('Run: php forge.php dev:blueprint:init');
            return 1;
        }

        $registryPath = $this->registryService->getRegistryPath('blueprint');
        $manifestPath = $registryPath . '/blueprints.json';
        $blueprintNameKebab = self::toKebabCase($this->name);
        $blueprintDir = $registryPath . '/blueprints/' . $blueprintNameKebab;

        $manifest = $this->manifestService->readModulesManifest($manifestPath);
        $blueprints = $manifest['blueprints'] ?? [];

        if (!isset($blueprints[$blueprintNameKebab])) {
            $this->error("Blueprint '{$this->name}' not found in registry.");
            return 1;
        }

        $messages = [];
        $messages[] = "This will DELETE the following:";
        $messages[] = "  - Blueprint directory: blueprints/{$blueprintNameKebab}/";
        $messages[] = "  - Blueprint entry from blueprints.json";
        $messages[] = "";
        $messages[] = "Blueprint: {$this->name}";
        $messages[] = "Latest version: " . ($blueprints[$blueprintNameKebab]['latest'] ?? 'N/A');

        $this->showDangerBox('DESTRUCTIVE ACTION WARNING', $messages, 'This action cannot be undone!');

        $confirm = $this->templateGenerator->askQuestion(
            "Type \"yes, remove {$this->name}\" to proceed or press Enter to cancel: ",
            ''
        );

        if (strtolower(trim($confirm)) !== strtolower("yes, remove {$this->name}")) {
            $this->info('Blueprint removal cancelled.');
            return 0;
        }

        $this->line('');

        if (is_dir($blueprintDir)) {
            $this->info("Removing blueprint directory: {$blueprintNameKebab}...");
            $this->removeDirectory($blueprintDir);
        }

        unset($blueprints[$blueprintNameKebab]);
        $manifest['blueprints'] = $blueprints;

        $this->info('Updating blueprints.json...');
        if (!$this->manifestService->writeModulesManifest($manifestPath, $manifest)) {
            $this->error('Failed to update blueprints.json.');
            return 1;
        }

        if ($this->gitService->isGitRepository($registryPath)) {
            $this->info('Committing changes...');
            $this->gitService->addAll($registryPath);
            if (!$this->gitService->commit($registryPath, "Remove blueprint {$this->name} from registry")) {
                $this->warning('Failed to commit changes, but blueprint was removed.');
            }
        }

        $this->success("Blueprint '{$this->name}' removed successfully!");
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
