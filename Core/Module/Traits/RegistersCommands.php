<?php

declare(strict_types=1);

namespace Forge\Core\Module\Traits;

use Forge\CLI\Application;
use Forge\Core\DI\Container;

trait RegistersCommands
{
    abstract protected function commands(): array;

    public function registerCommands(): void
    {
        $container = Container::getInstance();

        if (!$container->has(Application::class)) {
            return;
        }

        $app = $container->get(Application::class);

        foreach ($this->commands() as $commandClass) {
            $app->registerCommandClass($commandClass, 'modules:');
        }
    }
}
