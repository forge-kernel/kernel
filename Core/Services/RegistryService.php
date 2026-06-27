<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\Config\Environment;
use Forge\Core\DI\Attributes\Service;
use Forge\Core\Helpers\FileExistenceCache;

#[Service]
final class RegistryService
{
    public function __construct(
        private readonly GitService $gitService
    ) {
    }

    private function getRegistryConfigData(): array
    {
        $configPath = BASE_PATH . '/config/registry.php';
        if (FileExistenceCache::exists($configPath)) {
            return require $configPath;
        }
        return [];
    }

    public function isRegistryConfigured(string $type): bool
    {
        $defaultPath = BASE_PATH . "/{$type}-registry";

        $config = $this->getRegistryConfigData();
        $registryConfig = $config[$type] ?? null;

        if ($registryConfig && isset($registryConfig['path'])) {
            $path = $registryConfig['path'];
        } else {
            $path = $defaultPath;
        }

        if (!FileExistenceCache::isDir($path)) {
            return false;
        }

        if (!$this->gitService->isGitRepository($path)) {
            return false;
        }

        return true;
    }

    public function isRegistryDirectoryInitialized(string $type): bool
    {
        $defaultPath = BASE_PATH . "/{$type}-registry";

        if (!is_dir($defaultPath)) {
            return false;
        }

        return $this->gitService->isGitRepository($defaultPath);
    }

    public function validateRegistry(string $type): bool
    {
        if (!$this->isRegistryConfigured($type) && !$this->isRegistryDirectoryInitialized($type)) {
            return false;
        }

        $path = $this->getRegistryPath($type);

        return match ($type) {
            'modules' => FileExistenceCache::exists($path . '/modules.json'),
            'blueprint' => FileExistenceCache::exists($path . '/blueprints.json'),
            default => FileExistenceCache::exists($path . '/forge.json'),
        };
    }

    public function getRegistryPath(string $type): string
    {
        $config = $this->getRegistryConfigData();
        $registryConfig = $config[$type] ?? [];
        if (isset($registryConfig['path'])) {
            return $registryConfig['path'];
        }

        return BASE_PATH . "/{$type}-registry";
    }

    public function getRegistryConfig(string $type): ?array
    {
        $config = $this->getRegistryConfigData();
        return $config[$type] ?? null;
    }

    public function getRegistryConfigOrDetect(string $type): ?array
    {
        $config = $this->getRegistryConfig($type);

        if ($config !== null) {
            return $config;
        }

        $defaultPath = BASE_PATH . "/{$type}-registry";

        if (!FileExistenceCache::isDir($defaultPath) || !$this->gitService->isGitRepository($defaultPath)) {
            return null;
        }

        $url = $this->gitService->getRemoteUrl($defaultPath, 'origin');
        if (!$url) {
            return [
                'url' => null,
                'branch' => 'main',
                'private' => false,
                'path' => $defaultPath,
            ];
        }

        $branch = $this->gitService->getCurrentBranch($defaultPath);
        if (!$branch) {
            $branch = $this->gitService->getRemoteBranch($defaultPath, 'origin');
        }
        if (!$branch) {
            $branch = 'main';
        }

        $isPrivate = $this->detectIfPrivate($url);

        $detectedConfig = [
            'url' => $url,
            'branch' => $branch,
            'private' => $isPrivate,
            'path' => $defaultPath,
        ];

        return $detectedConfig;
    }

    private function detectIfPrivate(string $url): bool
    {
        if (str_contains($url, '@')) {
            return true;
        }

        if (preg_match('/github\.com[:/]([^\/]+)\/([^\/\.]+)/', $url, $matches)) {
            return false;
        }

        return false;
    }

    public function initializeRegistry(
        string $type,
        string $url,
        string $branch,
        bool $isPrivate
    ): void {
        $path = BASE_PATH . "/{$type}-registry";

        if (!FileExistenceCache::isDir($path)) {
            mkdir($path, 0755, true);
        }

        $this->createInitialStructure($type, $path);

        if (!$this->gitService->isGitRepository($path)) {
            $this->gitService->init($path);
        }

        $this->gitService->setRemote($path, 'origin', $url);

        $env = Environment::getInstance();
        $token = $isPrivate ? $env->get('GITHUB_TOKEN') : null;
        if ($token) {
            $this->gitService->setAuth($path, $token);
        }

        $this->gitService->addAll($path);
        $this->gitService->commit($path, 'Initial registry structure');

        if ($token) {
            $this->gitService->push($path, $branch, $token);
        }

        $this->saveConfig($type, $url, $branch, $isPrivate, $path);
    }

