<?php

declare(strict_types=1);

namespace Forge\CLI;

use Forge\CLI\Attributes\Cli;
use Forge\CLI\Attributes\Command;
use Forge\CLI\Commands\Assets\AssetLinkCommand;
use Forge\CLI\Commands\Assets\AssetUnlinkCommand;
use Forge\CLI\Commands\FlushCacheCommand;
use Forge\CLI\Commands\WarmCacheCommand;
use Forge\CLI\Commands\Generate\GenerateCommandCommand;
use Forge\CLI\Commands\Generate\GenerateEventCommand;
use Forge\CLI\Commands\Generate\GenerateMigrationCommand;
use Forge\CLI\Commands\Generate\GenerateModelCommand;
use Forge\CLI\Commands\Generate\GenerateModuleCommand;
use Forge\CLI\Commands\Generate\GenerateSeederCommand;
use Forge\CLI\Commands\Generate\GenerateTestCommand;
use Forge\CLI\Commands\HelpCommand;
use Forge\CLI\Commands\KeyGenerateCommand;
use Forge\CLI\Commands\MaintenanceDownCommand;
use Forge\CLI\Commands\MaintenanceUpCommand;
use Forge\CLI\Commands\StatsCommand;
use Forge\CLI\Commands\Storage\StorageLinkCommand;
use Forge\CLI\Commands\Storage\StorageUnlinkCommand;
use Forge\CLI\Commands\Registry\FrameworkListCommand;
use Forge\CLI\Commands\Registry\FrameworkPublishCommand;
use Forge\CLI\Commands\Registry\FrameworkVersionCommand;
use Forge\CLI\Commands\Registry\ModuleListCommand;
use Forge\CLI\Commands\Registry\ModulePublishCommand;
use Forge\CLI\Commands\Registry\ModuleVersionCommand;
use Forge\CLI\Commands\Registry\RegistryCleanCommand;
use Forge\CLI\Commands\Registry\RegistryCleanupCommand;
use Forge\CLI\Commands\Registry\RegistryConfigCommand;
use Forge\CLI\Commands\Registry\RegistryInitCommand;
use Forge\CLI\Commands\Registry\RegistryManageCommand;
use Forge\CLI\Commands\Registry\RegistryModuleRemoveCommand;
use Forge\CLI\Commands\Registry\RegistryReadmeUpdateCommand;
use Forge\CLI\Commands\Registry\RegistrySyncVersionsCommand;
use Forge\CLI\Commands\Registry\BlueprintInitCommand;
use Forge\CLI\Commands\Registry\BlueprintListCommand;
use Forge\CLI\Commands\Registry\BlueprintPublishCommand;
use Forge\CLI\Commands\Registry\BlueprintRemoveCommand;
use Forge\CLI\Commands\Registry\BlueprintScaffoldCommand;
use Forge\CLI\Commands\Registry\BlueprintVersionCommand;
use Forge\CLI\Commands\Dev\DevStructureAddCommand;
use Forge\CLI\Commands\StructureInfoCommand;
use Forge\CLI\Commands\StructureInitCommand;
use Forge\Core\Bootstrap\AppCommandSetup;
use Forge\Core\Config\Environment;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Exceptions\MissingServiceException;
use ReflectionClass;
use ReflectionException;

final class Application
{
    private static int $instanceCount = 0;
    private static ?self $instance = null;

    /** @var array<string, array{0:string,1:string}> */
    private array $commands = [];

    private Container $container;
    private int $instanceId;

    /**
     * @throws ReflectionException
     */
    private function __construct(Container $container)
    {
        $this->instanceId = ++self::$instanceCount;
        $this->container = $container;

        $this->registerCoreCommands();
        $this->registerAppCommandsFromCache();
    }

