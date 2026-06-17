<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Assets;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Attributes\Arg;
use Forge\CLI\Command;
use Forge\CLI\Traits\ManagesAssetLinks;
use Forge\CLI\Traits\Wizard;

#[
    Cli(
        command: "asset:unlink",
        description: "Remove the symbolic link from public/assets.",
        usage: "asset:unlink --type=app|module [--module=ModuleName]",
        examples: [
            "asset:unlink --type=app",
            "asset:unlink --type=module --module=Blog",
            "asset:unlink (starts wizard)",
        ],
    ),
]
final class AssetUnlinkCommand extends Command
{
    use ManagesAssetLinks;
    use Wizard;

    #[
        Arg(
            name: "type",
            description: "Type of asset link (app or module)",
            required: true,
            validate: "app|module",
        ),
    ]
    private string $type;

    #[
        Arg(
            name: "module",
            description: "Module name when type=module",
            required: false,
        ),
    ]
    private ?string $module = null;

    public function execute(array $args): int
    {
        $this->wizard($args);

        if ($this->type === "module" && !$this->module) {
            $this->error("--module=Name required when --type=module");
            return 1;
        }

        $paths = $this->buildPaths($this->type, $this->module);

        return $this->unlinkDirectory($paths["link"]);
    }
}
