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
    command: 'generate:service',
    description: 'Create a new service',
    usage: 'generate:service [--type=app|module] [--module=ModuleName] [--name=Example]',
    examples: [
        'generate:service --type=app --name=User',
        'generate:service --type=app --name=api/User',
        'generate:service --type=module --module=Blog --name=Post',
        'generate:service   (starts wizard)',
    ]
)]
final class GenerateServiceCommand extends Command
{
    use StringHelper;
    use CliGenerator;

    #[Arg(name: 'type', description: 'app or module', validate: 'app|module')]
    private string $type;

    #[Arg(name: 'module', description: 'Module name when type=module', required: false)]
    private ?string $module = null;

    #[Arg(name: 'name', description: 'Service name (e.g., User, api/User, admin/Dashboard)', validate: '/^[\w\/\s-]+$/')]
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

        $dtoFile = $this->servicePath();

        $parsed = $this->parseFolderFilenameForClass($this->name);
        $className = $this->toPascalCase($parsed['filename']) . 'Service';

        $tokens = [
            '{{ serviceName }}' => $className,
            '{{ serviceNameSpace }}' => $this->serviceNamespace(),
        ];

        $this->generateFromStub('service', $dtoFile, $tokens);

        $this->showPostGenerationInfo('service', [
            'type' => $this->type,
            'module' => $this->module,
            'name' => $this->name,
        ]);

        return 0;
    }
}
