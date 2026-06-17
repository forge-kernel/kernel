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
    command: 'generate:event',
    description: 'Create a new event',
    usage: 'generate:event [--type=app|module] [--module=ModuleName] [--name=Example]',
    examples: [
        'generate:event --type=app --name=UserCreated',
        'generate:event --type=app --name=api/UserCreated',
        'generate:event --type=module --module=Blog --name=PostCreated',
        'generate:event   (starts wizard)',
    ]
)]
final class GenerateEventCommand extends Command
{
    use StringHelper;
    use CliGenerator;

    #[Arg(name: 'type', description: 'app or module', validate: 'app|module')]
    private string $type;

    #[Arg(name: 'module', description: 'Module name when type=module', required: false)]
    private ?string $module = null;

    #[Arg(name: 'name', description: 'Event name (e.g., UserCreated, api/UserCreated)', validate: '/^[\w\/\s-]+$/')]
    private string $name;

    #[Arg(name: 'queue', description: 'Queue name', validate: '')]
    private string $queueName;

    #[Arg(
        name: 'path',
        description: 'Optional subfolder inside Middleware (e.g., Admin, Api/V1)',
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

        $eventFile = $this->eventPath();

        $parsed = $this->parseFolderFilenameForClass($this->name);
        $className = $this->toPascalCase($parsed['filename']) . 'Event';

        $tokens = [
            '{{ eventName }}'      => $className,
            '{{ eventNamespace }}' => $this->eventNamespace(),
            '{{ eventQueueName }}' => $this->queueName,
        ];

        $this->generateFromStub('event', $eventFile, $tokens);

        $this->showPostGenerationInfo('event', [
            'type' => $this->type,
            'module' => $this->module,
            'name' => $this->name,
        ]);

        return 0;
    }
}
