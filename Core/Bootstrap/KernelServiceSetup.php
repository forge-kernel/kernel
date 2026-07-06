<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\Config\Environment;
use Forge\Core\Config\EnvParser;
use Forge\Core\DI\Container;
use Forge\Core\Module\ModuleLoader\Loader;
use Forge\Core\Services\ArchiveService;
use Forge\Core\Services\GitService;
use Forge\Core\Services\InteractiveSelect;
use Forge\Core\Services\ManifestService;
use Forge\Core\Services\ModuleAssetManager;
use Forge\Core\Services\ModuleMetadataService;
use Forge\Core\Services\RedirectHandlerService;
use Forge\Core\Services\RegistryReadmeService;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\SplashScreenService;
use Forge\Core\Services\TemplateGenerator;
use Forge\Core\Services\TokenManager;
use Forge\Core\Services\VersionService;
use Forge\Core\Session\Session;
use Forge\Core\Structure\StructureResolver;

final class KernelServiceSetup
{
    public static function register(Container $container): void
    {
        $services = [
            ArchiveService::class,
            Environment::class,
            EnvParser::class,
            GitService::class,
            InteractiveSelect::class,
            Loader::class,
            ManifestService::class,
            ModuleAssetManager::class,
            ModuleMetadataService::class,
            RedirectHandlerService::class,
            RegistryReadmeService::class,
            RegistryService::class,
            Session::class,
            SplashScreenService::class,
            StructureResolver::class,
            TemplateGenerator::class,
            TokenManager::class,
            VersionService::class,
        ];

        foreach ($services as $class) {
            if (!$container->has($class)) {
                $container->singleton($class, $class);
            }
        }
    }
}
