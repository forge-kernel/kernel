<?php

declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Command;
use Forge\CLI\Attributes\Cli;
use Forge\Core\Debug\Metrics;

#[Cli(
    command: 'stats',
    description: 'Displays framework performance metrics.',
    usage: 'stats',
    examples: [
        'stats'
    ]
)]
final class StatsCommand extends Command
{
    public function execute(array $args): int
    {
        $headers = ['Metric', 'Time (sec)', 'Memory (KB)'];
        $rows = [];

        foreach (Metrics::get() as $key => $data) {
            $rows[] = [
                'Metric' => $key,
                'Time (sec)' => number_format($data['duration'] ?? 0, 5),
                'Memory (KB)' => number_format(($data['memory_used'] ?? 0) / 1024, 2),
            ];
        }

        $this->info("Forge Framework Performance Metrics");
        $this->table($headers, $rows);

        return 0;
    }
}