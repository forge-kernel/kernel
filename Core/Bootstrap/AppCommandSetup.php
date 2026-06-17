<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\CLI\Application;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\FileExistenceCache;
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
        $appNamespace = 'App\\Commands';

        $structureResolver = $this->container->has(StructureResolver::class)
            ? $this->container->get(StructureResolver::class)
            : null;

        if ($structureResolver) {
            try {
                $appCommandsPath = $structureResolver->getAppPath('commands');
                $appPath = BASE_PATH . '/' . $appCommandsPath;
            } catch (\InvalidArgumentException $e) {
                $appPath = BASE_PATH . '/app/Commands';
            }
        } else {
            $appPath = BASE_PATH . '/app/Commands';
        }

        if (!is_dir($appPath)) {
            return;
        }

        $directoryIterator = new RecursiveDirectoryIterator($appPath);
        $iterator = new RecursiveIteratorIterator($directoryIterator);

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php' || !str_ends_with($file->getFilename(), 'Command.php')) {
                continue;
            }

            $before = get_declared_classes();
            include_once $file->getRealPath();
            $after = get_declared_classes();

            $newClasses = array_diff($after, $before);

            foreach ($newClasses as $className) {
                if (!str_starts_with($className, $appNamespace)) {
                    continue;
                }

                if (is_subclass_of($className, Command::class)) {
                    $this->classMap[$className] = $file->getRealPath();
                }
            }
        }
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

            $attributes = (new ReflectionClass($className))->getAttributes(Cli::class);
            if (empty($attributes)) {
                continue;
            }

            $instance = $attributes[0]->newInstance();
            $cliApplication->registerAppCommandClass($className, $instance->command, $instance->description);
        }
    }
}
