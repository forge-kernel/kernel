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
    command: 'generate:migration',
    description: 'Create a new migration file',
    usage: 'generate:migration [--type=app|module] [--module=ModuleName] [--name=CreateTestsTable]',
    examples: [
        'generate:migration --type=app --name=CreateUsers',
        'generate:migration --type=app --name=api/CreateUsers',
        'generate:migration --type=module --module=Blog --name=CreatePosts',
        'generate:migration   (starts wizard)',
    ]
)]
final class GenerateMigrationCommand extends Command
{
    use StringHelper;
    use CliGenerator;

    #[Arg(name: 'type', description: 'app or module', validate: 'app|module')]
    private string $type;

    #[Arg(name: 'module', description: 'Module name when type=module', required: false)]
    private ?string $module = null;

    #[Arg(name: 'name', description: 'Migration name (e.g., CreateUsers, api/CreateUsers)', validate: '/^[\w\/\s-]+$/')]
    private string $name;

    #[Arg(name: 'table name', description: 'Migration table name (table_name)', validate: '/^\w+$/')]
    private string $table;

    #[Arg(
        name: 'path',
        description: 'Optional subfolder inside Migration (e.g., Admin, Api/V1)',
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

        $migrationFile = $this->migrationPath();

        $parsed = $this->parseFolderFilenameForClass($this->name);
        $className = $this->toPascalCase($parsed['filename']) . 'Migration';

        $tokens = [
            '{{ migrationName }}' => $className,
            '{{ migrationTable }}' => $this->toSnakeCase($this->table),
        ];

        $this->generateFromStub('migration', $migrationFile, $tokens);

        $this->showPostGenerationInfo('migration', [
            'type' => $this->type,
            'module' => $this->module,
            'name' => $this->name,
        ]);

        return 0;
    }
}
