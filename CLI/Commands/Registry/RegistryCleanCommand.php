<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\TemplateGenerator;
use Forge\Traits\StringHelper;

#[Cli(
  command: 'registry:clean',
  description: 'Clean modules registry from scratch, preserving essential files',
  usage: 'dev:registry:clean',
  examples: [
    'dev:registry:clean',
  ]
)]
final class RegistryCleanCommand extends Command
{
  use CliGenerator;
  use StringHelper;

  public function __construct(
    private readonly RegistryService $registryService,
    private readonly TemplateGenerator $templateGenerator
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

    $messages = [];
    $messages[] = "This will DELETE the following from the registry:";
    $messages[] = "  - All module ZIP files in modules/ directory";
    $messages[] = "  - modules.json manifest file";
    $messages[] = "";
    $messages[] = "The following files will be PRESERVED:";
    $messages[] = "  - README.md";
    $messages[] = "  - LICENSE";
    $messages[] = "  - LICENSE-MIT.txt";
    $messages[] = "  - CHANGELOG.md";
    $messages[] = "  - CODE_OF_CONDUCT.MD";

    $this->showDangerBox('DESTRUCTIVE ACTION WARNING', $messages, 'This action cannot be undone!');

    $confirm = $this->templateGenerator->askQuestion(
      'Type "yes, clean" to proceed or press Enter to cancel: ',
      ''
    );

    if (strtolower(trim($confirm)) !== 'yes, clean') {
      $this->info('Registry cleanup cancelled.');
      return 0;
    }

    $this->line('');

    $modulesDir = $registryPath . '/modules';
    if (is_dir($modulesDir)) {
      $this->info('Removing modules directory...');
      $this->removeDirectory($modulesDir);
    }

    $manifestPath = $registryPath . '/modules.json';
    if (file_exists($manifestPath)) {
      $this->info('Removing modules.json...');
      unlink($manifestPath);
    }

    $this->success('Registry cleaned successfully!');
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
