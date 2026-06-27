<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\CLI\Application;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Attributes\Command as CommandAttr;
use Forge\CLI\Command;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
use Forge\Core\Services\AttributeDiscoveryService;
use Forge\Core\Structure\StructureResolver;
use Forge\Exceptions\MissingServiceException;
use Forge\Traits\NamespaceHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

final class AppCommandSetup
{
    use NamespaceHelper;

    private const string CLASS_MAP_FILE = BASE_PATH . '/storage/framework/cache/app_command_class_map.php';
    private static ?self $instance = null;

    /** @var array<string, string> */
    private array $classMap = [];

    private function __construct(private readonly Container $container)
    {
    }

    public static function getInstance(Container $container): self
    {
        if (self::$instance === null) {
            self::$instance = new self($container);
            self::$instance->loadClassMap();
        }
        return self::$instance;
    }

    private function loadClassMap(): void
    {
        $classMapExists = FileExistenceCache::exists(self::CLASS_MAP_FILE);
        if ($classMapExists) {
            $classMap = include self::CLASS_MAP_FILE;
            $this->classMap = is_array($classMap) ? $classMap : [];
        }

        $this->cleanupStaleEntries();
        $this->buildClassMap();
        $this->saveClassMap();
    }

    private function cleanupStaleEntries(): void
    {
        FileExistenceCache::preload(array_values($this->classMap));

        foreach ($this->classMap as $className => $filePath) {
            if (!FileExistenceCache::exists($filePath)) {
                unset($this->classMap[$className]);
            }
        }
    }

    private function buildClassMap(): void
    {
        $appNamespace = 'App\\';

        // 1. Attribute-based discovery: any class under app/ with #[Cli] or #[Command]
        $discoveryService = new AttributeDiscoveryService();
        $classMap = $discoveryService->discover(['app'], [Cli::class, CommandAttr::class]);

        foreach ($classMap as $className => $metadata) {
            if (!in_array(Cli::class, $metadata['attributes'] ?? [], true) && !in_array(CommandAttr::class, $metadata['attributes'] ?? [], true)) {
                continue;
            }

            if (!is_subclass_of($className, Command::class)) {
                continue;
            }

            if (!str_starts_with($className, $appNamespace)) {
                continue;
            }

            // Exclude module classes; they are handled by RegisterModuleCommand.
            if (str_starts_with($className, 'App\\Modules\\')) {
                continue;
            }

            $this->classMap[$className] = $metadata['file'];
        }

        // 2. Legacy folder fallback: app/Commands/ (or configured commands path)
        $appCommandsPath = $this->resolveAppCommandsPath();
        if ($appCommandsPath === null || !is_dir($appCommandsPath)) {
            return;
        }

        $directoryIterator = new RecursiveDirectoryIterator($appCommandsPath);
        $iterator = new RecursiveIteratorIterator($directoryIterator);

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php' || !str_ends_with($file->getFilename(), 'Command.php')) {
                continue;
            }

            $filePath = $file->getRealPath();
            $className = $this->extractClassNameFromFile($filePath);

            if ($className === null) {
                continue;
            }

            if (!str_starts_with($className, 'App\\Commands\\')) {
                continue;
            }

            $this->classMap[$className] = $filePath;
        }
    }

    private function resolveAppCommandsPath(): ?string
    {
        $structureResolver = $this->container->has(StructureResolver::class)
            ? $this->container->get(StructureResolver::class)
            : null;

        if ($structureResolver) {
            try {
                return BASE_PATH . '/' . $structureResolver->getAppPath('commands');
            } catch (\InvalidArgumentException $e) {
                return BASE_PATH . '/app/Commands';
            }
        }

        return BASE_PATH . '/app/Commands';
    }

    private function extractClassNameFromFile(string $filePath): ?string
    {
        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        if (!preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatch)) {
            return null;
        }

        if (!preg_match('/(?:class|enum|trait|interface)\s+(\w+)/', $contents, $classMatch)) {
            return null;
        }

        return trim($namespaceMatch[1]) . '\\' . $classMatch[1];
    }

    private function saveClassMap(): void
    {
        $directory = dirname(self::CLASS_MAP_FILE);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $fp = fopen(self::CLASS_MAP_FILE, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, '<?php return ' . var_export($this->classMap, true) . ';');
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        } else {
            file_put_contents(self::CLASS_MAP_FILE, '<?php return ' . var_export($this->classMap, true) . ';');
        }
    }

    public function getClassMap(): array
    {
        return $this->classMap;
    }

    public function init(): void
    {
        $this->registerAppCommands();
    }

    /**
     * @throws MissingServiceException
     */
    private function registerAppCommands(): void
    {
        $cliApplication = $this->container->get(Application::class);

        foreach ($this->classMap as $className => $filePath) {
            if (!class_exists($className)) {
                include_once $filePath;
            }

            if (!class_exists($className) || !is_subclass_of($className, Command::class)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            $attribute = $reflection->getAttributes(CommandAttr::class)[0] ?? $reflection->getAttributes(Cli::class)[0] ?? null;
            if ($attribute === null) {
                continue;
            }

            $instance = $attribute->newInstance();
            $cliApplication->registerAppCommandClass($className, $instance->command, $instance->description);
        }
    }
}
