<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\GitService;
use Forge\Core\Services\ManifestService;
use Forge\Core\Services\RegistryReadmeService;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\TemplateGenerator;
use Forge\Traits\StringHelper;

#[Cli(
    command: 'dev:registry:module:remove',
    description: 'Remove module from registry and update README',
    usage: 'dev:registry:module:remove [--name=ModuleName]',
    examples: [
        'dev:registry:module:remove --name=ForgeLogger',
    ]
)]
final class RegistryModuleRemoveCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    #[Arg(name: 'name', description: 'Module name in PascalCase', required: true)]
    private ?string $name = null;

    public function __construct(
        private readonly RegistryService $registryService,
        private readonly ManifestService $manifestService,
        private readonly RegistryReadmeService $readmeService,
        private readonly GitService $gitService,
        private readonly TemplateGenerator $templateGenerator
    ) {
    }

    public function execute(array $args): int
    {
        $this->wizard($args);

        if (!$this->name) {
            $this->error('Module name is required.');
            return 1;
        }

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
        $moduleNameKebab = self::toKebabCase($this->name);
        $moduleDir = $registryPath . '/modules/' . $moduleNameKebab;

        $manifest = $this->manifestService->readModulesManifest($manifestPath);
        if (!$manifest || !isset($manifest[$moduleNameKebab])) {
            $this->error("Module '{$this->name}' not found in registry.");
            return 1;
        }

        $messages = [];
        $messages[] = "This will DELETE the following:";
        $messages[] = "  - Module directory: modules/{$moduleNameKebab}/";
        $messages[] = "  - Module entry from modules.json";
        $messages[] = "  - Module entry from README.md";
        $messages[] = "";
        $messages[] = "Module: {$this->name}";
        $messages[] = "Latest version: " . ($manifest[$moduleNameKebab]['latest'] ?? 'N/A');

        $this->showDangerBox('DESTRUCTIVE ACTION WARNING', $messages, 'This action cannot be undone!');

        $confirm = $this->templateGenerator->askQuestion(
            "Type \"yes, remove {$this->name}\" to proceed or press Enter to cancel: ",
            ''
        );

        if (strtolower(trim($confirm)) !== strtolower("yes, remove {$this->name}")) {
            $this->info('Module removal cancelled.');
            return 0;
        }

        $this->line('');

        if (is_dir($moduleDir)) {
            $this->info("Removing module directory: {$moduleNameKebab}...");
            $this->removeDirectory($moduleDir);
        }

        unset($manifest[$moduleNameKebab]);
        $this->info('Updating modules.json...');
        if (!$this->manifestService->writeModulesManifest($manifestPath, $manifest)) {
            $this->error('Failed to update modules.json.');
            return 1;
        }

        $this->info('Updating README...');
        if (!$this->readmeService->removeModuleFromTable($readmePath, $this->name)) {
            $this->warning('Failed to remove module from README table, but module was removed from registry.');
        }

        if ($this->gitService->isGitRepository($registryPath)) {
            $this->info('Committing changes...');
            $this->gitService->addAll($registryPath);
            if (!$this->gitService->commit($registryPath, "Remove module {$this->name} from registry")) {
                $this->warning('Failed to commit changes, but module was removed.');
            }
        }

        $this->success("Module '{$this->name}' removed successfully!");
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
