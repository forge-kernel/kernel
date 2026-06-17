<?php

declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\ManagesMaintenanceMode;

#[Cli(
    command: 'up',
    description: 'Disable maintenance mode',
    usage: 'up',
    examples: ['up']
)]
final class MaintenanceUpCommand extends Command
{
    use ManagesMaintenanceMode;

    public function execute(array $args): int
    {
        return $this->disableMaintenance();
    }
}