<?php

declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Attributes\Command as CommandAttr;
use Forge\CLI\Command;
use Forge\Core\Config\Environment;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Services\TemplateGenerator;
use Forge\Core\DI\Container;
use ReflectionClass;
use ReflectionException;

#[Cli(command: 'help', description: 'Displays help for available commands.')]
class HelpCommand extends Command
{
    public function __construct(
        private readonly TemplateGenerator $templateGenerator,
        private readonly Container $container
    ) {
    }

    /**
     * Show interactive command browser.
     * @throws ReflectionException
     */
    public function showInteractiveBrowser(array $commands): void
    {
        while (true) {
            $this->showInfoBox(
                'FORGE FRAMEWORK CLI',
                ['Welcome to the interactive command browser', 'Navigate through commands and execute them directly'],
                'Select an option to continue'
            );

            $browseChoice = $this->templateGenerator->selectFromList(
                "How would you like to browse?",
                ['Browse by category', 'Browse all commands', 'Exit'],
                'Browse by category'
            );

            if ($browseChoice === null || $browseChoice === 'Exit') {
                $this->info('Exiting interactive browser.');
                return;
            }

            if ($browseChoice === 'Browse by category') {
                $this->browseByCategory($commands);
            } else {
                $this->browseAllCommands($commands);
            }
        }
    }

    /**
     * Browse commands grouped by category.
     * @throws ReflectionException
     */
    private function browseByCategory(array $commands): void
    {
        $grouped = $this->groupCommandsByCategory($commands);
        $categories = array_keys($grouped);
        sort($categories);

        $categoryOptions = array_map(function ($cat) {
            return ucfirst($cat);
        }, $categories);

        $categoryOptionsWithBack = array_merge($categoryOptions, ['Back']);
        $selectedCategory = $this->templateGenerator->selectFromListMultiColumn(
            "Select a category",
            $categoryOptionsWithBack
        );

        if ($selectedCategory === null || $selectedCategory === 'Back') {
            return;
        }

        $categoryKey = strtolower($selectedCategory);
        if (!isset($grouped[$categoryKey])) {
            $this->error("Category not found.");
            return;
        }

        $categoryCommands = $grouped[$categoryKey];
        $this->showCommandsInCategory($categoryKey, $categoryCommands, $commands);
    }

    /**
     * Group commands by category.
     * @throws ReflectionException
     */
    private function groupCommandsByCategory(array $commands): array
    {
        $grouped = [];
        $isDeveloperMode = $this->isDeveloperModeEnabled();

        foreach ($commands as $name => $commandInfo) {
            if (!$isDeveloperMode && str_starts_with($name, 'dev:')) {
                continue;
            }

            $commandClass = $commandInfo[0] ?? null;
            if (!$commandClass)
                continue;

            $reflectionClass = new ReflectionClass($commandClass);
            $cliAttrs = $reflectionClass->getAttributes(CommandAttr::class) ?: $reflectionClass->getAttributes(Cli::class);
            $cli = count($cliAttrs) ? $cliAttrs[0]->newInstance() : null;
            if (!$cli)
                continue;

            $prefix = strstr($name, ':', true) ?: 'General';
            $grouped[$prefix][$name] = $cli->description;
        }

        return $grouped;
    }

    /**
     * Show commands in a specific category.
     * @throws ReflectionException
     */
    private function showCommandsInCategory(string $category, array $categoryCommands, array $allCommands): void
    {
        $commandOptions = [];
        foreach ($categoryCommands as $name => $description) {
            $displayName = "{$name} - {$description}";
            $commandOptions[$name] = $displayName;
        }

        ksort($commandOptions);
        $options = array_values($commandOptions);
        $keys = array_keys($commandOptions);

        $this->showNormalBox(
            'Category: ' . ucfirst($category),
            array_map(function ($name, $desc) {
                return "{$name} - {$desc}";
            }, array_keys($categoryCommands), array_values($categoryCommands))
        );

        $this->clearScreen();

        $optionsWithBack = array_merge($options, ['Back']);
        $selected = $this->templateGenerator->selectFromListMultiColumn(
            "Select a command",
            $optionsWithBack
        );

        if ($selected === null || $selected === 'Back') {
            return;
        }

        $selectedIndex = array_search($selected, $options, true);
        if ($selectedIndex === false) {
            $this->error("Command not found.");
            return;
        }

        $commandName = $keys[$selectedIndex];
        $this->showCommandActions($commandName, $allCommands);
    }

