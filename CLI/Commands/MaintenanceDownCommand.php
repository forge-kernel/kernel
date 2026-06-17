<?php

declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\ManagesMaintenanceMode;

#[Cli(
    command: 'down',
    description: 'Put the application into maintenance mode',
    usage: 'down',
    examples: ['down']
)]
final class MaintenanceDownCommand extends Command
{
    use ManagesMaintenanceMode;

    public function execute(array $args): int
    {
        return $this->enableMaintenance();
    }
}