    /**
     * Register all known core commands explicitly.
     * @throws ReflectionException
     */
    private function registerCoreCommands(): void
    {
        $coreCommands = [
            FlushCacheCommand::class,
            WarmCacheCommand::class,
            KeyGenerateCommand::class,
            GenerateModuleCommand::class,
            StorageLinkCommand::class,
            StorageUnlinkCommand::class,
            MaintenanceUpCommand::class,
            MaintenanceDownCommand::class,
            StatsCommand::class,
            AssetLinkCommand::class,
            AssetUnlinkCommand::class,
            GenerateEventCommand::class,
            GenerateMigrationCommand::class,
            GenerateSeederCommand::class,
            GenerateModelCommand::class,
            GenerateCommandCommand::class,
            GenerateTestCommand::class,
            StructureInfoCommand::class,
            StructureInitCommand::class,
        ];

        foreach ($coreCommands as $commandClass) {
            $this->registerCommandClass($commandClass, "");
        }

        if ($this->isDeveloperModeEnabled()) {
            $this->registerDeveloperCommands();
        }
    }

    /**
     * Register a command with optional prefix
     * @throws ReflectionException
     */
    public function registerCommandClass(
        string $commandClass,
        string $prefix = "module:",
    ): void {
        $reflectionClass = new ReflectionClass($commandClass);
        $commandAttribute =
            $reflectionClass->getAttributes(Command::class)[0] ?? $reflectionClass->getAttributes(Cli::class)[0] ?? null;

        if ($commandAttribute) {
            $commandInstance = $commandAttribute->newInstance();
            $this->container->register($commandClass);

            $commandName = $commandInstance->command;
            if ($prefix && !str_starts_with($commandName, $prefix)) {
                $commandName = $prefix . $commandName;
            }

            $this->commands[$commandName] = [
                $commandClass,
                $commandInstance->description,
            ];
        }
    }

    private function isDeveloperModeEnabled(): bool
    {
        $env = Environment::getInstance();
        $envValue = $env->get("FORGE_DEVELOPER_MODE");

        return $envValue === "true" || $envValue === true || $envValue === "1";
    }

    /**
     * Get singleton instance.
     */
    public static function getInstance(Container $container): self
    {
        if (!self::$instance) {
            self::$instance = new self($container);
        }
        return self::$instance;
    }

    private function registerDeveloperCommands(): void
    {
        $devCommands = [
            ModuleVersionCommand::class,
            ModulePublishCommand::class,
            ModuleListCommand::class,
            FrameworkVersionCommand::class,
            FrameworkPublishCommand::class,
            FrameworkListCommand::class,
            RegistryInitCommand::class,
            RegistryConfigCommand::class,
            RegistryManageCommand::class,
            RegistryCleanupCommand::class,
            RegistryCleanCommand::class,
            RegistryReadmeUpdateCommand::class,
            RegistryModuleRemoveCommand::class,
            RegistrySyncVersionsCommand::class,
            BlueprintInitCommand::class,
            BlueprintListCommand::class,
            BlueprintPublishCommand::class,
            BlueprintRemoveCommand::class,
            BlueprintScaffoldCommand::class,
            BlueprintVersionCommand::class,
            DevStructureAddCommand::class,
        ];

        foreach ($devCommands as $commandClass) {
            $this->registerCommandClass($commandClass, "dev:");
        }
    }

    private function registerAppCommandsFromCache(): void
    {
        $setup = AppCommandSetup::getInstance($this->container);
        $classMap = $setup->getClassMap();

        foreach ($classMap as $className => $filePath) {
            if (!class_exists($className)) {
                include_once $filePath;
            }

            if (!class_exists($className)) {
                continue;
            }

            $this->registerAppCommandClass($className);
        }
    }

    /**
     * Shortcut for registering app commands.
     * @throws ReflectionException
     */
    public function registerAppCommandClass(string $commandClass): void
    {
        $this->registerCommandClass($commandClass, "app:");
    }

    /**
     * Returns all registered commands.
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Return instance id.
     */
    public function getInstanceId(): int
    {
        return $this->instanceId;
    }

