<?php

declare(strict_types=1);

namespace Forge\CLI\Traits;

trait OutputHelper
{
    protected function info(string $message, string $context = ''): void
    {
        $prefix = $context ? "[{$context}] " : '';
        $this->output("\033[0;34m" . $prefix . $message . "\033[0m");
    }

    protected function clearScreen(): void
    {
        echo "\033[H\033[2J";
    }

    protected function warning(string $message, string $context = ''): void
    {
        $prefix = $context ? "[{$context}] " : '';
        $this->output("\033[1;33m" . $prefix . $message . "\033[0m");
    }

    protected function error(string $message, string $context = ''): void
    {
        $prefix = $context ? "[{$context}] " : '';
        $this->output("\033[0;31m" . $prefix . $message . "\033[0m");
    }

    protected function comment(string $message, string $context = ''): void
    {
        $prefix = $context ? "[{$context}] " : '';
        $this->output("\033[0;33m" . $prefix . $message . "\033[0m");
    }

    protected function debug(string $message, string $context = ''): void
    {
        $prefix = $context ? "[{$context}] " : '';
        echo "\033[35m{$prefix}{$message}\033[0m\n";
    }

    protected function log(string $message, string $context = 'LOG'): void
    {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] [" . $context . "]: {$message}";
        $this->output($logMessage);
    }

    protected function prompt(string $message): void
    {
        echo "\033[36m{$message}\033[0m ";
    }

    protected function array(array $data, ?string $title = null): void
    {
        if ($title) {
            $this->info($title);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->array($value, (string) $key);
                continue;
            }
            $this->output(sprintf("\033[0;36m%s:\033[0m %s", $key, $value));
        }
    }

    protected function line(string $message = ""): void
    {
        $this->output($message);
    }

    protected function success(string $message, string $context = ''): void
    {
        $prefix = $context ? "[{$context}] " : '';
        $this->output("\033[1;32m" . $prefix . $message . "\033[0m");
    }

    private function output(string $message): void
    {
        echo $message . PHP_EOL;
    }

    protected function table(array $headers, array $rows): void
    {
        if (empty($headers) || empty($rows)) {
            return;
        }

        $columnsWidth = array_map('strlen', $headers);

        foreach ($rows as $row) {
            if (is_array($row)) {
                foreach ($headers as $index => $header) {
                    $value = $row[$header] ?? '';
                    $columnsWidth[$index] = max($columnsWidth[$index], strlen((string) $value));
                }
            } elseif (is_object($row)) {
                foreach ($headers as $index => $header) {
                    if (isset($row->$header)) {
                        $columnsWidth[$index] = max($columnsWidth[$index], strlen((string) $row->$header));
                    } else {
                        $columnsWidth[$index] = max($columnsWidth[$index], strlen(''));
                    }
                }
            }
        }

        $headerLine = '| ' . implode(' | ', array_map(function ($header, $width) {
            return str_pad($header, $width);
        }, $headers, $columnsWidth)) . ' |';
        $this->line($headerLine);

        $separator = '+';
        foreach ($columnsWidth as $width) {
            $separator .= str_repeat('-', $width + 2) . '+';
        }
        $this->line($separator);

        foreach ($rows as $row) {
            $rowOutput = '| ';

            foreach ($headers as $index => $header) {
                $value = '';
                if (is_array($row) && array_key_exists($header, $row)) {
                    $value = $row[$header];
                } elseif (is_object($row) && isset($row->$header)) {
                    $value = $row->$header;
                }

                $rowOutput .= str_pad((string) $value, $columnsWidth[$index]) . ' | ';
            }

            $this->line($rowOutput);
        }
    }

    protected function showMessageBox(string $type, string $title, array $messages, ?string $footer = null): void
    {
        $width = 70;
        $borderChar = '▓';
        $border = str_repeat($borderChar, $width);

        $colors = [
            'danger' => ['border' => "\033[0;31m", 'title' => "\033[1;33m", 'message' => "\033[0;31m"],
            'warning' => ['border' => "\033[1;33m", 'title' => "\033[1;33m", 'message' => "\033[1;33m"],
            'info' => ['border' => "\033[0;34m", 'title' => "\033[1;34m", 'message' => "\033[0;34m"],
            'success' => ['border' => "\033[1;32m", 'title' => "\033[1;32m", 'message' => "\033[1;32m"],
            'normal' => ['border' => "\033[0m", 'title' => "\033[0m", 'message' => "\033[0m"],
        ];

        $color = $colors[$type] ?? $colors['normal'];
        $reset = "\033[0m";

        $titleSpaced = implode(' ', str_split(strtoupper($title)));
        $titlePadding = (int) (($width - strlen($titleSpaced)) / 2);
        $titleLine = str_repeat(' ', max(0, $titlePadding)) . $titleSpaced;

        $this->line('');
        $this->line($color['border'] . $border . $reset);
        $this->line($color['title'] . $titleLine . $reset);
        $this->line($color['border'] . $border . $reset);
        $this->line('');

        foreach ($messages as $message) {
            $formattedMessage = '  – ' . $message;
            $this->line($color['message'] . $formattedMessage . $reset);
        }

        if ($footer !== null) {
            $this->line('');
            $this->line($color['message'] . '  ' . $footer . $reset);
        }

        $this->line('');
    }

    protected function showDangerBox(string $title, array $messages, ?string $footer = null): void
    {
        $this->showMessageBox('danger', $title, $messages, $footer);
    }

    protected function showWarningBox(string $title, array $messages, ?string $footer = null): void
    {
        $this->showMessageBox('warning', $title, $messages, $footer);
    }

    protected function showInfoBox(string $title, array $messages, ?string $footer = null): void
    {
        $this->showMessageBox('info', $title, $messages, $footer);
    }

    protected function showSuccessBox(string $title, array $messages, ?string $footer = null): void
    {
        $this->showMessageBox('success', $title, $messages, $footer);
    }

    protected function showNormalBox(string $title, array $messages, ?string $footer = null): void
    {
        $this->showMessageBox('normal', $title, $messages, $footer);
    }

    protected function getTerminalWidth(): int
    {
        if (function_exists('exec')) {
            $output = [];
            $return = 0;
            @exec('tput cols 2>/dev/null', $output, $return);
            if ($return === 0 && !empty($output) && is_numeric($output[0])) {
                return (int) $output[0];
            }
        }

        if (isset($_ENV['COLUMNS']) && is_numeric($_ENV['COLUMNS'])) {
            return (int) $_ENV['COLUMNS'];
        }

        return 80;
    }

    protected function getTerminalHeight(): int
    {
        if (function_exists('exec')) {
            $output = [];
            $return = 0;
            @exec('tput lines 2>/dev/null', $output, $return);
            if ($return === 0 && !empty($output) && is_numeric($output[0])) {
                return (int) $output[0];
            }
        }

        if (isset($_ENV['LINES']) && is_numeric($_ENV['LINES'])) {
            return (int) $_ENV['LINES'];
        }

        return 24;
    }

    protected function showSplashScreen(int $duration = 1500): void
    {
        $splashService = new \Forge\Core\Services\SplashScreenService();
        $splashService->showSplashScreen($duration);
    }

    protected function showPostGenerationInfo(string $commandType, array $context = []): void
    {
        $type = $context['type'] ?? 'app';
        $module = $context['module'] ?? null;
        $name = $context['name'] ?? '';

        $tips = $this->getPostGenerationTips($commandType, $type, $module, $name);

        if (!empty($tips)) {
            $this->showInfoBox($tips['title'], $tips['messages']);
        }
    }

    private function getPostGenerationTips(string $commandType, string $type, ?string $module, string $name): array
    {
        $componentName = $this->getComponentExampleName($name, $type, $module);
        $modulePrefix = $module ? "{$module}:" : '';

        return match ($commandType) {
            'component' => [
                'title' => 'Component Usage Guide',
                'messages' => [
                    'Basic Usage (App Scope)',
                    "  <?= component('{$componentName}', ['type' => 'success']) ?>",
                    '',
                    'Module Scope',
                    "  <?= component('{$modulePrefix}notifications') ?>",
                    '',
                    'With Simple Slots',
                    "  <?= component('{$componentName}', [], ['header' => '...']) ?>",
                    '',
                    'With Named Parameters and Multiple Slots',
                    '  $footerSlots = [',
                    "    'footer_copy' => [",
                    "      'name' => '{$componentName}',",
                    "      'props' => ['type' => 'info', 'children' => '...']",
                    '    ]',
                    '  ];',
                    "  <?= component(name: '{$componentName}', slots: \$footerSlots) ?>",
                    '',
                    'Component location: ' . ($type === 'app' ? 'app/UI/views/components/' : "modules/{$module}/src/UI/views/components/"),
                    'Props are automatically extracted as variables in the component view.',
                ],
            ],
            'layout' => [
                'title' => 'Layout Usage Guide',
                'messages' => [
                    'App Scope',
                    "  <?php layout('{$name}') ?>",
                    '',
                    'Module Scope',
                    "  <?php layout('{$name}', fromModule: true, moduleName: '{$module}') ?>",
                    '',
                    'The layout receives a $content variable containing the rendered view.',
                    'Layout location: ' . ($type === 'app' ? 'app/UI/views/layouts/' : "modules/{$module}/src/UI/views/layouts/"),
                ],
            ],
            'controller' => [
                'title' => 'Controller Usage Guide',
                'messages' => [
                    'Defining Routes',
                    '  #[Route("/path")]',
                    '  public function index(Request $request): Response',
                    '',
                    'Applying Middleware',
                    '  Class level: #[UseMiddleware("web")]',
                    '  Method level: #[UseMiddleware("\\App\\Modules\\ModuleName\\Middlewares\\AuthMiddleware")]',
                    '  Multiple: Use multiple #[UseMiddleware] attributes',
                    '',
                    'Rendering Views',
                    '  return $this->view("name", $data);',
                    '',
                    'Dependency Injection',
                    '  public function __construct(private readonly Service $service) {}',
                    '',
                    'Configure middleware groups in config/middleware.php',
                ],
            ],
            'middleware' => [
                'title' => 'Middleware Usage Guide',
                'messages' => [
                    'Auto-Registration',
                    '  #[Middleware(group: "web", order: 1)]',
                    '',
                    'Applying to Controllers',
                    '  Group: #[UseMiddleware("web")]',
                    '  Class: #[UseMiddleware("\\App\\Modules\\Module\\Middlewares\\Middleware")]',
                    '  (No ::class needed)',
                    '',
                    'Register in config/middleware.php',
                    '  Add to existing groups (web, api, global)',
                    '  Create new middleware groups',
                    '  Override kernel-provided middleware',
                    '  Configure middleware order within groups',
                ],
            ],
            'model' => [
                'title' => 'Model Usage Guide',
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
            'event' => [
                'title' => 'Event Usage Guide',
                'messages' => [
                    'Dispatching Events',
                    '  Event::dispatch(new EventName($data));',
                    '',
                    'Event Listeners',
                    '  Register listeners in EventServiceProvider',
                    '  Use #[EventListener] attribute',
                    '',
                    'Event Service Provider',
                    '  Register in app/Providers/EventServiceProvider.php',
                ],
            ],
            'service' => [
                'title' => 'Service Usage Guide',
                'messages' => [
                    'Service Registration',
                    '  #[Service]',
                    '  class MyService {}',
                    '',
                    'Dependency Injection',
                    '  public function __construct(private readonly MyService $service) {}',
                    '',
                    'Service Location',
                    '  Services are auto-registered in the DI container',
                    '  Available throughout the application',
                ],
            ],
            'dto' => [
                'title' => 'DTO Usage Guide',
                'messages' => [
                    'DTO Usage Patterns',
                    '  $dto = new UserDTO(name: "John", email: "john@example.com");',
                    '',
                    'Passing to Views/Components',
                    '  return $this->view("user", $dto);',
                    '',
                    'Automatic Property Extraction',
                    '  DTO properties are automatically extracted as variables',
                    '  Available directly in views: <?= $name ?>',
                ],
            ],
            'migration' => [
                'title' => 'Migration Usage Guide',
                'messages' => [
                    'Running Migrations',
                    '  php forge.php migrate',
                    '',
                    'Rolling Back',
                    '  php forge.php migrate:rollback',
                    '',
                    'Migration File Structure',
                    '  Up method: Schema changes',
                    '  Down method: Rollback changes',
                ],
            ],
            'seeder' => [
                'title' => 'Seeder Usage Guide',
                'messages' => [
                    'Running Seeders',
                    '  php forge.php db:seed',
                    '',
                    'Using in DatabaseSeeder',
                    '  $this->call(SeederName::class);',
                    '',
                    'Seeding Patterns',
                    '  Use factories for test data',
                    '  Use seeders for initial data',
                ],
            ],
            'enum' => [
                'title' => 'Enum Usage Guide',
                'messages' => [
                    'Enum Usage',
                    '  $status = Status::Active;',
                    '',
                    'Type Hints',
                    '  public function setStatus(Status $status): void',
                    '',
                    'Comparisons',
                    '  if ($status === Status::Active) {}',
                ],
            ],
            'trait' => [
                'title' => 'Trait Usage Guide',
                'messages' => [
                    'Using Traits',
                    '  use TraitName;',
                    '',
                    'Trait Location',
                    '  App: App\\Traits\\TraitName',
                    '  Module: App\\Modules\\Module\\Traits\\TraitName',
                ],
            ],
            'test' => [
                'title' => 'Test Usage Guide',
                'messages' => [
                    'Running Tests',
                    '  php forge.php test',
                    '',
                    'Test Groups',
                    '  #[Group("unit")]',
                    '  php forge.php test --group=unit',
                    '',
                    'Test Structure',
                    '  Use PHPUnit test methods',
                    '  Organize tests by feature',
                ],
            ],
            default => [],
        };
    }

    private function getComponentExampleName(string $name, string $type, ?string $module): string
    {
        if (str_contains($name, '/')) {
            return $name;
        }
        return "ui/{$name}";
    }
}
