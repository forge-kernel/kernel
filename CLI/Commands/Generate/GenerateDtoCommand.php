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
    command: 'generate:dto',
    description: 'Create a new dto',
    usage: 'generate:dto [--type=app|module] [--module=ModuleName] [--name=Example]',
    examples: [
        'generate:dto --type=app --name=User',
        'generate:dto --type=app --name=api/User',
        'generate:dto --type=module --module=Blog --name=Post',
        'generate:dto   (starts wizard)',
    ]
)]
final class GenerateDtoCommand extends Command
{
    use StringHelper;
    use CliGenerator;

    #[Arg(name: 'type', description: 'app or module', validate: 'app|module')]
    private string $type;

    #[Arg(name: 'module', description: 'Module name when type=module', required: false)]
    private ?string $module = null;

    #[Arg(name: 'name', description: 'DTO name (e.g., User, api/User, admin/Dashboard)', validate: '/^[\w\/\s-]+$/')]
    private string $name;


    #[Arg(
        name: 'path',
        description: 'Optional subfolder inside Models (e.g., Admin, Api/V1)',
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

        $dtoFile = $this->dtoPath();

        $parsed = $this->parseFolderFilenameForClass($this->name);
        $className = $this->toPascalCase($parsed['filename']) . 'DTO';

        $tokens = [
            '{{ dtoName }}' => $className,
            '{{ dtoNameSpace }}' => $this->dtoNamespace(),
        ];

        $this->generateFromStub('dto', $dtoFile, $tokens);

        $this->showPostGenerationInfo('dto', [
            'type' => $this->type,
            'module' => $this->module,
            'name' => $this->name,
        ]);

        return 0;
    }
}
