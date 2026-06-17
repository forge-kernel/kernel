<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Commands\Registry\RegistryInitCommand;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\ArchiveService;
use Forge\Core\Services\GitService;
use Forge\Core\Services\ManifestService;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\TemplateGenerator;
use Forge\Core\Services\VersionService;

#[Cli(
    command: 'dev:kernel:version',
    description: 'Create a new kernel version (zip, update manifest, commit)',
    usage: 'dev:kernel:version [--version=X.Y.Z] [--type=patch|minor|major]',
    examples: [
        'dev:kernel:version',
        'dev:kernel:version --type=minor',
        'dev:kernel:version --version=1.2.0',
    ]
)]
final class FrameworkVersionCommand extends Command
{
    use CliGenerator;

    #[Arg(name: 'version', description: 'Version number (e.g., 1.2.0)', required: false)]
    private ?string $version = null;

    #[Arg(name: 'type', description: 'Version increment type (patch/minor/major)', required: false)]
    private ?string $type = null;

    public function __construct(
        private readonly VersionService $versionService,
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

        if ($this->registryService->isRegistryDirectoryInitialized('framework')) {
            if (!$this->registryService->isRegistryConfigured('framework')) {
                $this->info('Framework registry directory exists with git repository. Using existing registry.');
            }
        } elseif (!$this->registryService->isRegistryConfigured('framework')) {
            $this->warning('Framework registry not found or not configured.');
            $proceed = $this->templateGenerator->askQuestion(
                'Would you like to initialize the framework registry now? (yes/no): ',
                'yes'
            );

            if (in_array(strtolower($proceed), ['yes', 'y', '1', 'true'], true)) {
                $this->info('Initializing framework registry...');
                $initCommand = new RegistryInitCommand($this->registryService, $this->templateGenerator);
                $initResult = $initCommand->execute(['--type=framework']);
                if ($initResult !== 0) {
                    return 1;
                }
            } else {
                $this->error('Cannot proceed without a framework registry.');
                $this->info('Run: php forge.php dev:registry:init --type=framework');
                return 1;
            }
        }

        if (!$this->registryService->validateRegistry('framework')) {
            $this->error('Framework registry validation failed.');
            return 1;
        }

        $currentVersion = $this->versionService->detectFrameworkVersion();
        $registryPath = $this->registryService->getRegistryPath('framework');
        $manifestPath = $registryPath . '/forge.json';
        $enginePath = BASE_PATH . '/kernel';

        if (!$this->version) {
            $manifest = $this->manifestService->readFrameworkManifest($manifestPath);
            $latestVersion = $manifest['versions']['latest'] ?? null;

            if ($latestVersion && $this->versionService->compareVersions($currentVersion, $latestVersion) <= 0) {
                if (!$this->type) {
                    $this->type = $this->templateGenerator->askQuestion(
                        'Version increment type (patch/minor/major): ',
                        'patch'
                    );
                }
                $this->version = $this->versionService->suggestNextVersion($latestVersion, $this->type);
            } else {
                $this->version = $currentVersion;
            }
        }

        if ($this->manifestService->frameworkVersionExists($manifestPath, $this->version)) {
            $this->error("Version {$this->version} already exists in manifest.");
            return 1;
        }

        $this->info("Updating framework version in source...");
        $this->versionService->updateFrameworkVersion($this->version);

        if ($this->gitService->isGitRepository(BASE_PATH)) {
            $versionFilePath = 'kernel/Core/Bootstrap/Version.php';
            $this->info("Committing version bump to main repository...");

            if ($this->gitService->addFile(BASE_PATH, $versionFilePath)) {
                if ($this->gitService->commitFile(BASE_PATH, $versionFilePath, "Bump framework version to v{$this->version}")) {
                    $this->success("Version bump committed to main repository.");
                } else {
                    $this->warning("Failed to commit version bump to main repository, but file was updated.");
                }
            } else {
                $this->warning("Failed to stage version file, but file was updated.");
            }
        } else {
            $this->info("Main repository is not a git repository. Version file updated but not committed.");
        }

        $versionsDir = $registryPath . '/versions';
        if (!is_dir($versionsDir)) {
            mkdir($versionsDir, 0755, true);
        }

        $zipPath = $versionsDir . "/{$this->version}.zip";

        $this->info("Creating ZIP archive...");
        if (!$this->archiveService->createZip($enginePath, $zipPath)) {
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
        $manifest = $this->manifestService->readFrameworkManifest($manifestPath) ?? [];

        if (!isset($manifest['versions'])) {
            $manifest['versions'] = [];
        }

        $manifest['versions'][$this->version] = [
            'download_url' => 'versions/' . $this->version . '.zip',
            'integrity' => $integrity,
            'release_date' => date('Y-m-d'),
            'release_notes_url' => 'https://github.com/forge-kernel/forge/blob/main/CHANGELOG.md',
            'require' => $manifest['require'] ?? ['php' => '>=8.3'],
        ];
        $manifest['versions']['latest'] = $this->version;

        if (!$this->manifestService->writeFrameworkManifest($manifestPath, $manifest)) {
            $this->error("Failed to write manifest.");
            return 1;
        }

        $changelogPath = BASE_PATH . '/CHANGELOG.md';
        $commitMessage = "Add framework version v{$this->version}";

        if (file_exists($changelogPath)) {
            $changelog = file_get_contents($changelogPath);
            $this->info("Recent CHANGELOG entries:");
            $this->line(substr($changelog, 0, 500));
        }

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

        $this->success("Framework version {$this->version} created successfully!");
        $this->info("ZIP file: {$zipPath}");
        $this->info("Manifest updated: {$manifestPath}");

        return 0;
    }
}
