<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Generate;

use Exception;
use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Traits\StringHelper;

#[Cli(
  command: 'generate:component',
  description: 'Create a new view-only component',
  usage: 'generate:component [--type=app|module] [--module=ModuleName] [--name=ComponentName]',
  examples: [
    'generate:component --type=app --name=alert',
    'generate:component --type=app --name=forms/field',
    'generate:component --type=module --module=Blog --name=post-card',
    'generate:component   (starts wizard)',
  ]
)]
final class GenerateComponentCommand extends Command
{
  use StringHelper;
  use CliGenerator;

  #[Arg(name: 'type', description: 'app or module', validate: 'app|module')]
  private string $type;

  #[Arg(name: 'module', description: 'Module name when type=module', required: false)]
  private ?string $module = null;

  #[Arg(name: 'name', description: 'Component name (e.g., alert, forms/field, ui/button)', validate: '/^[\w\/\s-]+$/')]
  private string $name;

  #[Arg(
    name: 'path',
    description: 'Optional subfolder inside components (e.g., Admin, Api/V1)',
    default: '',
    required: false
  )]
  private string $path = '';

  /**
   * @throws Exception
   */
  public function execute(array $args): int
  {
    $this->wizard($args);

    if ($this->type === 'module' && !$this->module) {
      $this->error('--module=Name required when --type=module');
      return 1;
    }

    $componentFile = $this->componentViewPath();

    $tokens = [];

    $this->generateFromStub('component-view-only', $componentFile, $tokens);

    $this->showPostGenerationInfo('component', [
      'type' => $this->type,
      'module' => $this->module,
      'name' => $this->name,
    ]);

    return 0;
  }

  private function componentViewPath(): string
  {
    return $this->resolve('component-view-only')['path'];
  }
}