    /**
     * Show actions for a selected command.
     * @throws ReflectionException
     */
    private function showCommandActions(string $commandName, array $commands): void
    {
        if (!isset($commands[$commandName])) {
            $this->error("Command '{$commandName}' not found.");
            return;
        }

        $action = $this->templateGenerator->selectFromList(
            "What would you like to do with '{$commandName}'?",
            ['Execute command', 'Show help', 'Back'],
            'Show help'
        );

        if ($action === null) {
            return;
        }

        if ($action === 'Show help') {
            $this->showCommandHelp($commandName, $commands);
            $this->line('');
            $return = $this->templateGenerator->askQuestion("Return to browser? (Y/n)", "Y");
            if (strtolower(trim($return)) === 'y' || $return === '') {
                $this->showInteractiveBrowser($commands);
            }
        } elseif ($action === 'Execute command') {
            $this->executeSelectedCommand($commandName, $commands);
        } elseif ($action === 'Back') {
            $this->showInteractiveBrowser($commands);
        }
    }

    /**
     * Execute a selected command.
     * @throws ReflectionException
     */
    private function executeSelectedCommand(string $commandName, array $commands): void
    {
        if (!isset($commands[$commandName])) {
            $this->error("Command '{$commandName}' not found.");
            return;
        }

        [$commandClass] = $commands[$commandName];

        try {
            $command = $this->container->make($commandClass);

            $this->line('');
            $this->info("Executing command: {$commandName}");
            $this->line('');

            $command->execute([]);

            $this->line('');
            $return = $this->templateGenerator->askQuestion("Return to browser? (Y/n)", "Y");
            if (strtolower(trim($return)) === 'y' || $return === '') {
                $this->showInteractiveBrowser($commands);
            }
        } catch (\Exception $e) {
            $this->error("Error executing command: " . $e->getMessage());
            $this->line('');
            $return = $this->templateGenerator->askQuestion("Return to browser? (Y/n)", "Y");
            if (strtolower(trim($return)) === 'y' || $return === '') {
                $this->showInteractiveBrowser($commands);
            }
        }
    }

    /**
     * @throws ReflectionException
     */
    public function execute(array $args): int
    {
        if ($cmd = $this->option('command', $GLOBALS['argv'] ?? [])) {
            $this->showCommandHelp($cmd, $args);
            return 0;
        }

        $this->showGlobalHelp($args);

        return 0;
    }

    /**
     * @throws ReflectionException
     */
    private function showCommandHelp(string $name, array $commands): void
    {
        if (!isset($commands[$name])) {
            $this->error("Command $name not found.");
            return;
        }

        [$class] = $commands[$name];
        $ref = new ReflectionClass($class);

        $cliAttrs = $ref->getAttributes(Cli::class);
        $cli = count($cliAttrs) ? $cliAttrs[0]->newInstance() : null;

        $this->line('');
        $this->info("Command: $name");
        $this->line($cli?->description ?? '');
        $this->line('');

        if ($cli?->usage) {
            $this->comment('Usage:');
            $this->line('  php forge.php ' . $cli->usage);
            $this->line('');
        }

        $args = [];
        foreach ($ref->getProperties() as $p) {
            $argAttrs = $p->getAttributes(Arg::class);
            $arg = count($argAttrs) ? $argAttrs[0]->newInstance() : null;
            if (!$arg)
                continue;

            $args[] = [
                'OPTION' => '--' . $arg->name,
                'DESCRIPTION' => $arg->description . ($arg->required ? ' (required)' : ''),
            ];
        }

        if ($args) {
            $this->comment('Arguments:');
            $this->table(['OPTION', 'DESCRIPTION'], $args);
            $this->line('');
        }

        if ($cli?->examples) {
            $this->comment('Examples:');
            foreach ($cli->examples as $ex) {
                $this->line('  php forge.php ' . $ex);
            }
            $this->line('');
        }

        $usageTips = $this->getCommandUsageTips($name);
        if (!empty($usageTips)) {
            $this->showInfoBox($usageTips['title'], $usageTips['messages']);
        }
    }