    /**
     * Run CLI application with argv.
     * @throws ReflectionException|MissingServiceException
     */
    public function run(array $argv): int
    {
        if (count($argv) < 2) {
            return $this->showInteractiveStartup($argv);
        }

        $commandName = $argv[1];

        if ($commandName === "help") {
            $helpCommand = $this->container->make(HelpCommand::class);
            $helpCommand->execute($this->getSortedCommands());
            return 0;
        }

        foreach ($this->commands as $name => $commandInfo) {
            if ($name === $commandName) {
                if (
                    str_starts_with($commandName, "dev:") &&
                    !$this->isDeveloperModeEnabled()
                ) {
                    echo "Developer mode is required. Set FORGE_DEVELOPER_MODE=true in .env\n";
                    return 1;
                }

                $commandClass = $commandInfo[0];
                $command = $this->container->make($commandClass);
                $args = array_slice($argv, 2);
                $command->execute($args);
                return 0;
            }
        }

        $matchingCommands = [];
        foreach ($this->getSortedCommands() as $name => $commandInfo) {
            if (str_starts_with($name, $commandName)) {
                if (str_starts_with($name, "dev:") && !$this->isDeveloperModeEnabled()) {
                    continue;
                }
                $matchingCommands[$name] = $commandInfo;
            }
        }

        if (count($matchingCommands) > 0) {
            $helpCommand = $this->container->make(HelpCommand::class);
            $helpCommand->execute($matchingCommands);
            return 0;
        }

        $this->showHelp();
        echo "Command not found: $commandName\n";
        return 1;
    }

    /**
     * Show interactive startup with splash screen and choice.
     * @throws ReflectionException|MissingServiceException
     */
    private function showInteractiveStartup(array $argv): int
    {
        $showSplash = true;
        $directMode = null;

        foreach ($argv as $arg) {
            if ($arg === "--no-splash") {
                $showSplash = false;
            } elseif ($arg === "--list") {
                $directMode = "list";
                $showSplash = false;
            } elseif ($arg === "--interactive") {
                $directMode = "interactive";
                $showSplash = false;
            }
        }

        if ($showSplash) {
            $splashService = $this->container->make(
                \Forge\Core\Services\SplashScreenService::class,
            );
            $splashService->showSplashScreen();
        }

        flush();
        usleep(100000);

        if ($directMode === "list") {
            $this->showHelp();
            return 0;
        }

        if ($directMode === "interactive") {
            return $this->showInteractiveMode();
        }

        try {
            $templateGenerator = $this->container->make(
                \Forge\Core\Services\TemplateGenerator::class,
            );

            $choice = $templateGenerator->selectFromList(
                "How would you like to proceed?",
                ["Show command list", "Interactive command browser"],
                "Interactive command browser",
            );

            if ($choice === null) {
                echo PHP_EOL . "Cancelled.\n";
                return 0;
            }

            if ($choice === "Show command list") {
                $this->showHelp();
                return 0;
            }

            if ($choice === "Interactive command browser") {
                return $this->showInteractiveMode();
            }
        } catch (\Throwable $e) {
            echo PHP_EOL . "Error: " . $e->getMessage() . PHP_EOL;
            echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
            if ($this->isDeveloperModeEnabled()) {
                echo "Trace: " . $e->getTraceAsString() . PHP_EOL;
            }
            return 1;
        }

        return 0;
    }

    /**
     * Show help.
     * @throws ReflectionException|MissingServiceException
     */
    private function showHelp(): void
    {
        $helpCommand = $this->container->make(HelpCommand::class);
        $helpCommand->execute($this->getSortedCommands());
    }

    /**
     * Return commands sorted by name.
     */
    private function getSortedCommands(): array
    {
        ksort($this->commands);
        return $this->commands;
    }

    /**
     * Show interactive command browser mode.
     * @throws ReflectionException|MissingServiceException
     */
    private function showInteractiveMode(): int
    {
        try {
            $helpCommand = $this->container->make(HelpCommand::class);
            $helpCommand->showInteractiveBrowser($this->getSortedCommands());
        } catch (\Throwable $e) {
            echo PHP_EOL .
                "Error in interactive mode: " .
                $e->getMessage() .
                PHP_EOL;
            echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
            if ($this->isDeveloperModeEnabled()) {
                echo "Trace: " . $e->getTraceAsString() . PHP_EOL;
            }
            return 1;
        }
        return 0;
    }
}
