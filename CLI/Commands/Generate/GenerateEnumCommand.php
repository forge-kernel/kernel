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
    command: 'generate:enum',
    description: 'Create a new enum',
    usage: 'generate:enum [--type=app|module] [--module=ModuleName] [--name=Example]',
    examples: [
        'generate:enum --type=app --name=Status',
        'generate:enum --type=app --name=api/Status',
        'generate:enum --type=module --module=Blog --name=PostStatus',
        'generate:enum   (starts wizard)',
    ]
)]
final class GenerateEnumCommand extends Command
{
    use StringHelper;
    use CliGenerator;

    #[Arg(name: 'type', description: 'app or module', validate: 'app|module')]
    private string $type;

    #[Arg(name: 'module', description: 'Module name when type=module', required: false)]
    private ?string $module = null;

    #[Arg(name: 'name', description: 'Enum name (e.g., Status, api/Status, admin/Permission)', validate: '/^[\w\/\s-]+$/')]
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

        $dtoFile = $this->enumPath();

        $parsed = $this->parseFolderFilenameForClass($this->name);
        $className = $this->toPascalCase($parsed['filename']) . 'Enum';

        $tokens = [
            '{{ enumName }}' => $className,
            '{{ enumNameSpace }}' => $this->enumNamespace(),
        ];

        $this->generateFromStub('enum', $dtoFile, $tokens);

        $this->showPostGenerationInfo('enum', [
            'type' => $this->type,
            'module' => $this->module,
            'name' => $this->name,
        ]);

        return 0;
    }
}