    private function createInitialStructure(string $type, string $path): void
    {
        match ($type) {
            'framework' => $this->createFrameworkStructure($path),
            'blueprint' => $this->createBlueprintsStructure($path),
            default => $this->createModulesStructure($path),
        };
    }

    private function createFrameworkStructure(string $path): void
    {
        if (!FileExistenceCache::isDir($path . '/versions')) {
            mkdir($path . '/versions', 0755, true);
        }

        if (!file_exists($path . '/forge.json')) {
            $manifest = [
                'name' => 'forge-kernel/kernel',
                'version' => '0.1.0',
                'manifest_version' => '1.0',
                'description' => 'Forge PHP Kernel',
                'homepage' => 'https://forge-kernel.github.io/',
                'repository' => '',
                'require' => [
                    'php' => '>=8.3'
                ],
                'authors' => [],
                'versions' => [
                    'latest' => '0.1.0'
                ]
            ];
            file_put_contents($path . '/forge.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (!file_exists($path . '/README.md')) {
            file_put_contents($path . '/README.md', "# Framework Registry\n\nFramework version registry.\n");
        }

        if (!file_exists($path . '/LICENSE')) {
            file_put_contents($path . '/LICENSE', "MIT License\n");
        }
    }

    private function createModulesStructure(string $path): void
    {
        if (!FileExistenceCache::isDir($path . '/modules')) {
            mkdir($path . '/modules', 0755, true);
        }

        $pathsToCheck = [
            $path . '/modules.json',
            $path . '/README.md',
            $path . '/CHANGELOG.md',
            $path . '/LICENSE'
        ];

        FileExistenceCache::preload($pathsToCheck);

        if (!FileExistenceCache::exists($path . '/modules.json')) {
            file_put_contents($path . '/modules.json', "{\n}\n");
        }

        if (!FileExistenceCache::exists($path . '/README.md')) {
            file_put_contents($path . '/README.md', "# Modules Registry\n\nModule version registry.\n");
        }

        if (!FileExistenceCache::exists($path . '/CHANGELOG.md')) {
            file_put_contents($path . '/CHANGELOG.md', "# Changelog\n\nAll notable changes to this registry will be documented in this file.\n");
        }

        if (!FileExistenceCache::exists($path . '/LICENSE')) {
            file_put_contents($path . '/LICENSE', "MIT License\n");
        }
    }

    private function createBlueprintsStructure(string $path): void
    {
        if (!FileExistenceCache::isDir($path . '/blueprints')) {
            mkdir($path . '/blueprints', 0755, true);
        }

        if (!FileExistenceCache::exists($path . '/blueprints.json')) {
            $manifest = ['blueprints' => (object) []];
            file_put_contents($path . '/blueprints.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (!FileExistenceCache::exists($path . '/README.md')) {
            file_put_contents($path . '/README.md', "# Blueprints Registry\n\nBlueprint version registry.\n");
        }

        if (!FileExistenceCache::exists($path . '/CHANGELOG.md')) {
            file_put_contents($path . '/CHANGELOG.md', "# Changelog\n\nAll notable changes to this registry will be documented in this file.\n");
        }

        if (!FileExistenceCache::exists($path . '/LICENSE')) {
            file_put_contents($path . '/LICENSE', "MIT License\n");
        }
    }

    private function saveConfig(string $type, string $url, string $branch, bool $isPrivate, string $path): void
    {
        $configPath = BASE_PATH . '/config/registry.php';
        $config = [];

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($configPath, true);
        }
        FileExistenceCache::clearPath($configPath);
        if (file_exists($configPath)) {
            $config = require $configPath;
            FileExistenceCache::clearPath($configPath);
        }

        $config[$type] = [
            'url' => $url,
            'branch' => $branch,
            'private' => $isPrivate,
            'path' => $path,
        ];

        $content = "<?php\n\nreturn [\n";
        foreach ($config as $key => $entry) {
            $relativePath = str_replace(BASE_PATH, '', $entry['path']);
            $content .= "    '{$key}' => [\n";
            $content .= "        'url' => '" . addslashes($entry['url']) . "',\n";
            $content .= "        'branch' => '" . addslashes($entry['branch']) . "',\n";
            $content .= "        'private' => " . ($entry['private'] ? 'true' : 'false') . ",\n";
            $content .= "        'path' => BASE_PATH . '" . addslashes($relativePath) . "',\n";
            $content .= "    ],\n";
        }
        $content .= "];\n";

        file_put_contents($configPath, $content);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($configPath, true);
        }
    }
}
