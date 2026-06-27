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
    command: 'dev:blueprint:version',
    description: 'Create a new blueprint version (zip, update manifest, commit)',
    usage: 'dev:blueprint:version --name=blueprint-name [--version=X.Y.Z] [--source=/path/to/source]',
    examples: [
        'dev:blueprint:version --name=blank',
        'dev:blueprint:version --name=minimal --version=1.0.0',
        'dev:blueprint:version --name=custom --source=./my-blueprint',
    ]
)]
final class BlueprintVersionCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    #[Arg(name: 'name', description: 'Blueprint name in kebab-case', required: true)]
    private ?string $name = null;

    #[Arg(name: 'version', description: 'Version number (e.g., 1.0.0)', required: false)]
    private ?string $version = null;

    #[Arg(name: 'source', description: 'Path to blueprint source directory (default: ./blueprints/<name>)', required: false)]
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

        if ($this->registryService->isRegistryDirectoryInitialized('blueprint')) {
            if (!$this->registryService->isRegistryConfigured('blueprint')) {
                $this->info('Blueprint registry directory exists with git repository. Using existing registry.');
            }
        } elseif (!$this->registryService->isRegistryConfigured('blueprint')) {
            $this->warning('Blueprint registry not found or not configured.');
            $proceed = $this->templateGenerator->askQuestion(
                'Would you like to initialize the blueprint registry now? (yes/no): ',
                'yes'
            );

            if (in_array(strtolower($proceed), ['yes', 'y', '1', 'true'], true)) {
                $this->info('Initializing blueprint registry...');
                $initCommand = new BlueprintInitCommand($this->registryService, $this->templateGenerator);
                $initResult = $initCommand->execute([]);
                if ($initResult !== 0) {
                    return 1;
                }
            } else {
                $this->error('Cannot proceed without a blueprint registry.');
                $this->info('Run: php forge.php dev:blueprint:init');
                return 1;
            }
        }

        if (!$this->registryService->validateRegistry('blueprint')) {
            $this->error('Blueprint registry validation failed.');
            return 1;
        }

        if (!$this->name) {
            $this->error('Blueprint name is required.');
            return 1;
        }

        $blueprintNameKebab = self::toKebabCase($this->name);
        $sourceDir = $this->source ?? BASE_PATH . "/blueprint-templates/{$this->name}";

        if (!is_dir($sourceDir)) {
            $this->error("Blueprint source directory not found: {$sourceDir}");
            return 1;
        }

        // Determine if using structured format (base/ + options) or flat
        $actualSourceDir = $sourceDir;
        $baseDir = $sourceDir . '/base';
        if (is_dir($baseDir)) {
            $actualSourceDir = $sourceDir;
        }

        // Read forge.json from source for metadata
        $forgeJsonPath = (is_dir($baseDir) ? $baseDir : $sourceDir) . '/forge.json';
        $engineVersion = 'latest';
        $modules = [];

        if (file_exists($forgeJsonPath)) {
            $forgeConfig = json_decode(file_get_contents($forgeJsonPath), true);
            if ($forgeConfig) {
                $engineVersion = $forgeConfig['kernel']['version'] ?? 'latest';
                $modules = $forgeConfig['modules'] ?? [];
            }
        }

        // Read blueprint-config.json for configurable options (structured blueprints only)
        $configOptions = null;
        $configJsonPath = $sourceDir . '/blueprint-config.json';
        if (file_exists($configJsonPath)) {
            $configData = json_decode(file_get_contents($configJsonPath), true);
            if (isset($configData['options']) && !empty($configData['options'])) {
                $configOptions = $configData;
            }
        }

        $registryPath = $this->registryService->getRegistryPath('blueprint');
        $manifestPath = $registryPath . '/blueprints.json';

        if ($this->gitService->isGitRepository($registryPath)) {
            $this->info("Pulling latest changes from blueprint registry...");
            if (!$this->gitService->pull($registryPath, 'origin', 'main')) {
                $this->warning("Failed to pull latest changes from registry, but will proceed.");
            }
        }

        // Determine version
        $manifest = $this->readBlueprintManifest($manifestPath);
        if (!$this->version) {
            $latestVersion = $manifest[$blueprintNameKebab]['latest'] ?? null;

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

        if ($this->blueprintVersionExists($manifestPath, $blueprintNameKebab, $this->version)) {
            $this->error("Version {$this->version} already exists for blueprint '{$blueprintNameKebab}'.");
            return 1;
        }

        // Collect metadata — skip prompts if blueprint already in manifest
        $existingBlueprint = $manifest[$blueprintNameKebab] ?? null;
        if ($existingBlueprint) {
            $blueprintName = $existingBlueprint['name'];
            $description = $existingBlueprint['description'] ?? '';
            $postInstallCommands = $existingBlueprint['versions'][$existingBlueprint['latest']]['post_install'] ?? [];
        } else {
            $blueprintName = $this->templateGenerator->askQuestion(
                'Blueprint display name: ',
                $this->toPascalCase($blueprintNameKebab) . ' Blueprint'
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
        $versionDir = $registryPath . "/blueprints/{$blueprintNameKebab}/{$this->version}";
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
        $manifest = $this->readBlueprintManifest($manifestPath);

        if (!isset($manifest[$blueprintNameKebab])) {
            $manifest[$blueprintNameKebab] = [
                'name' => $blueprintName,
                'description' => $description,
                'latest' => $this->version,
                'versions' => [],
            ];
        }

        $versionEntry = [
            'url' => "{$blueprintNameKebab}/{$this->version}",
            'integrity' => $integrity,
            'kernel' => $engineVersion,
            'modules' => $modules,
            'post_install' => $postInstallCommands,
        ];

        if ($configOptions !== null) {
            $versionEntry['config'] = $configOptions;
        }

        $manifest[$blueprintNameKebab]['versions'][$this->version] = $versionEntry;
        $manifest[$blueprintNameKebab]['latest'] = $this->version;

        if (!$this->writeBlueprintManifest($manifestPath, $manifest)) {
            $this->error("Failed to write manifest.");
            return 1;
        }

        $commitMessage = "Add blueprint {$blueprintNameKebab} version v{$this->version}";

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

        $this->success("Blueprint {$blueprintNameKebab} version {$this->version} created successfully!");
        $this->info("ZIP file: {$zipPath}");
        $this->info("Manifest updated: {$manifestPath}");

        return 0;
    }

    private function readBlueprintManifest(string $manifestPath): array
    {
        $manifest = $this->manifestService->readModulesManifest($manifestPath);
        if (!is_array($manifest)) {
            return [];
        }
        return $manifest['blueprints'] ?? [];
    }

    private function writeBlueprintManifest(string $manifestPath, array $blueprintsData): bool
    {
        $data = ['blueprints' => $blueprintsData];
        return $this->manifestService->writeModulesManifest($manifestPath, $data);
    }

    private function blueprintVersionExists(string $manifestPath, string $blueprintName, string $version): bool
    {
        $manifest = $this->readBlueprintManifest($manifestPath);
        return isset($manifest[$blueprintName]['versions'][$version]);
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
