<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\CLI\Application;
use Forge\Core\DI\Container;
use Forge\Core\Structure\StructureResolver;

trait LoadsCommands
{
    private static function loadCommands(): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        $container = Container::getInstance();

        if (!$container->has(Application::class)) {
            return;
        }

        $app = $container->get(Application::class);
        $resolver = new StructureResolver();
        $commands = $resolver->getAppConfig('commands');

        if (!is_array($commands)) {
            return;
        }

        foreach ($commands as $commandClass) {
            $app->registerCommandClass($commandClass, '');
        }
    }
}
