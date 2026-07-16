<?php

declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Command;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Traits\OutputHelper;
use Forge\Core\DI\Container;
use Forge\Core\Services\InteractiveSelect;
use Forge\Core\Structure\StructureResolver;

#[Cli(
  command: 'structure:info',
  description: 'Display current folder structure configuration interactively',
  usage: 'structure:info',
  examples: [
    'structure:info'
  ]
)]
final class StructureInfoCommand extends Command
{
  use OutputHelper;

  public function execute(array $args): int
  {
    $container = Container::getInstance();

    if (!$container->has(StructureResolver::class)) {
      $this->error("StructureResolver not available");
      return 1;
    }

    $structureResolver = $container->get(StructureResolver::class);
    $interactiveSelect = $container->has(InteractiveSelect::class)
      ? $container->get(InteractiveSelect::class)
      : null;

    $userStructureFile = null;
    if (file_exists(BASE_PATH . '/forge_structure.php')) {
      $userStructureFile = BASE_PATH . '/forge_structure.php';
    } elseif (file_exists(BASE_PATH . '/structure.php')) {
      $userStructureFile = BASE_PATH . '/structure.php';
    }

    $modules = [];
    foreach (StructureResolver::resolveModulesRoots() as $root) {
      $modulesPath = BASE_PATH . '/' . $root;
      if (!is_dir($modulesPath)) {
        continue;
      }
      foreach (scandir($modulesPath) as $item) {
        if (is_dir("$modulesPath/$item") && !in_array($item, ['.', '..'])) {
          $modules[$item] = $modulesPath . '/' . $item;
        }
      }
    }

    $options = ['View App Structure'];
    if (!empty($modules)) {
      $options[] = 'View Modules Structure';
      if (count($modules) > 1) {
        $options[] = 'View Specific Module';
      }
    }
    if ($userStructureFile) {
      $options[] = 'View User-Defined Structure';
    }
    $options[] = 'Exit';

    if ($interactiveSelect && count($options) > 2) {
      $selectedIndex = $interactiveSelect->select(
        $options,
        "What would you like to view?"
      );

      if ($selectedIndex === null || $selectedIndex === count($options) - 1) {
        return 0;
      }

      $this->line("");

      switch ($selectedIndex) {
        case 0:
          $this->displayAppStructure($structureResolver, $userStructureFile);
          break;

        case 1:
          if (!empty($modules)) {
            if (count($modules) === 1) {
              $moduleName = array_values($modules)[0];
              $this->displayModuleStructure($moduleName, $structureResolver);
            } else {
              $this->displayAllModules($modules, $structureResolver);
            }
          }
          break;

        case 2:
          if (count($modules) > 1) {
            $moduleOptions = array_values($modules);
            $moduleOptions[] = 'Back';

            $moduleIndex = $interactiveSelect->select(
              $moduleOptions,
              "Select a module to view"
            );

            if ($moduleIndex !== null && $moduleIndex < count($moduleOptions) - 1) {
              $this->line("");
              $this->displayModuleStructure($moduleOptions[$moduleIndex], $structureResolver);
            }
          } elseif (isset($options[2]) && $options[2] === 'View User-Defined Structure') {
            $this->displayUserStructure($userStructureFile);
          }
          break;

        default:
          if (isset($options[$selectedIndex]) && $options[$selectedIndex] === 'View User-Defined Structure') {
            $this->displayUserStructure($userStructureFile);
          }
          break;
      }
    } else {
      $this->info("Forge Folder Structure Configuration");
      $this->line("");

      if ($userStructureFile) {
        $this->success("User structure file found: " . basename($userStructureFile));
      } else {
        $this->line("No user structure file found - using internal defaults");
      }
      $this->line("");

      $this->displayAppStructure($structureResolver, $userStructureFile);

      if (!empty($modules)) {
        if (count($modules) === 1) {
          $moduleName = array_values($modules)[0];
          $this->displayModuleStructure($moduleName, $structureResolver);
        } else {
          $this->displayAllModules($modules, $structureResolver);
        }
      }
    }

    return 0;
  }

  private function displayAppStructure(StructureResolver $structureResolver, ?string $userStructureFile): void
  {
    $this->info("App Structure:");
    if ($userStructureFile) {
      $this->comment("  (User-defined overrides applied)");
    }
    $appStructure = $structureResolver->getFullAppStructure();
    $headers = ['Type', 'Path'];
    $rows = [];
    foreach ($appStructure as $type => $path) {
      $rows[] = ['Type' => $type, 'Path' => $path];
    }
    $this->table($headers, $rows);
    $this->line("");
  }

  private function displayUserStructure(string $userStructureFile): void
  {
    $this->info("User-Defined Structure File:");
    $this->line("  File: " . basename($userStructureFile));
    $this->line("");

    $userStructure = require $userStructureFile;
    if (isset($userStructure['app'])) {
      $this->info("App Structure (from user file):");
      $headers = ['Type', 'Path'];
      $rows = [];
      foreach ($userStructure['app'] as $type => $path) {
        $rows[] = ['Type' => $type, 'Path' => $path];
      }
      $this->table($headers, $rows);
      $this->line("");
    }

    if (isset($userStructure['modules'])) {
      $this->info("Modules Structure (from user file):");
      $headers = ['Type', 'Path'];
      $rows = [];
      foreach ($userStructure['modules'] as $type => $path) {
        $rows[] = ['Type' => $type, 'Path' => $path];
      }
      $this->table($headers, $rows);
      $this->line("");
    }
  }

  private function displayModuleStructure(string $moduleName, StructureResolver $structureResolver): void
  {
    try {
      $moduleStructure = $structureResolver->getFullModuleStructure($moduleName);
      $this->info("Module: {$moduleName}");
      $moduleRows = [];
      foreach ($moduleStructure as $type => $path) {
        $moduleRows[] = ['Type' => $type, 'Path' => $path];
      }
      $this->table(['Type', 'Path'], $moduleRows);
      $this->line("");
    } catch (\Exception $e) {
      $this->error("Module: {$moduleName} - Error loading structure: " . $e->getMessage());
    }
  }

  private function displayAllModules(array $modules, StructureResolver $structureResolver): void
  {
    $this->info("All Module Structures:");
    $this->line("");
    foreach ($modules as $moduleName) {
      $this->displayModuleStructure($moduleName, $structureResolver);
    }
  }
}
