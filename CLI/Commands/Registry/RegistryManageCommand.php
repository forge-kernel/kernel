<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Commands\Registry\RegistryInitCommand;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Config\Environment;
use Forge\Core\Services\ArchiveService;
use Forge\Core\Services\GitService;
use Forge\Core\Services\ManifestService;
use Forge\Core\Services\ModuleMetadataService;
use Forge\Core\Services\RegistryReadmeService;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\TemplateGenerator;
use Forge\Core\Services\VersionService;
use Forge\Core\Structure\StructureResolver;
use Forge\Traits\StringHelper;

#[Cli(
    command: 'dev:registry:manage',
    description: 'Create module version, update manifest, commit, update README, and publish to remote',
    usage: 'dev:registry:manage [--name=ModuleName] [--version=X.Y.Z] [--type=patch|minor|major]',
    examples: [
        'dev:registry:manage --name=ForgeLogger',
        'dev:registry:manage --name=ForgeLogger --type=minor',
        'dev:registry:manage --name=ForgeLogger --version=1.2.0',
    ]
)]
final class RegistryManageCommand extends Command
{
    use CliGenerator;
    use StringHelper;

    #[Arg(name: 'name', description: 'Module name in PascalCase', required: true)]
    private ?string $name = null;

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
        private readonly TemplateGenerator $templateGenerator,
        private readonly RegistryReadmeService $readmeService,
        private readonly ModuleMetadataService $metadataService
    ) {
    }

    public function execute(array $args): int
    {
        $this->wizard($args);

        if ($this->registryService->isRegistryDirectoryInitialized('modules')) {
            if (!$this->registryService->isRegistryConfigured('modules')) {
                $this->info('Modules registry directory exists with git repository. Using existing registry.');
            }
        } elseif (!$this->registryService->isRegistryConfigured('modules')) {
            $this->warning('Modules registry not found or not configured.');
            $proceed = $this->templateGenerator->askQuestion(
                'Would you like to initialize the modules registry now? (yes/no): ',
                'yes'
            );

            if (in_array(strtolower($proceed), ['yes', 'y', '1', 'true'], true)) {
                $this->info('Initializing modules registry...');
                $initCommand = new RegistryInitCommand($this->registryService, $this->templateGenerator);
                $initResult = $initCommand->execute(['--type=modules']);
                if ($initResult !== 0) {
                    return 1;
                }
            } else {
                $this->error('Cannot proceed without a modules registry.');
                $this->info('Run: php forge.php dev:registry:init --type=modules');
                return 1;
            }
        }

        if (!$this->registryService->validateRegistry('modules')) {
            $this->error('Modules registry validation failed.');
            return 1;
        }

        if (!$this->name) {
            $this->error('Module name is required.');
            return 1;
        }

        $moduleNameKebab = self::toKebabCase($this->name);
        $modulesRoot = StructureResolver::resolveModulesRoot();
        $modulePath = BASE_PATH . "/{$modulesRoot}/{$this->name}";

        if (!is_dir($modulePath)) {
            $this->error("Module directory not found: {$modulePath}");
            return 1;
        }

        $currentVersion = $this->versionService->detectModuleVersion($this->name);
        $registryPath = $this->registryService->getRegistryPath('modules');
        $manifestPath = $registryPath . '/modules.json';

        if (!$this->version) {
            $manifest = $this->manifestService->readModulesManifest($manifestPath);
            $latestVersion = $manifest[$moduleNameKebab]['latest'] ?? null;

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

        if ($this->manifestService->moduleVersionExists($manifestPath, $moduleNameKebab, $this->version)) {
            $this->error("Version {$this->version} already exists in manifest.");
            return 1;
        }

        $this->info("Updating module version in source files...");
        $this->versionService->updateModuleVersion($this->name, $this->version);

        if ($this->gitService->isGitRepository(BASE_PATH)) {
            $entryFilePath = StructureResolver::findModuleEntryFileStatic(
                BASE_PATH . '/' . $modulesRoot,
                $this->name
            );
            $entryFilePath = $entryFilePath !== null
                ? str_replace(BASE_PATH . '/', '', $entryFilePath)
                : null;

            if ($entryFilePath) {
                $this->info("Committing module entry file version update to main repository...");

                if ($this->gitService->addFile(BASE_PATH, $entryFilePath)) {
                    if ($this->gitService->commitFile(BASE_PATH, $entryFilePath, "Bump {$this->name} module version to v{$this->version}")) {
                        $this->success("Module entry file version update committed to main repository.");
                    } else {
                        $this->warning("Failed to commit module entry file version update, but file was updated.");
                    }
                } else {
                    $this->warning("Failed to stage module entry file, but file was updated.");
                }
            }
        } else {
            $this->info("Main repository is not a git repository. Module entry file updated but not committed.");
        }

        $versionDir = $registryPath . "/modules/{$moduleNameKebab}/{$this->version}";
        if (!is_dir($versionDir)) {
            mkdir($versionDir, 0755, true);
        }

        $zipPath = $versionDir . "/{$this->version}.zip";

        $this->info("Creating ZIP archive...");
        if (!$this->archiveService->createZip($modulePath, $zipPath)) {
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
        $manifest = $this->manifestService->readModulesManifest($manifestPath) ?? [];

        if (!isset($manifest[$moduleNameKebab])) {
            $manifest[$moduleNameKebab] = [
                'latest' => $this->version,
                'versions' => [],
            ];
        }

        $manifest[$moduleNameKebab]['versions'][$this->version] = [
            'description' => "Version {$this->version} of {$moduleNameKebab}",
            'url' => "{$moduleNameKebab}/{$this->version}",
            'integrity' => $integrity,
        ];
        $manifest[$moduleNameKebab]['latest'] = $this->version;

        if (!$this->manifestService->writeModulesManifest($manifestPath, $manifest)) {
            $this->error("Failed to write manifest.");
            return 1;
        }

        $changelogPath = $modulePath . '/CHANGELOG.md';
        $commitMessage = "Add module {$moduleNameKebab} version v{$this->version}";

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

        $this->info("Updating README module list...");
        $entryFile = $this->findModuleEntryFile(BASE_PATH . '/' . $modulesRoot, $this->name);
        if ($entryFile) {
            $metadata = $this->metadataService->extractFromFile($entryFile);
            if ($metadata) {
                $metadata['version'] = $this->version;
                $readmePath = $registryPath . '/README.md';
                if (!$this->readmeService->updateModuleInTable($readmePath, $this->name, $metadata)) {
                    $modules = $this->readmeService->readAllModulesFromRegistry($registryPath, $manifestPath, BASE_PATH . '/' . $modulesRoot);
                    $this->readmeService->updateModuleListTable($readmePath, $modules);
                }
            }
        }

        $this->info("Committing changes...");
        $this->gitService->addAll($registryPath);

        if (!$this->gitService->commit($registryPath, $commitMessage)) {
            $this->error("Failed to commit changes.");
            return 1;
        }

        $config = $this->registryService->getRegistryConfig('modules');
        if (!$config) {
            $this->info('Auto-detecting modules registry configuration from git repository...');
            $config = $this->registryService->getRegistryConfigOrDetect('modules');
        }

        if (!$config) {
            $this->error('Registry configuration not found and could not be auto-detected.');
            $this->info('Run: php forge.php dev:registry:init --type=modules');
            return 1;
        }

        if (!isset($config['url']) || $config['url'] === null) {
            $this->error('Registry remote URL not found. Cannot publish without a remote repository.');
            $this->info('Run: php forge.php dev:registry:config --type=modules --url=<repository_url>');
            return 1;
        }

        $branch = $config['branch'] ?? 'main';
        $env = Environment::getInstance();
        $token = $config['private'] ? $env->get('GITHUB_TOKEN') : null;

        $this->info("Pushing changes to remote repository...");

        if (!$this->gitService->push($registryPath, $branch, $token)) {
            $this->error("Failed to push changes.");
            return 1;
        }

        $this->success("Module {$moduleNameKebab} version {$this->version} created and published successfully!");
        $this->info("ZIP file: {$zipPath}");
        $this->info("Manifest updated: {$manifestPath}");

        return 0;
    }

    private function findModuleEntryFile(string $basePath, string $moduleName): ?string
    {
        return StructureResolver::findModuleEntryFileStatic($basePath, $moduleName);
    }
}
