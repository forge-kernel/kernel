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
    command: 'generate:test',
    description: 'Create a new test',
    usage: 'generate:test [--type=app|module] [--module=ModuleName] [--group=example]',
    examples: [
        'generate:test --type=app --name=User --group=example',
        'generate:test --type=app --name=api/User --group=example',
        'generate:test --type=module --module=Blog --name=Post --group=example',
        'generate:test   (starts wizard)',
    ]
)]
final class GenerateTestCommand extends Command
{
    use StringHelper;
    use CliGenerator;

    #[Arg(name: 'type', description: 'app or module', validate: 'app|module')]
    private string $type;

    #[Arg(name: 'module', description: 'Module name when type=module', required: false)]
    private ?string $module = null;

    #[Arg(name: 'name', description: 'Test name (e.g., UserTest, api/UserTest, admin/DashboardTest)', validate: '/^[\w\/\s-]+$/')]
    private string $name;

    #[Arg(name: 'group', description: 'Test group (e.g. db)')]
    private string $group;

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

        $dtoFile = $this->testPath();

        $parsed = $this->parseFolderFilenameForClass($this->name);
        $className = $this->toPascalCase($parsed['filename']) . 'Test';

        $tokens = [
            '{{ testName }}' => $className,
            '{{ testGroup }}' => $this->group,
            '{{ testNameSpace }}' => $this->testNamespace(),
        ];

        $this->generateFromStub('test', $dtoFile, $tokens);

        $this->showPostGenerationInfo('test', [
            'type' => $this->type,
            'module' => $this->module,
            'name' => $this->name,
        ]);

        return 0;
    }
}
