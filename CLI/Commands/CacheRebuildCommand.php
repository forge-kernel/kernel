<?php

declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\OutputHelper;

#[Cli(
    command: 'cache:rebuild',
    description: 'Signal the next web request to rebuild all caches. Use on shared hosting where CLI cache:warm is unavailable.',
    usage: 'cache:rebuild',
    examples: [
        'cache:rebuild',
        'cache:rebuild --now',
    ]
)]
final class CacheRebuildCommand extends Command
{
    use OutputHelper;

    private const string SENTINEL_DIR = '/storage/framework';
    private const string SENTINEL_FILE = '/storage/framework/.cache_rebuild';

    public function execute(array $args): int
    {
        $sentinel = BASE_PATH . self::SENTINEL_FILE;

        if (file_put_contents($sentinel, (string) time()) === false) {
            $this->error('Failed to create cache rebuild sentinel. Check permissions on ' . dirname($sentinel));
            return 1;
        }

        $this->success('Cache rebuild signal created successfully.');

        if (in_array('--now', $args, true)) {
            $this->info('Triggering immediate cache rebuild...');

            \Forge\Core\Cache\CacheRebuildTrigger::process();

            $this->info('Cache rebuild triggered. Caches will be rebuilt on the next request.');
        } else {
            $this->info('The next web request will rebuild all framework caches.');
            $this->line('Upload the file "storage/framework/.cache_rebuild" via FTP to trigger remotely,');
            $this->line('or run "php forge.php cache:rebuild --now" for immediate processing.');
        }

        return 0;
    }
}
