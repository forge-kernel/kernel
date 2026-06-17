<?php
declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\DI\Container;
use Forge\Core\Config\Environment;
use Forge\Core\Session\Drivers\FileSessionDriver;
use Forge\Core\Session\Drivers\MemorySessionDriver;
use Forge\Core\Session\Drivers\SqliteSessionDriver;
use Forge\Core\Session\Session;
use Forge\Core\Session\SessionInterface;

final class SessionSetup
{
    public static function setup(Container $container): void
    {
        $env = Environment::getInstance();

        $container->singleton(SessionInterface::class, function () use ($env) {
            $driverName = strtolower(trim($env->get('SESSION_DRIVER', 'memory')));
            $rawValue = $env->get('SESSION_DRIVER');

            if ($rawValue === null || $rawValue === '') {
                $driverName = 'file';
            }

            try {
                $driver = match ($driverName) {
                    'memory' => new MemorySessionDriver(),
                    'sqlite' => new SqliteSessionDriver(),
                    'database' => new FileSessionDriver(),
                    'file' => new FileSessionDriver(),
                    default => new SqliteSessionDriver(),
                };
                return new Session($driver);
            } catch (\Throwable $e) {
                error_log('Failed to initialize session driver "' . $driverName . '": ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $fileDriver = new FileSessionDriver();
                return new Session($fileDriver);
            }
        });
    }
}