    private function getCommandUsageTips(string $commandName): array
    {
        $normalizedName = $this->normalizeCommandName($commandName);

        return match ($normalizedName) {
            'generate:component' => [
                'title' => 'Component Usage Tips',
                'messages' => [
                    'Basic Usage (App Scope)',
                    "  <?= component('ui/alert', ['type' => 'success']) ?>",
                    '',
                    'Module Scope',
                    "  <?= component('ForgeUi:notifications') ?>",
                    '',
                    'With Named Parameters and Multiple Slots',
                    '  $footerSlots = [',
                    "    'footer_copy' => [",
                    "      'name' => 'ui/alert',",
                    "      'props' => ['type' => 'info', 'children' => '...']",
                    '    ]',
                    '  ];',
                    "  <?= component(name: 'ui/footer', slots: \$footerSlots) ?>",
                ],
            ],
            'generate:layout' => [
                'title' => 'Layout Usage Tips',
                'messages' => [
                    'App Scope',
                    "  <?php layout('main') ?>",
                    '',
                    'Module Scope',
                    "  <?php layout('admin', fromModule: true, moduleName: 'MyModule') ?>",
                    '',
                    'The layout receives a $content variable containing the rendered view.',
                ],
            ],
            'generate:controller' => [
                'title' => 'Controller Usage Tips',
                'messages' => [
                    'Defining Routes',
                    '  #[Route("/path")]',
                    '  public function index(Request $request): Response',
                    '',
                    'Applying Middleware',
                    '  Class level: #[UseMiddleware("web")]',
                    '  Method level: #[UseMiddleware("\\Modules\\ModuleName\\Middlewares\\AuthMiddleware")]',
                    '  Multiple: Use multiple #[UseMiddleware] attributes',
                    '',
                    'Rendering Views',
                    '  return $this->view("name", $data);',
                    '',
                    'Dependency Injection',
                    '  public function __construct(private readonly Service $service) {}',
                ],
            ],
            'generate:middleware' => [
                'title' => 'Middleware Usage Tips',
                'messages' => [
                    'Module Registration (in your module register() method)',
                    '  ForgeRouterModule::registerMiddleware(MyMiddleware::class, "web", 100);',
                    '',
                    'Applying to Controllers',
                    '  Group: #[UseMiddleware("web")]',
                    '  Class: #[UseMiddleware("\\Modules\\Module\\Middlewares\\Middleware")]',
                    '  (No ::class needed)',
                    '',
                    'Configure in config/middleware.php',
                    '  Add to existing groups (web, api, global)',
                    '  Create new middleware groups',
                    '  Override module-provided middleware order',
                ],
            ],
            'generate:model' => [
                'title' => 'Model Usage Tips',
                'messages' => [
                    'ORM Attributes',
                    '  #[Table("table_name")]',
                    '  #[Column(cast: Cast::STRING)]',
                    '',
                    'Model Usage',
                    '  Model::find($id)',
                    '  Model::create([...])',
                    '  Model::where("field", "value")->get()',
                    '',
                    'Available Traits',
                    '  HasTimeStamps - Automatic timestamps',
                    '  HasMetaData - Metadata support',
                    '  TenantScopedTrait - Multi-tenant support',
                ],
            ],
            'generate:event' => [
                'title' => 'Event Usage Tips',
                'messages' => [
                    'Dispatching Events',
                    '  Event::dispatch(new EventName($data));',
                    '',
                    'Event Listeners',
                    '  Register listeners in EventServiceProvider',
                    '  Use #[EventListener] attribute',
                ],
            ],
            'generate:service' => [
                'title' => 'Service Usage Tips',
                'messages' => [
                    'Service Registration',
                    '  #[Service]',
                    '  class MyService {}',
                    '',
                    'Dependency Injection',
                    '  public function __construct(private readonly MyService $service) {}',
                ],
            ],
            'generate:dto' => [
                'title' => 'DTO Usage Tips',
                'messages' => [
                    'DTO Usage Patterns',
                    '  $dto = new UserDTO(name: "John", email: "john@example.com");',
                    '',
                    'Passing to Views/Components',
                    '  return $this->view("user", $dto);',
                    '',
                    'Automatic Property Extraction',
                    '  DTO properties are automatically extracted as variables',
                ],
            ],
            'generate:migration' => [
                'title' => 'Migration Usage Tips',
                'messages' => [
                    'Running Migrations',
                    '  php forge.php migrate',
                    '',
                    'Rolling Back',
                    '  php forge.php migrate:rollback',
                ],
            ],
            'generate:seeder' => [
                'title' => 'Seeder Usage Tips',
                'messages' => [
                    'Running Seeders',
                    '  php forge.php db:seed',
                    '',
                    'Using in DatabaseSeeder',
                    '  $this->call(SeederName::class);',
                ],
            ],
            default => [],
        };
    }

