<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\CLI\Application;
use Forge\Core\DI\Container;
use Forge\Core\Structure\StructureResolver;

final class AppCommandSetup
{
    use LoadsCommands;

    private static ?self $instance = null;

    private function __construct(private readonly Container $container)
    {
    }

    public static function getInstance(Container $container): self
    {
        if (self::$instance === null) {
            self::$instance = new self($container);
        }
        return self::$instance;
    }

    public function init(): void
    {
        self::loadCommands();
    }
}
