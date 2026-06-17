<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Generate;

use Exception;
use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Traits\StringHelper;
use Forge\Core\Services\TemplateGenerator;

#[Cli(
    command: 'generate:command',
    description: 'Create a new command with basic structure',
    usage: 'generate:command [--name=ModuleName] [--description="Command description"]',
    examples: [
        'generate:command --name=Blog',
        'generate:command --name=api/User',
        'generate:command --name=admin/Dashboard',
        'generate:command   (starts wizard)',
    ]
)]
final class GenerateCommandCommand extends Command
{
    use StringHelper;
    use CliGenerator;

    #[Arg(name: 'name', description: 'Command name (e.g., Blog, api/User, admin/Dashboard)', validate: '/^[\w\/\s-]+$/')]
    private string $name;

    #[Arg(name: 'description', description: 'Command description', required: false)]
    private ?string $description = null;

    #[Arg(name: 'type', description: 'app|module', required: false)]
    private ?string $type = 'app';

    #[Arg(
        name: 'path',
        description: 'Optional subfolder inside Commands (e.g., Admin, Api/V1)',
        default: '',
        required: false
    )]
    private string $path = '';

    public function __construct(private readonly TemplateGenerator $templateGenerator)
    {
    }

    /**
     * @throws Exception
     */
    public function execute(array $args): int
    {
        $this->wizard($args);

        $this->type = 'app';
        $this->name = $this->toPascalCase($this->name);
        if (!$this->isPascalCase($this->name)) {
            $this->error("Invalid module name. Use PascalCase (e.g., MyTest).");
            return 1;
        }

        if (!$this->description) {
            $this->description = $this->templateGenerator->askQuestion(
                "Command description: ",
                "A brief description of the command."
            );
        }


        $eventFile = $this->commandPath();

        $tokens = [
            '{{ command }}' => $this->toSnakeCase($this->name),
            '{{ description }}' => $this->description,
            '{{ commandName }}' => $this->name,
        ];

        $this->generateFromStub('command', $eventFile, $tokens);
        return 0;
    }
}
