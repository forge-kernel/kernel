<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\TemplateGenerator;
use Forge\Traits\StringHelper;

#[Cli(
    command: 'dev:blueprint:scaffold',
    description: 'Scaffold a new blueprint source template',
    usage: 'dev:blueprint:scaffold --name=blueprint-name [--source=/path/to/output]',
    examples: [
        'dev:blueprint:scaffold --name=my-blueprint',
        'dev:blueprint:scaffold --name=api-blueprint',
        'dev:blueprint:scaffold --name=custom --source=./my-custom-blueprint',
    ]
)]
final class BlueprintScaffoldCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    #[Arg(name: 'name', description: 'Blueprint name in kebab-case', required: true)]
    private ?string $name = null;

    #[Arg(name: 'source', description: 'Output path for the blueprint source (default: ./blueprints/<name>)', required: false)]
    private ?string $source = null;

    public function __construct(
        private readonly TemplateGenerator $templateGenerator
    ) {
    }

    public function execute(array $args): int
    {
        $this->wizard($args);

        if (!$this->name) {
            $this->error('Blueprint name is required.');
            return 1;
        }

        $blueprintNameKebab = self::toKebabCase($this->name);
        $blueprintDir = $this->source ?? BASE_PATH . "/blueprint-templates/{$blueprintNameKebab}";

        if (is_dir($blueprintDir)) {
            $this->error("Blueprint '{$blueprintNameKebab}' already exists at: {$blueprintDir}");
            return 1;
        }

        $displayName = $this->templateGenerator->askQuestion(
            'Blueprint display name: ',
            $this->toPascalCase($blueprintNameKebab) . ' Blueprint'
        );

        $description = $this->templateGenerator->askQuestion(
            'Description: ',
            ''
        );

        $engineVersion = $this->templateGenerator->askQuestion(
            'Kernel version (e.g., latest, 5.0.2): ',
            'latest'
        );

        $this->info("Scaffolding blueprint '{$blueprintNameKebab}'...");

        $baseDir = "{$blueprintDir}/base";

        $dirs = [
            "{$baseDir}/app",
            "{$baseDir}/config",
            "{$baseDir}/public",
            "{$baseDir}/storage",
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        file_put_contents(
            "{$baseDir}/forge.json",
            json_encode([
                'name' => $displayName,
                'kernel' => [
                    'name' => 'forge-kernel',
                    'version' => $engineVersion,
                ],
                'modules' => [
                    'forge-package-manager' => '*',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        file_put_contents(
            "{$baseDir}/index.php",
            "<?php\n\nhttp_response_code(403);\necho \"Access denied.\";\nexit();\n"
        );

        file_put_contents(
            "{$baseDir}/forge.php",
            <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

define("BASE_PATH", __DIR__);
require_once BASE_PATH . "/kernel/Core/Support/helpers.php";

use Forge\Core\Bootstrap\Bootstrap;
use Forge\Core\DI\Container;
use Forge\CLI\Application;
use Forge\Core\Autoloader;
use Forge\Core\Config\EnvParser;
use Forge\Core\Debug\Metrics;

require BASE_PATH . "/kernel/Core/Autoloader.php";
require BASE_PATH . "/kernel/Core/Config/EnvParser.php";

Autoloader::register();
EnvParser::load(BASE_PATH . "/.env");

ini_set('display_errors', '1');
error_reporting(E_ALL);

$container = Container::getInstance();
Metrics::start('cli_resolution');
$container = Bootstrap::initCliContainer();

$app = $container->get(Application::class);
Metrics::stop('cli_resolution');
exit($app->run($argv));
PHP
        );

        file_put_contents(
            "{$baseDir}/public/index.php",
            <<<'PHP'
<?php

declare(strict_types=1);

define("BASE_PATH", dirname(__DIR__));

require_once BASE_PATH . "/kernel/Core/Support/helpers.php";
require_once BASE_PATH . "/kernel/Core/Autoloader.php";

\Forge\Core\Autoloader::register();

$maintenanceFile = BASE_PATH . '/storage/framework/maintenance.html';
if (file_exists($maintenanceFile)) {
    readfile($maintenanceFile);
    exit;
}

\Forge\Core\Engine::init();
PHP
        );

        file_put_contents(
            "{$baseDir}/public/.htaccess",
            <<<'HTACCESS'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /public/
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^files/(.+)$ index.php?file=$1 [L,QSA]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>

<FilesMatch "^\.env">
    Require all denied
</FilesMatch>

<FilesMatch "^(composer\.(json|lock)|package\.json|yarn\.lock|phpunit\.xml)$">
    Require all denied
</FilesMatch>

Options -Indexes
HTACCESS
        );

        $this->writeConfigFiles($baseDir);
        $this->writeEnvFiles($baseDir);
        $this->writeMetaFiles($baseDir, $blueprintNameKebab, $description);

        $installSource = BASE_PATH . '/blueprint-templates/blank/install.php';
        if (file_exists($installSource)) {
            copy($installSource, "{$baseDir}/install.php");
        } else {
            $installFallback = BASE_PATH . '/forge-blueprint/install.php';
            if (file_exists($installFallback)) {
                file_put_contents(
                    "{$baseDir}/install.php",
                    file_get_contents($installFallback)
                );
            }
        }

        $configOptions = $this->collectConfigOptions();
        if (!empty($configOptions)) {
            file_put_contents(
                "{$blueprintDir}/blueprint-config.json",
                json_encode(['options' => $configOptions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );

            foreach ($configOptions as $optionDef) {
                foreach ($optionDef['options'] as $choice) {
                    $optionDir = "{$blueprintDir}/{$choice['value']}";
                    if (!is_dir($optionDir)) {
                        mkdir($optionDir, 0755, true);
                    }
                    file_put_contents("{$optionDir}/.gitkeep", '');
                }
            }

            $this->info('Config options scaffolded with empty subdirectories.');
        }

        $relativePath = str_replace(BASE_PATH . '/', '', $blueprintDir);
        $this->success("Blueprint '{$blueprintNameKebab}' scaffolded successfully at: {$blueprintDir}");
        $this->info("Next steps:");
        $this->info("  1. Edit {$relativePath}/base/forge.json to add modules");
        $this->info("  2. Populate option subdirectories with overlay files");
        if (file_exists("{$blueprintDir}/blueprint-config.json")) {
            $this->info("  3. Edit {$relativePath}/blueprint-config.json to refine options");
            $this->info("  4. php forge.php dev:blueprint:version --name={$blueprintNameKebab}");
        } else {
            $this->info("  2. php forge.php dev:blueprint:version --name={$blueprintNameKebab}");
        }

        return 0;
    }

    private function collectConfigOptions(): array
    {
        $addOptions = $this->templateGenerator->askQuestion(
            'Would you like to define configurable options (e.g., auth implementation, features)? (yes/no): ',
            'no'
        );

        if (!in_array(strtolower($addOptions), ['yes', 'y', '1', 'true'], true)) {
            return [];
        }

        $this->line('');
        $this->info('── Define Config Options ──────────────────────────────');
        $this->line('Each option is a choice point for users scaffolding this blueprint.');
        $this->line('Option values map to subdirectories that will be overlaid on base/.');
        $this->line('');

        $options = [];
        $optionIndex = 1;

        while (true) {
            $this->info("Option #{$optionIndex}:");

            $key = $this->templateGenerator->askQuestion(
                '  Key (e.g., "auth", "features"): ',
                'option-' . $optionIndex
            );

            $label = $this->templateGenerator->askQuestion(
                '  Label (e.g., "Auth Implementation"): ',
                $this->toPascalCase($key)
            );

            $typeInput = $this->templateGenerator->selectFromList(
                '  Type:',
                ['select', 'multi-select'],
                'select'
            );
            $type = $typeInput === 'multi-select' ? 'multi-select' : 'select';

            $requiredInput = $this->templateGenerator->askQuestion(
                '  Required? (yes/no): ',
                'yes'
            );
            $required = in_array(strtolower($requiredInput), ['yes', 'y', '1', 'true'], true);

            if ($type === 'multi-select') {
                $defaultInput = $this->templateGenerator->askQuestion(
                    '  Default values (comma-separated, leave empty for none): ',
                    ''
                );
            } else {
                $defaultInput = $this->templateGenerator->askQuestion(
                    '  Default value (leave empty for none): ',
                    ''
                );
            }

            $this->line('');
            $this->info("  Define choices for \"{$key}\":");

            $choices = [];
            $choiceIndex = 1;

            while (true) {
                $this->line('');
                $this->info("  Choice #{$choiceIndex}:");

                $choiceValue = $this->templateGenerator->askQuestion(
                    '    Value (maps to subdirectory name, e.g., "standard"): ',
                    'choice-' . $choiceIndex
                );

                $choiceLabel = $this->templateGenerator->askQuestion(
                    '    Label (e.g., "Standard Auth"): ',
                    $this->toPascalCase($choiceValue)
                );

                $choiceDescription = $this->templateGenerator->askQuestion(
                    '    Description (shown to user during selection): ',
                    ''
                );

                $choiceModules = [];
                $addModules = $this->templateGenerator->askQuestion(
                    '    Add module dependencies for this choice? (yes/no): ',
                    'no'
                );

                if (in_array(strtolower($addModules), ['yes', 'y', '1', 'true'], true)) {
                    while (true) {
                        $modName = $this->templateGenerator->askQuestion(
                            '      Module name (e.g., forge-app-auth): ',
                            ''
                        );
                        if (empty($modName)) {
                            break;
                        }
                        $modVersion = $this->templateGenerator->askQuestion(
                            '      Version (e.g., "latest", "1.0.0"): ',
                            'latest'
                        );
                        $choiceModules[$modName] = $modVersion;

                        $more = $this->templateGenerator->askQuestion(
                            '      Add another module? (yes/no): ',
                            'no'
                        );
                        if (!in_array(strtolower($more), ['yes', 'y', '1', 'true'], true)) {
                            break;
                        }
                    }
                }

                $choice = [
                    'value' => $choiceValue,
                    'label' => $choiceLabel,
                ];
                if ($choiceDescription) {
                    $choice['description'] = $choiceDescription;
                }
                if (!empty($choiceModules)) {
                    $choice['modules'] = $choiceModules;
                }
                $choices[] = $choice;

                $choiceIndex++;

                $moreChoices = $this->templateGenerator->askQuestion(
                    '  Add another choice? (yes/no): ',
                    'yes'
                );
                if (!in_array(strtolower($moreChoices), ['yes', 'y', '1', 'true'], true)) {
                    break;
                }
            }

            $optionDef = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => $required,
                'options' => $choices,
            ];

            if ($defaultInput !== '') {
                if ($type === 'multi-select') {
                    $optionDef['default'] = array_map('trim', explode(',', $defaultInput));
                } else {
                    $optionDef['default'] = $defaultInput;
                }
            }

            $options[] = $optionDef;
            $optionIndex++;

            $this->line('');
            $moreOptions = $this->templateGenerator->askQuestion(
                'Add another option? (yes/no): ',
                'yes'
            );
            if (!in_array(strtolower($moreOptions), ['yes', 'y', '1', 'true'], true)) {
                break;
            }
        }

        return $options;
    }

    private function writeConfigFiles(string $dir): void
    {
        file_put_contents(
            "{$dir}/config/middleware.php",
            <<<'PHP'
<?php

return [
    "global" => [
        \App\Modules\ForgeRouter\Http\Middlewares\CorsMiddleware::class,
        \App\Modules\ForgeRouter\Http\Middlewares\SanitizeInputMiddleware::class,
        \App\Modules\ForgeRouter\Http\Middlewares\CompressionMiddleware::class,
    ],
    "web" => [
        \App\Modules\ForgeRouter\Http\Middlewares\SessionMiddleware::class,
        \App\Modules\ForgeRouter\Http\Middlewares\CsrfMiddleware::class,
        \App\Modules\ForgeRouter\Http\Middlewares\RelaxSecurityHeadersMiddleware::class,
    ],
    "api" => [
        \App\Modules\ForgeRouter\Http\Middlewares\IpWhiteListMiddleware::class,
        \App\Modules\ForgeRouter\Http\Middlewares\ApiKeyMiddleware::class,
        \App\Modules\ForgeRouter\Http\Middlewares\CookieMiddleware::class,
    ],
];
PHP
        );

        file_put_contents(
            "{$dir}/config/source_list.php",
            <<<'PHP'
<?php

return [
    "registry" => [
        [
            "name" => "kernel-module-registry",
            "type" => "git",
            "url" => "https://github.com/forge-kernel/kernel-module-registry",
            "branch" => "main",
            "private" => false,
            "personal_token" => env("GITHUB_TOKEN"),
            "description" => "Forge Kernel Official Modules",
        ],
    ],
    "cache_ttl" => 3600,
];
PHP
        );

        file_put_contents(
            "{$dir}/config/registry.php",
            "<?php\nreturn [];\n"
        );
    }

    private function writeEnvFiles(string $dir): void
    {
        $envContent = <<<'ENV'
# APP
APP_NAME="Forge Kernel"
APP_ENV=development
APP_METRICS_ENABLED=false
APP_DEBUG=true
APP_KEY=secret-key

# CACHE
CACHE_DRIVER=sqlite

# Security
CSP_ENABLED=false
IP_WHITE_LIST=[127.0.0.1, ::1]
CORS_ALLOWED_ORIGINS=["http://localhost:8000", "https://forge-v3.test"]
CORS_ALLOWED_METHODS=['GET, POST, PUT, PATCH, DELETE, OPTIONS']
CORS_ALLOWED_HEADERS=['Content-Type', 'Authorization']

SESSION_SECURE=true
COOKIE_SECURE=true
CSRF_STRICT_SCHEME=true
TRUST_PROXIES=true

#Queue
QUEUE_DRIVER=database
QUEUE_LIST=[notifications,cache_refresh,page_visits]

#Database
DB_DRIVER=sqlite
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=forge_v3
DB_USER=root
DB_PASS=root
SQLITE_PATH=/storage/database
SQLITE_DB=/database.sqlite

# Session Configuration
SESSION_DRIVER=sqlite
SESSION_DB_PATH=/storage/database/security.sqlite
SESSION_NAME=FORGE_SESSID
SESSION_LIFETIME=3600
SESSION_PATH=/
SESSION_DOMAIN=
SESSION_SECURE=true
SESSION_HTTPONLY=true
SESSION_SAMESITE=Lax

# Cookie Defaults
COOKIE_PATH=/
COOKIE_DOMAIN=
COOKIE_SECURE=true
COOKIE_HTTPONLY=true
COOKIE_SAMESITE=Lax
SESSION_ENABLED=false

# Rate Limit Configuration
RATE_LIMIT_DRIVER=sqlite
RATE_LIMIT_DB_PATH=/storage/framework/security.sqlite
RATE_LIMIT_MAX_REQUESTS=100
RATE_LIMIT_TIME_WINDOW=60
RATE_LIMIT_DISABLE_IN_DEV=true
RATE_LIMIT_ENABLED=true

# Circuit Breaker Configuration
CIRCUIT_BREAKER_DRIVER=sqlite
CIRCUIT_BREAKER_DB_PATH=/storage/framework/security.sqlite
CIRCUIT_BREAKER_MAX_FAILURES=50
CIRCUIT_BREAKER_RESET_TIME=300
CIRCUIT_BREAKER_DISABLE_IN_DE=true

# Customize logging
LOG_DRIVER=file
LOG_PATH=/storage/logs/forge.log

# Mail configuration
SMTP_HOST=localhost
SMTP_PORT=1025
SMTP_ENCRYPTION=none
SMTP_FROM_ADDRESS=noreply@forge.test
SMTP_FROM_NAME="Forge Application"

# File Storage
FILE_STORAGE_PATH=public/storage/files
STORAGE_PROVIDER=local

GITHUB_TOKEN=your-token

FORGE_DEVELOPER_MODE=false

DISABLED_MODULES=[]
ENV;

        file_put_contents("{$dir}/.env", $envContent);
        file_put_contents("{$dir}/env-example", $envContent);
    }

    private function writeMetaFiles(string $dir, string $blueprintNameKebab, string $description): void
    {
        file_put_contents(
            "{$dir}/.forgeignore",
            "storage/bin/\nstorage/database/\nstorage/sessions/\nstorage/framework/cache/\nstorage/framework/routes/\nstorage/app/uploads/\nstorage/framework/\nstorage/logs/\n.nova/\n.git/\n.idea/\n.fleet/\nnode_modules/\npublic/assets/modules/\n.forge-deployment-state.json\n.env\n"
        );

        file_put_contents(
            "{$dir}/.gitignore",
            ".env\n!.env.example\n.DS_Store\n/vendor/\n/node_modules/\n*.zip\n.idea/\n.vscode/\n"
        );

        file_put_contents("{$dir}/app/.gitignore", "");
        file_put_contents("{$dir}/CHANGELOG.md", "# Changelog\n\nAll notable changes to this blueprint will be documented in this file.\n");
        file_put_contents("{$dir}/README.md", "# {$blueprintNameKebab} Blueprint\n\n{$description}\n");
        file_put_contents("{$dir}/LICENSE", "MIT License\n");
        file_put_contents("{$dir}/LICENSE-MIT.txt", "MIT License\n");
    }
}
