<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Assets;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\ManagesAssetLinks;
use Forge\CLI\Traits\Wizard;

#[Cli(
    command: 'asset:link',
    description: 'Create symbolic links for assets into public/assets.',
    usage: 'asset:link --type=app|module [--module=ModuleName]',
    examples: [
        'asset:link --type=app',
        'asset:link --type=module --module=Blog',
        'asset:link   (starts wizard)'
    ]
)]
final class AssetLinkCommand extends Command
{
    use Wizard;
    use ManagesAssetLinks;

    #[Arg(
        name: 'type',
        description: 'Type of asset link (app or module)',
        required: true, validate: 'app|module'
    )]
    private string $type = '';

    #[Arg(
        name: 'module',
        description: 'Module name when type=module (e.g., Blog)',
        required: false
    )]
    private ?string $module = null;

    public function execute(array $args): int
    {
        $this->wizard($args);

        if ($this->type === 'module' && !$this->module) {
            $this->error('--module=Name required when --type=module');
            return 1;
        }

        $paths = $this->buildPaths($this->type, $this->module);

        return $this->createLink($paths['target'], $paths['link']);
    }
}