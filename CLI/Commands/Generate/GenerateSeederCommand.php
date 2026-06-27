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
    command: 'generate:seeder',
    description: 'Create a new seeder file',
    usage: 'generate:seeder [--type=app|module] [--module=ModuleName] [--name=CreateAdminUser]',
    examples: [
        'generate:seeder --type=app --name=CreateAdminUser',
        'generate:seeder --type=app --name=api/CreateUsers',
        'generate:seeder --type=module --module=Blog --name=CreatePosts',
        'generate:seeder   (starts wizard)',
    ]
)]
final class GenerateSeederCommand extends Command
{
    use StringHelper;
    use CliGenerator;

    #[Arg(name: 'type', description: 'app or module', validate: 'app|module')]
    private string $type;

    #[Arg(name: 'module', description: 'Module name when type=module', required: false)]
    private ?string $module = null;

    #[Arg(name: 'name', description: 'Seeder name (e.g., CreateAdminUser, api/CreateUsers)', validate: '/^[\w\/\s-]+$/')]
    private string $name;

    #[Arg(name: 'table name', description: 'Seeder table name (table_name)', validate: '/^\w+$/')]
    private string $table;

    #[Arg(
        name: 'path',
        description: 'Optional subfolder inside Seeder (e.g., Admin, Api/V1)',
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

        if (!$this->type) $this->type = 'app';

        if ($this->type === 'module' && !$this->module) {
            $this->error('--module=Name required when --type=module');
            return 1;
        }

        $migrationFile = $this->seederPath();

        $parsed = $this->parseFolderFilenameForClass($this->name);
        $className = $this->toPascalCase($parsed['filename']) . 'Seeder';

        $tokens = [
            '{{ seederName }}' => $className,
            '{{ tableName }}' => $this->toSnakeCase($this->table),
            '{{ namespace }}' => $this->resolve('seeder')['namespace'],
        ];

        $this->generateFromStub('seeder', $migrationFile, $tokens);

        $this->showPostGenerationInfo('seeder', [
            'type' => $this->type,
            'module' => $this->module,
            'name' => $this->name,
        ]);

        return 0;
    }
}
