<?php

declare(strict_types=1);

namespace Forge\Core;

$requestUri = $_SERVER["REQUEST_URI"] ?? '';
if ($requestUri !== '' && preg_match('/\.env$/i', $requestUri)) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

use Forge\Core\Bootstrap\Bootstrap;
use Forge\Core\Config\EnvParser;
use Forge\Core\Debug\Metrics;
use Forge\Core\Helpers\FileExistenceCache;

final class Kernel
{
    public static function init(): void
    {
        $envPath = BASE_PATH . "/.env";
        if (FileExistenceCache::exists($envPath)) {
            EnvParser::load($envPath);
        }

        Metrics::start('kernel_resolution');
        Bootstrap::getInstance();
        Metrics::stop('kernel_resolution');
    }
}
