<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\TemplateGenerator;
use Forge\Traits\StringHelper;

#[Cli(
  command: 'registry:cleanup',
  description: 'Orchestrator for registry cleanup operations',
  usage: 'dev:registry:cleanup [--operation=clean|readme|remove|sync|all] [--name=ModuleName]',
  examples: [
    'dev:registry:cleanup',
    'dev:registry:cleanup --operation=clean',
    'dev:registry:cleanup --operation=remove --name=ForgeLogger',
  ]
)]
final class RegistryCleanupCommand extends Command
{
  use CliGenerator;
  use StringHelper;

  #[Arg(name: 'operation', description: 'Operation to perform (clean|readme|remove|sync|all)', required: false)]
  private ?string $operation = null;

  #[Arg(name: 'name', description: 'Module name (required for remove operation)', required: false)]
  private ?string $name = null;

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

    if (!$this->operation) {
      $this->operation = $this->selectOperation();
      if (!$this->operation) {
        return 0;
      }
    }

    $this->line('');

    return match ($this->operation) {
      'clean' => $this->runClean(),
      'readme' => $this->runReadmeUpdate(),
      'remove' => $this->runRemove(),
      'sync' => $this->runSync(),
      'all' => $this->runAll(),
      default => $this->handleInvalidOperation(),
    };
  }

  private function selectOperation(): ?string
  {
    $operations = [
      'clean' => 'Clean registry (remove modules/ and modules.json)',
      'readme' => 'Update README (clean up README format)',
      'remove' => 'Remove specific module',
      'sync' => 'Sync all module versions',
      'all' => 'Run all cleanup operations (in sequence)',
    ];

    $selected = $this->templateGenerator->selectFromList(
      'Select cleanup operation:',
      array_values($operations),
      'clean'
    );

    if ($selected === null) {
      return null;
    }

    return array_search($selected, $operations, true) ?: null;
  }

  private function runClean(): int
  {
    $this->info('Running registry clean operation...');
    $command = new RegistryCleanCommand($this->registryService, $this->templateGenerator);
    return $command->execute([]);
  }

  private function runReadmeUpdate(): int
  {
    $this->info('Running README update operation...');
    $container = \Forge\Core\DI\Container::getInstance();
    $readmeService = $container->make(\Forge\Core\Services\RegistryReadmeService::class);
    $manifestService = $container->make(\Forge\Core\Services\ManifestService::class);
    $command = new RegistryReadmeUpdateCommand($this->registryService, $readmeService, $manifestService);
    return $command->execute([]);
  }

  private function runRemove(): int
  {
    if (!$this->name) {
      $this->name = $this->templateGenerator->askQuestion(
        'Module name (PascalCase): ',
        ''
      );
    }

    if (!$this->name) {
      $this->error('Module name is required for remove operation.');
      return 1;
    }

    $this->info("Running module remove operation for {$this->name}...");
    $container = \Forge\Core\DI\Container::getInstance();
    $manifestService = $container->make(\Forge\Core\Services\ManifestService::class);
    $readmeService = $container->make(\Forge\Core\Services\RegistryReadmeService::class);
    $gitService = $container->make(\Forge\Core\Services\GitService::class);
    $command = new RegistryModuleRemoveCommand(
      $this->registryService,
      $manifestService,
      $readmeService,
      $gitService,
      $this->templateGenerator
    );
    return $command->execute(['--name=' . $this->name]);
  }

  private function runSync(): int
  {
    $this->info('Running version sync operation...');
    $container = \Forge\Core\DI\Container::getInstance();
    $manifestService = $container->make(\Forge\Core\Services\ManifestService::class);
    $metadataService = $container->make(\Forge\Core\Services\ModuleMetadataService::class);
    $readmeService = $container->make(\Forge\Core\Services\RegistryReadmeService::class);
    $gitService = $container->make(\Forge\Core\Services\GitService::class);
    $command = new RegistrySyncVersionsCommand(
      $this->registryService,
      $manifestService,
      $metadataService,
      $readmeService,
      $gitService
    );
    return $command->execute([]);
  }

  private function runAll(): int
  {
    $this->info('Running all cleanup operations in sequence...');
    $this->line('');

    $operations = ['clean', 'readme', 'sync'];
    $results = [];

    foreach ($operations as $op) {
      $this->line('');
      $this->info("=== Running operation: {$op} ===");
      $this->line('');

      $result = match ($op) {
        'clean' => $this->runClean(),
        'readme' => $this->runReadmeUpdate(),
        'sync' => $this->runSync(),
        default => 1,
      };

      $results[] = $result;

      if ($result !== 0) {
        $this->warning("Operation '{$op}' completed with errors.");
      } else {
        $this->success("Operation '{$op}' completed successfully.");
      }
    }

    $failed = array_filter($results, fn($r) => $r !== 0);
    if (!empty($failed)) {
      $this->error('Some operations completed with errors.');
      return 1;
    }

    $this->success('All cleanup operations completed successfully!');
    return 0;
  }

  private function handleInvalidOperation(): int
  {
    $this->error("Invalid operation: {$this->operation}");
    $this->info('Valid operations: clean, readme, remove, sync, all');
    return 1;
  }
}
