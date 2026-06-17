<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Storage;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;

#[
    Cli(
        command: "storage:link",
        description: 'Create a symbolic link from "public/storage" to "storage/app"',
        usage: "storage:link",
        examples: ["storage:link"],
    ),
]
final class StorageLinkCommand extends Command
{
    private const string TARGET_PATH = BASE_PATH . "/storage/app";
    private const string LINK_PATH = BASE_PATH . "/public/storage";

    public function execute(array $args): int
    {
        if (file_exists(self::LINK_PATH)) {
            $this->info("The [public/storage] link already exists");
            return 0;
        }

        if (!is_dir(self::TARGET_PATH)) {
            mkdir(self::TARGET_PATH, 0755, true);
            $this->info("Created target directory: [storage/app]");
        }

        if (symlink(self::TARGET_PATH, self::LINK_PATH)) {
            $this->info("The [public/storage] link has been created");
            return 0;
        }

        $this->error("Failed to create the [public/storage] link");
        return 1;
    }
}
