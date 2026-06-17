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
    command: 'generate:model',
    description: 'Create a new model',
    usage: 'generate:model [--type=app|module] [--module=ModuleName] [--name=Example]',
    examples: [
        'generate:model --type=app --name=User',
        'generate:model --type=app --name=api/User',
        'generate:model --type=module --module=Blog --name=Post',
        'generate:model   (starts wizard)',
    ]
)]
final class GenerateModelCommand extends Command
{
    use StringHelper;
    use CliGenerator;

    #[Arg(name: 'type', description: 'app or module', validate: 'app|module')]
    private string $type;

    #[Arg(name: 'module', description: 'Module name when type=module', required: false)]
    private ?string $module = null;

    #[Arg(name: 'name', description: 'Model name (e.g., User, api/User, admin/Dashboard)', validate: '/^[\w\/\s-]+$/')]
    private string $name;

    #[Arg(name: 'table name', description: 'Model table name', validate: '')]
    private string $table;

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

        $eventFile = $this->modelPath();

        $parsed = $this->parseFolderFilenameForClass($this->name);
        $className = $this->toPascalCase($parsed['filename']);

        $tokens = [
            '{{ modelName }}' => $className,
            '{{ modelNameSpace }}' => $this->modelNamespace(),
            '{{ modelTableName }}' => $this->table,
        ];

        $this->generateFromStub('model', $eventFile, $tokens);

        $this->showPostGenerationInfo('model', [
            'type' => $this->type,
            'module' => $this->module,
            'name' => $this->name,
        ]);

        return 0;
    }
}
