<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Storage;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;

#[
    Cli(
        command: "storage:unlink",
        description: 'Remove the symbolic link from "public/storage"',
        usage: "storage:unlink",
        examples: ["storage:unlink"],
    ),
]
final class StorageUnlinkCommand extends Command
{
    private const string LINK_PATH = BASE_PATH . "/public/storage";

    public function execute(array $args): int
    {
        if (!file_exists(self::LINK_PATH)) {
            $this->info("The [public/storage] link does not exist.");
            return 0;
        }

        if (!is_link(self::LINK_PATH)) {
            $this->error("The [public/storage] path is not a symbolic link.");
            return 1;
        }

        if (unlink(self::LINK_PATH)) {
            $this->info("The [public/storage] link has been removed.");
            return 0;
        }

        $this->error("Failed to remove the [public/storage] link.");
        return 1;
    }
}
