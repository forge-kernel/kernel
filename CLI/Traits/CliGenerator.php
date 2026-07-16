<?php
declare(strict_types=1);

namespace Forge\CLI\Traits;

use Forge\Core\DI\Container;
use Forge\Core\Structure\StructureResolver;

trait CliGenerator
{
    use OutputHelper;
    use Wizard;

    protected function generateFromStub(
        string $stub,
        string $targetPath,
        array $tokens,
        bool $force = false
    ): void {
        if (is_file($targetPath) && !$force) {
            $this->error("File exists: $targetPath  (--force to overwrite)");
            exit(1);
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir))
            mkdir($dir, 0755, true);

        $content = file_get_contents($this->stubPath($stub));
        $content = strtr($content, $tokens);

        file_put_contents($targetPath, $content);
        $this->success("Created: $targetPath");
    }

    private function stubPath(string $stub): string
    {
        return BASE_PATH . "/kernel/resources/stubs/$stub.stub";
    }

    private function controllerPath(): string
    {
        return $this->resolve('controller')['path'];
    }

    private function resolve(string $type): array
    {
        $structureResolver = Container::getInstance()->has(StructureResolver::class)
            ? Container::getInstance()->get(StructureResolver::class)
            : null;

        $modulesRoot = $structureResolver?->getModulesRoot() ?? StructureResolver::resolveModulesRoot();

        $map = [
            'controller' => ['controllers', 'Controller.php'],
            'middleware' => ['middlewares', 'Middleware.php'],
            'event' => ['events', 'Event.php'],
            'migration' => ['migrations', 'Table.php'],
            'seeder' => ['seeders', 'Seeder.php'],
            'model' => ['models', 'Model.php'],
            'view' => ['views', '/pages'],
            'command' => ['commands', 'Command.php'],
            'service' => ['injectable', 'Service.php'],
            'enum' => ['Enums', 'Enum.php'],
            'trait' => ['Traits', 'Trait.php'],
            'test' => ['tests', 'Test.php'],
            'component' => ['components', 'Component.php'],
            'component-view' => ['components', 'View.php'],
            'component-dto' => ['components', 'Dto.php'],
            'component-view-only' => ['components', '.php'],
            'layout' => ['views', '/layouts'],
            'dto' => ['dto', 'DTO.php'],
        ];

        if (!isset($map[$type])) {
            throw new \InvalidArgumentException("Unknown type: $type");
        }

        [$structureKey, $suffix] = $map[$type];
        $subPath = $this->normalizePath($this->path ?? '');

        if ($structureResolver && in_array($structureKey, ['controllers', 'middlewares', 'events', 'migrations', 'seeders', 'models', 'commands', 'injectable', 'tests', 'dto'])) {
            try {
                if ($this->type === 'app') {
                    $structurePath = $structureResolver->getAppPath($structureKey);
                    $baseDir = BASE_PATH . '/' . $structurePath;
                } else {
                    $structurePath = $structureResolver->getModulePath($this->module, $structureKey);
                    $baseDir = BASE_PATH . "/{$modulesRoot}/{$this->module}/{$structurePath}";
                }
            } catch (\InvalidArgumentException $e) {
                $baseDir = $this->getFallbackPath($type);
            }
        } elseif ($structureResolver && in_array($structureKey, ['views', 'components'])) {
            try {
                if ($this->type === 'app') {
                    if ($type === 'view') {
                        $structurePath = $structureResolver->getAppPath('pages');
                        $baseDir = BASE_PATH . '/' . $structurePath;
                    } elseif ($type === 'layout') {
                        $structurePath = $structureResolver->getAppPath('layouts');
                        $baseDir = BASE_PATH . '/' . $structurePath;
                    } elseif (str_starts_with($type, 'component')) {
                        $structurePath = $structureResolver->getAppPath('components');
                        $baseDir = BASE_PATH . '/' . $structurePath;
                    } else {
                        $structurePath = $structureResolver->getAppPath($structureKey);
                        $baseDir = BASE_PATH . '/' . $structurePath;
                    }
                } else {
                    if ($type === 'view') {
                        $structurePath = $structureResolver->getModulePath($this->module, 'pages');
                        $baseDir = BASE_PATH . "/{$modulesRoot}/{$this->module}/{$structurePath}";
                    } elseif ($type === 'layout') {
                        $structurePath = $structureResolver->getModulePath($this->module, 'layouts');
                        $baseDir = BASE_PATH . "/{$modulesRoot}/{$this->module}/{$structurePath}";
                    } elseif (str_starts_with($type, 'component')) {
                        $structurePath = $structureResolver->getModulePath($this->module, 'components');
                        $baseDir = BASE_PATH . "/{$modulesRoot}/{$this->module}/{$structurePath}";
                    } else {
                        $structurePath = $structureResolver->getModulePath($this->module, $structureKey);
                        $baseDir = BASE_PATH . "/{$modulesRoot}/{$this->module}/{$structurePath}";
                    }
                }
            } catch (\InvalidArgumentException $e) {
                $baseDir = $this->getFallbackPath($type);
            }
        } else {
            $baseDir = $this->getFallbackPath($type);
        }

        if ($subPath !== '')
            $baseDir .= '/' . $subPath;

        if ($type === 'migration' || $type === 'seeder') {
            $parsed = $this->parseFolderFilenameForClass($this->name);
            $className = $this->toPascalCase($parsed['filename']);
            $folder = $parsed['folder'];
            $file = date("Y_m_d_His") . "_" . $className . ($type === 'migration' ? "Table.php" : "Seeder.php");
            if ($folder !== '') {
                $file = $folder . '/' . $file;
            }
        } elseif ($type === 'view') {
            $file = $this->parseFolderFilename($this->name, '/index.php');
        } elseif ($type === 'component' || $type === 'component-dto' || $type === 'component-view') {
            $parsed = $this->parseFolderFilenameForClass($this->name);
            $className = $this->toPascalCase($parsed['filename']);
            $folder = $parsed['folder'];
            $file = $className . $suffix;
            if ($folder !== '') {
                $file = $folder . '/' . $file;
            }
        } elseif ($type === 'layout' || $type === 'component-view-only') {
            $file = $this->parseFolderFilename($this->name, $suffix);
        } else {
            $parsed = $this->parseFolderFilenameForClass($this->name);
            $className = $parsed['filename'];
            $folder = $parsed['folder'];
            $file = $className . $suffix;
            if ($folder !== '') {
                $file = $folder . '/' . $file;
            }
        }

        $baseNamespace = $this->type === 'app'
            ? 'App'
            : "Modules\\{$this->module}";

        $parsed = $this->parseFolderFilenameForClass($this->name);
        $nameFolder = $parsed['folder'];

        if ($type === 'component') {
            $namespace = $baseNamespace . '\\View\\Components';
            if ($subPath !== '') {
                $namespace .= '\\' . str_replace('/', '\\', $subPath);
            }
            if ($nameFolder !== '') {
                $namespace .= '\\' . str_replace('/', '\\', $nameFolder);
            }
        } else {
            $fallbackMap = [
                'controller' => 'controllers',
                'middleware' => 'middlewares',
                'event' => 'events',
                'migration' => 'migrations',
                'seeder' => 'seeders',
                'model' => 'models',
                'view' => 'views',
                'command' => 'commands',
                'service' => 'injectable',
                'enum' => 'Enums',
                'trait' => 'Traits',
                'test' => 'tests',
                'component' => 'components',
                'component-view' => 'components',
                'component-dto' => 'components',
                'component-view-only' => 'components',
                'layout' => 'layouts',
                'dto' => 'dto',
            ];

            $structureKeyForNs = $fallbackMap[$type] ?? '';

            if ($structureResolver && in_array($structureKey, ['controllers', 'middlewares', 'events', 'migrations', 'seeders', 'models', 'commands', 'injectable', 'tests', 'dto', 'views', 'components'])) {
                try {
                    if ($this->type === 'app') {
                        $nsKey = in_array($structureKey, ['views', 'components']) ? ($type === 'view' ? 'views' : ($type === 'layout' ? 'layouts' : 'components')) : $structureKey;
                        $structurePath = $structureResolver->getAppPath($nsKey);
                        $subdir = preg_replace('#^app/#', '', $structurePath);
                    } else {
                        $nsKey = in_array($structureKey, ['views', 'components']) ? ($type === 'view' ? 'views' : ($type === 'layout' ? 'layouts' : 'components')) : $structureKey;
                        $structurePath = $structureResolver->getModulePath($this->module, $nsKey);
                        $subdir = preg_replace('#^src/#', '', $structurePath);
                    }
                } catch (\InvalidArgumentException $e) {
                }
            }

            if (!isset($subdir)) {
                $subdir = $fallbackMap[$structureKeyForNs] ?? '';
            }

            $namespace = $baseNamespace . '\\' . str_replace('/', '\\', $subdir);
            if ($subPath !== '') {
                $namespace .= '\\' . str_replace('/', '\\', $subPath);
            }
            if ($nameFolder !== '') {
                $namespace .= '\\' . str_replace('/', '\\', $nameFolder);
            }
        }

        return [
            'path' => "$baseDir/$file",
            'namespace' => $namespace,
        ];
    }

    private function parseFolderFilename(string $name, string $suffix): string
    {
        $parts = explode('/', $name);

        if (count($parts) === 1) {
            return $this->slugify($parts[0]) . $suffix;
        }

        $filename = array_pop($parts);
        $normalizedParts = array_map(fn($part) => $this->slugify($part), $parts);
        $folder = implode('/', $normalizedParts);
        $normalizedFilename = $this->slugify($filename);

        return $folder . '/' . $normalizedFilename . $suffix;
    }

    protected function parseFolderFilenameForClass(string $name): array
    {
        $parts = explode('/', $name);

        if (count($parts) === 1) {
            return ['folder' => '', 'filename' => $this->toPascalCase($parts[0])];
        }

        $filename = array_pop($parts);
        $normalizedParts = array_map(function ($part) {
            $slugified = $this->slugify($part);
            return ucfirst($slugified);
        }, $parts);
        $folder = implode('/', $normalizedParts);
        $pascalCaseFilename = $this->toPascalCase($filename);

        return ['folder' => $folder, 'filename' => $pascalCaseFilename];
    }

    private function normalizePath(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '')
            return '';
        return trim(str_replace(['\\', '//'], '/', $path), '/');
    }

    private function controllerNamespace(): string
    {
        return $this->resolve('controller')['namespace'];
    }

    private function middlewarePath(): string
    {
        return $this->resolve('middleware')['path'];
    }

    private function middlewareNamespace(): string
    {
        return $this->resolve('middleware')['namespace'];
    }

    private function enumNamespace(): string
    {
        return $this->resolve('enum')['namespace'];
    }

    private function traitNamespace(): string
    {
        return $this->resolve('trait')['namespace'];
    }

    private function testNamespace(): string
    {
        return $this->resolve('test')['namespace'];
    }

    private function componentNamespace(): string
    {
        return $this->resolve('component')['namespace'];
    }

    private function componentPath(): string
    {
        return $this->resolve('component')['path'];
    }

    private function componentDtoPath(): string
    {
        return $this->resolve('component-dto')['path'];
    }

    private function testPath(): string
    {
        return $this->resolve('test')['path'];
    }

    private function traitPath(): string
    {
        return $this->resolve('trait')['path'];
    }

    private function enumPath(): string
    {
        return $this->resolve('enum')['path'];
    }

    private function eventPath(): string
    {
        return $this->resolve('event')['path'];
    }

    private function commandPath(): string
    {
        return $this->resolve('command')['path'];
    }

    private function eventNamespace(): string
    {
        return $this->resolve('event')['namespace'];
    }

    private function modelNamespace(): string
    {
        return $this->resolve('model')['namespace'];
    }

    private function dtoNamespace(): string
    {
        return $this->resolve('dto')['namespace'];
    }

    private function serviceNamespace(): string
    {
        return $this->resolve('service')['namespace'];
    }

    private function migrationPath(): string
    {
        return $this->resolve('migration')['path'];
    }

    private function dtoPath(): string
    {
        return $this->resolve('dto')['path'];
    }

    private function servicePath(): string
    {
        return $this->resolve('service')['path'];
    }

    private function seederPath(): string
    {
        return $this->resolve('seeder')['path'];
    }

    private function modelPath(): string
    {
        return $this->resolve('model')['path'];
    }

    private function viewPath(): string
    {
        return $this->resolve('view')['path'];
    }

    protected function viewPathForName(string $viewName): string
    {
        $subPath = $this->normalizePath($this->path ?? '');

        $structureResolver = Container::getInstance()->has(StructureResolver::class)
            ? Container::getInstance()->get(StructureResolver::class)
            : null;

        $modulesRoot = $structureResolver?->getModulesRoot() ?? StructureResolver::resolveModulesRoot();

        if ($structureResolver) {
            try {
                if ($this->type === 'app') {
                    $pagesPath = $structureResolver->getAppPath('pages');
                    $baseDir = BASE_PATH . '/' . $pagesPath;
                } else {
                    $pagesPath = $structureResolver->getModulePath($this->module, 'pages');
                    $baseDir = BASE_PATH . "/{$modulesRoot}/{$this->module}/{$pagesPath}";
                }
            } catch (\InvalidArgumentException $e) {
                $baseDir = $this->getFallbackPath($type);
            }
        } else {
            $baseDir = $this->getFallbackPath('view');
        }

        if ($subPath !== '') {
            $baseDir .= '/' . $subPath;
        }

        $file = $this->parseFolderFilename($viewName, '/index.php');
        return "$baseDir/$file";
    }

    private function getFallbackPath(string $type): string
    {
        $fallbackMap = [
            'controller' => ['controllers', ''],
            'middleware' => ['middlewares', ''],
            'event' => ['events', ''],
            'migration' => ['migrations', ''],
            'seeder' => ['seeders', ''],
            'model' => ['models', ''],
            'view' => ['pages', ''],
            'command' => ['commands', ''],
            'service' => ['injectable', ''],
            'enum' => ['Enums', ''],
            'trait' => ['Traits', ''],
            'test' => ['tests', ''],
            'component' => ['components', ''],
            'component-view' => ['components', ''],
            'component-dto' => ['components', ''],
            'component-view-only' => ['components', ''],
            'layout' => ['layouts', ''],
            'dto' => ['dto', ''],
        ];

        [$structureKey] = $fallbackMap[$type] ?? ['', ''];

        $modulesRoot = StructureResolver::resolveModulesRoot();

        try {
            $resolver = new StructureResolver();
            if ($this->type === 'app') {
                $structurePath = $resolver->getAppPath($structureKey);
            } else {
                $structurePath = $resolver->getModulePath($this->module, $structureKey);
            }
            return $this->type === 'app'
                ? BASE_PATH . '/' . $structurePath
                : BASE_PATH . "/{$modulesRoot}/{$this->module}/{$structurePath}";
        } catch (\InvalidArgumentException $e) {
            return $this->type === 'app'
                ? BASE_PATH . '/app'
                : BASE_PATH . "/{$modulesRoot}/{$this->module}/src";
        }
    }

    private function viewComponentPath(): string
    {
        return $this->resolve('component-view')['path'];
    }

    private function layoutPath(): string
    {
        return $this->resolve('layout')['path'];
    }
}
