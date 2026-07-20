<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\CLI\Application;
use Forge\CLI\Commands\HelpCommand;
use Forge\Core\DI\Container;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;
use Forge\Core\Bootstrap\ModuleSetup;
use Forge\Core\Bootstrap\ServiceDiscoverSetup;
use Forge\Core\Bootstrap\SessionSetup;
use Forge\Core\Bootstrap\AppCommandSetup;
use ReflectionException;

final class ContainerCLISetup
{
    use LoadsIncludes;

    private static bool $cliContainerSetup = false;

    /**
     * @throws ReflectionException
     * @throws MissingServiceException
     * @throws ResolveParameterException
     */
    public static function setup(): Container
    {
        if (self::$cliContainerSetup) {
            return Container::getInstance();
        }

        $container = Container::getInstance();
        self::loadIncludes();
        SessionSetup::setup($container);
        ErrorHandlerSetup::setup($container);

        $container->singleton(Application::class, function () use ($container) {
            return Application::getInstance($container);
        });

        ModuleSetup::loadModules($container);
        ModuleSetup::preloadModules($container);

        $container->singleton(HelpCommand::class, function () use ($container) {
            $templateGenerator = $container->make(\Forge\Core\Services\TemplateGenerator::class);
            return new HelpCommand($templateGenerator, $container);
        });

        ServiceDiscoverSetup::setup($container);
        AppCommandSetup::getInstance($container)->init();

        self::$cliContainerSetup = true;

        return $container;
    }
}
