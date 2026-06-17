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
    command: 'dev:starter:scaffold',
    description: 'Scaffold a new starter source template',
    usage: 'dev:starter:scaffold --name=starter-name [--source=/path/to/output]',
    examples: [
        'dev:starter:scaffold --name=my-starter',
        'dev:starter:scaffold --name=api-starter',
        'dev:starter:scaffold --name=custom --source=./my-custom-starter',
    ]
)]
final class StarterScaffoldCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    #[Arg(name: 'name', description: 'Starter name in kebab-case', required: true)]
    private ?string $name = null;

    #[Arg(name: 'source', description: 'Output path for the starter source (default: ./starters/<name>)', required: false)]
    private ?string $source = null;

    public function __construct(
        private readonly TemplateGenerator $templateGenerator
    ) {
    }

    public function execute(array $args): int
    {
        $this->wizard($args);

        if (!$this->name) {
            $this->error('Starter name is required.');
            return 1;
        }

        $starterNameKebab = self::toKebabCase($this->name);
        $starterDir = $this->source ?? BASE_PATH . "/starter-templates/{$starterNameKebab}";

        if (is_dir($starterDir)) {
            $this->error("Starter '{$starterNameKebab}' already exists at: {$starterDir}");
            return 1;
        }

        $displayName = $this->templateGenerator->askQuestion(
            'Starter display name: ',
            $this->toPascalCase($starterNameKebab) . ' Starter'
        );

        $description = $this->templateGenerator->askQuestion(
            'Description: ',
            ''
        );

        $engineVersion = $this->templateGenerator->askQuestion(
            'Kernel version (e.g., latest, 5.0.2): ',
            'latest'
        );

        $this->info("Scaffolding starter '{$starterNameKebab}'...");

        $dirs = [
            "{$starterDir}/app",
            "{$starterDir}/config",
            "{$starterDir}/public",
            "{$starterDir}/storage",
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        file_put_contents(
            "{$starterDir}/forge.json",
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
            "{$starterDir}/index.php",
            "<?php\n\nhttp_response_code(403);\necho \"Access denied.\";\nexit();\n"
        );

        file_put_contents(
            "{$starterDir}/forge.php",
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
            "{$starterDir}/public/index.php",
            <<<'PHP'
<?php

declare(strict_types=1);

define("BASE_PATH", dirname(__DIR__));

require_once BASE_PATH . "/kernel/Core/Support/helpers.php";
require BASE_PATH . "/kernel/Core/Autoloader.php";

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
            "{$starterDir}/public/.htaccess",
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

        $this->writeConfigFiles($starterDir);
        $this->writeEnvFiles($starterDir);
        $this->writeMetaFiles($starterDir);

        $installSource = BASE_PATH . '/starter-templates/blank/install.php';
        if (file_exists($installSource)) {
            copy($installSource, "{$starterDir}/install.php");
        } else {
            file_put_contents(
                "{$starterDir}/install.php",
                file_get_contents(BASE_PATH . '/forge-starter/install.php')
            );
        }

        $relativePath = str_replace(BASE_PATH . '/', '', $starterDir);
        $this->success("Starter '{$starterNameKebab}' scaffolded successfully at: {$starterDir}");
        $this->info("Next steps:");
        $this->info("  1. Edit {$relativePath}/forge.json to add modules");
        $this->info("  2. php forge.php dev:starter:version --name={$starterNameKebab}");

        return 0;
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

    private function writeMetaFiles(string $dir): void
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
        file_put_contents("{$dir}/CHANGELOG.md", "# Changelog\n\nAll notable changes to this starter will be documented in this file.\n");
        file_put_contents("{$dir}/README.md", "# {$this->name} Starter\n\n{$description}\n");
        file_put_contents("{$dir}/LICENSE", "MIT License\n");
        file_put_contents("{$dir}/LICENSE-MIT.txt", "MIT License\n");
    }
}
