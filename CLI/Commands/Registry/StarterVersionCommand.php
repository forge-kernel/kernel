<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\ArchiveService;
use Forge\Core\Services\GitService;
use Forge\Core\Services\ManifestService;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\TemplateGenerator;
use Forge\Traits\StringHelper;

#[Cli(
    command: 'dev:starter:version',
    description: 'Create a new starter version (zip, update manifest, commit)',
    usage: 'dev:starter:version --name=starter-name [--version=X.Y.Z] [--source=/path/to/source]',
    examples: [
        'dev:starter:version --name=blank',
        'dev:starter:version --name=minimal --version=1.0.0',
        'dev:starter:version --name=custom --source=./my-starter',
    ]
)]
final class StarterVersionCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    #[Arg(name: 'name', description: 'Starter name in kebab-case', required: true)]
    private ?string $name = null;

    #[Arg(name: 'version', description: 'Version number (e.g., 1.0.0)', required: false)]
    private ?string $version = null;

    #[Arg(name: 'source', description: 'Path to starter source directory (default: ./starters/<name>)', required: false)]
    private ?string $source = null;

    public function __construct(
        private readonly ArchiveService $archiveService,
        private readonly ManifestService $manifestService,
        private readonly GitService $gitService,
        private readonly RegistryService $registryService,
        private readonly TemplateGenerator $templateGenerator
    ) {
    }

    public function execute(array $args): int
    {
        $this->wizard($args);

        if ($this->registryService->isRegistryDirectoryInitialized('starter')) {
            if (!$this->registryService->isRegistryConfigured('starter')) {
                $this->info('Starter registry directory exists with git repository. Using existing registry.');
            }
        } elseif (!$this->registryService->isRegistryConfigured('starter')) {
            $this->warning('Starter registry not found or not configured.');
            $proceed = $this->templateGenerator->askQuestion(
                'Would you like to initialize the starter registry now? (yes/no): ',
                'yes'
            );

            if (in_array(strtolower($proceed), ['yes', 'y', '1', 'true'], true)) {
                $this->info('Initializing starter registry...');
                $initCommand = new StarterInitCommand($this->registryService, $this->templateGenerator);
                $initResult = $initCommand->execute([]);
                if ($initResult !== 0) {
                    return 1;
                }
            } else {
                $this->error('Cannot proceed without a starter registry.');
                $this->info('Run: php forge.php dev:starter:init');
                return 1;
            }
        }

        if (!$this->registryService->validateRegistry('starter')) {
            $this->error('Starter registry validation failed.');
            return 1;
        }

        if (!$this->name) {
            $this->error('Starter name is required.');
            return 1;
        }

        $starterNameKebab = self::toKebabCase($this->name);
        $sourceDir = $this->source ?? BASE_PATH . "/starter-templates/{$this->name}";

        if (!is_dir($sourceDir)) {
            $this->error("Starter source directory not found: {$sourceDir}");
            return 1;
        }

        // Read forge.json from source for metadata
        $forgeJsonPath = $sourceDir . '/forge.json';
        $engineVersion = 'latest';
        $modules = [];

        if (file_exists($forgeJsonPath)) {
            $forgeConfig = json_decode(file_get_contents($forgeJsonPath), true);
            if ($forgeConfig) {
                $engineVersion = $forgeConfig['kernel']['version'] ?? 'latest';
                $modules = $forgeConfig['modules'] ?? [];
            }
        }

        $registryPath = $this->registryService->getRegistryPath('starter');
        $manifestPath = $registryPath . '/starters.json';

        if ($this->gitService->isGitRepository($registryPath)) {
            $this->info("Pulling latest changes from starter registry...");
            if (!$this->gitService->pull($registryPath, 'origin', 'main')) {
                $this->warning("Failed to pull latest changes from registry, but will proceed.");
            }
        }

        // Determine version
        $manifest = $this->readStarterManifest($manifestPath);
        if (!$this->version) {
            $latestVersion = $manifest[$starterNameKebab]['latest'] ?? null;

            if ($latestVersion) {
                $type = $this->templateGenerator->askQuestion(
                    'Version increment type (patch/minor/major): ',
                    'patch'
                );
                $this->version = $this->suggestNextVersion($latestVersion, $type);
            } else {
                $this->version = $this->templateGenerator->askQuestion(
                    'Initial version number: ',
                    '1.0.0'
                );
            }
        }

        if ($this->starterVersionExists($manifestPath, $starterNameKebab, $this->version)) {
            $this->error("Version {$this->version} already exists for starter '{$starterNameKebab}'.");
            return 1;
        }

        // Collect metadata — skip prompts if starter already in manifest
        $existingStarter = $manifest[$starterNameKebab] ?? null;
        if ($existingStarter) {
            $starterName = $existingStarter['name'];
            $description = $existingStarter['description'] ?? '';
            $postInstallCommands = $existingStarter['versions'][$existingStarter['latest']]['post_install'] ?? [];
        } else {
            $starterName = $this->templateGenerator->askQuestion(
                'Starter display name: ',
                $this->toPascalCase($starterNameKebab) . ' Starter'
            );

            $description = $this->templateGenerator->askQuestion(
                'Description: ',
                ''
            );

            $postInstallInput = $this->templateGenerator->askQuestion(
                'Post-install commands (comma-separated, leave empty for none): ',
                ''
            );
            $postInstallCommands = [];
            if (!empty(trim($postInstallInput))) {
                $postInstallCommands = array_map('trim', explode(',', $postInstallInput));
            }
        }

        // Create version directory in registry
        $versionDir = $registryPath . "/starters/{$starterNameKebab}/{$this->version}";
        if (!is_dir($versionDir)) {
            mkdir($versionDir, 0755, true);
        }

        $zipPath = $versionDir . "/{$this->version}.zip";

        $this->info("Creating ZIP archive...");
        if (!$this->archiveService->createZip($sourceDir, $zipPath)) {
            $this->error("Failed to create ZIP archive.");
            return 1;
        }

        $this->info("Calculating integrity hash...");
        $integrity = $this->archiveService->calculateIntegrity($zipPath);
        if (!$integrity) {
            $this->error("Failed to calculate integrity hash.");
            return 1;
        }

        $this->info("Updating manifest...");
        $manifest = $this->readStarterManifest($manifestPath);

        if (!isset($manifest[$starterNameKebab])) {
            $manifest[$starterNameKebab] = [
                'name' => $starterName,
                'description' => $description,
                'latest' => $this->version,
                'versions' => [],
            ];
        }

        $manifest[$starterNameKebab]['versions'][$this->version] = [
            'url' => "{$starterNameKebab}/{$this->version}",
            'integrity' => $integrity,
            'kernel' => $engineVersion,
            'modules' => $modules,
            'post_install' => $postInstallCommands,
        ];
        $manifest[$starterNameKebab]['latest'] = $this->version;

        if (!$this->writeStarterManifest($manifestPath, $manifest)) {
            $this->error("Failed to write manifest.");
            return 1;
        }

        $commitMessage = "Add starter {$starterNameKebab} version v{$this->version}";

        $customMessage = $this->templateGenerator->askQuestion(
            'Enter commit message (or press Enter for auto-generated): ',
            ''
        );

        if (!empty($customMessage)) {
            $commitMessage = $customMessage;
        }

        $this->info("Committing changes...");
        $this->gitService->addAll($registryPath);

        if (!$this->gitService->commit($registryPath, $commitMessage)) {
            $this->error("Failed to commit changes.");
            return 1;
        }

        $this->success("Starter {$starterNameKebab} version {$this->version} created successfully!");
        $this->info("ZIP file: {$zipPath}");
        $this->info("Manifest updated: {$manifestPath}");

        return 0;
    }

    private function readStarterManifest(string $manifestPath): array
    {
        $manifest = $this->manifestService->readModulesManifest($manifestPath);
        if (!is_array($manifest)) {
            return [];
        }
        return $manifest['starters'] ?? [];
    }

    private function writeStarterManifest(string $manifestPath, array $startersData): bool
    {
        $data = ['starters' => $startersData];
        return $this->manifestService->writeModulesManifest($manifestPath, $data);
    }

    private function starterVersionExists(string $manifestPath, string $starterName, string $version): bool
    {
        $manifest = $this->readStarterManifest($manifestPath);
        return isset($manifest[$starterName]['versions'][$version]);
    }

    private function suggestNextVersion(string $currentVersion, string $type): string
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $currentVersion, $matches)) {
            return $currentVersion;
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        $patch = (int) $matches[3];

        return match ($type) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            'patch' => $major . '.' . $minor . '.' . ($patch + 1),
            default => $currentVersion,
        };
    }
}
