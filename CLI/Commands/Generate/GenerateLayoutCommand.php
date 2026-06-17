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
  command: 'generate:layout',
  description: 'Create a new layout file',
  usage: 'generate:layout [--type=app|module] [--module=ModuleName] [--name=LayoutName]',
  examples: [
    'generate:layout --type=app --name=main',
    'generate:layout --type=app --name=ui/main',
    'generate:layout --type=module --module=Blog --name=admin',
    'generate:layout   (starts wizard)',
  ]
)]
final class GenerateLayoutCommand extends Command
{
  use StringHelper;
  use CliGenerator;

  #[Arg(name: 'type', description: 'app or module', validate: 'app|module')]
  private string $type;

  #[Arg(name: 'module', description: 'Module name when type=module', required: false)]
  private ?string $module = null;

  #[Arg(name: 'name', description: 'Layout name (e.g., main, ui/main, admin/dashboard)', validate: '/^[\w\/\s-]+$/')]
  private string $name;

  #[Arg(
    name: 'path',
    description: 'Optional subfolder inside layouts (e.g., Admin, Api/V1)',
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

    $layoutFile = $this->layoutPath();

    $tokens = [];

    $this->generateFromStub('layout', $layoutFile, $tokens);

    $this->showPostGenerationInfo('layout', [
      'type' => $this->type,
      'module' => $this->module,
      'name' => $this->name,
    ]);

    return 0;
  }
}
