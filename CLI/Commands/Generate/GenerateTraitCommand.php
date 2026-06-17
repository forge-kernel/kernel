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
    command: 'generate:trait',
    description: 'Create a new trait',
    usage: 'generate:trait [--type=app|module] [--module=ModuleName] [--name=Example]',
    examples: [
        'generate:trait --type=app --name=HasMeta',
        'generate:trait --type=app --name=api/HasMeta',
        'generate:trait --type=module --module=Blog --name=HasComments',
        'generate:trait   (starts wizard)',
    ]
)]
final class GenerateTraitCommand extends Command
{
    use StringHelper;
    use CliGenerator;

    #[Arg(name: 'type', description: 'app or module', validate: 'app|module')]
    private string $type;

    #[Arg(name: 'module', description: 'Module name when type=module', required: false)]
    private ?string $module = null;

    #[Arg(name: 'name', description: 'Trait name (e.g., HasMeta, api/HasMeta, admin/HasPermissions)', validate: '/^[\w\/\s-]+$/')]
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

        $dtoFile = $this->traitPath();

        $parsed = $this->parseFolderFilenameForClass($this->name);
        $className = $this->toPascalCase($parsed['filename']) . 'Trait';

        $tokens = [
            '{{ traitName }}' => $className,
            '{{ traitNameSpace }}' => $this->traitNamespace(),
        ];

        $this->generateFromStub('trait', $dtoFile, $tokens);

        $this->showPostGenerationInfo('trait', [
            'type' => $this->type,
            'module' => $this->module,
            'name' => $this->name,
        ]);

        return 0;
    }
}