    private function normalizeCommandName(string $commandName): string
    {
        if (str_starts_with($commandName, 'modules:')) {
            return substr($commandName, 8);
        }
        if (str_starts_with($commandName, 'module:')) {
            return substr($commandName, 7);
        }
        if (str_starts_with($commandName, 'app:')) {
            return substr($commandName, 4);
        }
        return $commandName;
    }

    /**
     * @throws ReflectionException
     */
    private function showGlobalHelp(array $args): void
    {
        $this->line();
        $this->info("Forge Framework CLI Tool");

        $grouped = [];
        $isDeveloperMode = $this->isDeveloperModeEnabled();

        foreach ($args as $name => $commandInfo) {
            if (!$isDeveloperMode && str_starts_with($name, 'dev:')) {
                continue;
            }

            $commandClass = $commandInfo[0] ?? null;
            if (!$commandClass)
                continue;

            $reflectionClass = new ReflectionClass($commandClass);
            $cliAttrs = $reflectionClass->getAttributes(CommandAttr::class) ?: $reflectionClass->getAttributes(Cli::class);
            $cli = count($cliAttrs) ? $cliAttrs[0]->newInstance() : null;
            if (!$cli)
                continue;

            $prefix = strstr($name, ':', true) ?: 'General';
            $grouped[$prefix][$name] = $cli->description;
        }

        ksort($grouped);

        foreach ($grouped as $group => $commands) {
            $this->line("\n\033[1;34m" . ucfirst($group) . " commands\033[0m");

            ksort($commands);

            $headers = ['COMMAND', 'DESCRIPTION'];
            $rows = [];
            foreach ($commands as $cmd => $desc) {
                $rows[] = ['COMMAND' => $cmd, 'DESCRIPTION' => $desc];
            }

            $this->table($headers, $rows);
        }

        $this->line('');
        $this->comment("Run php forge.php help --command=<name> for detailed usage and examples.");
    }

    private function isDeveloperModeEnabled(): bool
    {
        $env = Environment::getInstance();
        $envValue = $env->get('FORGE_DEVELOPER_MODE');

        if ($envValue === 'true' || $envValue === true) {
            return true;
        }

        try {
            $configPath = BASE_PATH . '/config/app.php';
            if (FileExistenceCache::exists($configPath)) {
                $config = require $configPath;
                return ($config['developer_mode'] ?? false) === true;
            }
        } catch (\Exception $e) {
        }

        return false;
    }

    /**
     * Browse all commands in one list.
     * @throws ReflectionException
     */
    private function browseAllCommands(array $commands): void
    {
        $commandOptions = [];
        $isDeveloperMode = $this->isDeveloperModeEnabled();

        foreach ($commands as $name => $commandInfo) {
            if (!$isDeveloperMode && str_starts_with($name, 'dev:')) {
                continue;
            }

            $commandClass = $commandInfo[0] ?? null;
            if (!$commandClass)
                continue;

            $reflectionClass = new ReflectionClass($commandClass);
            $cliAttrs = $reflectionClass->getAttributes(CommandAttr::class) ?: $reflectionClass->getAttributes(Cli::class);
            $cli = count($cliAttrs) ? $cliAttrs[0]->newInstance() : null;
            if (!$cli)
                continue;

            $description = $cli->description ?? 'No description';
            $displayName = "{$name} - {$description}";
            $commandOptions[$name] = $displayName;
        }

        ksort($commandOptions);
        $options = array_values($commandOptions);
        $keys = array_keys($commandOptions);

        $optionsWithBack = array_merge($options, ['Back']);
        $selected = $this->templateGenerator->selectFromListMultiColumn(
            "Select a command",
            $optionsWithBack
        );

        if ($selected === null || $selected === 'Back') {
            return;
        }

        $selectedIndex = array_search($selected, $options, true);
        if ($selectedIndex === false) {
            $this->error("Command not found.");
            return;
        }

        $commandName = $keys[$selectedIndex];
        $this->showCommandActions($commandName, $commands);
    }
}
