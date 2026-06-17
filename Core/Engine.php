<?php

declare(strict_types=1);

namespace Forge\Core;

$requestUri = $_SERVER["REQUEST_URI"] ?? '';
if ($requestUri !== '' && preg_match('/\.env$/i', $requestUri)) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

use Forge\Core\Bootstrap\Bootstrap;
use Forge\Core\Debug\Metrics;
use Forge\Core\Helpers\FileExistenceCache;

final class Engine
{
    public static function init(): void
    {
        Metrics::start('kernel_resolution');
        Bootstrap::getInstance();

        $compiledFile = BASE_PATH . '/storage/framework/cache/compiled_hooks.php';
        if (FileExistenceCache::exists($compiledFile)) {
            include $compiledFile;
        }

        Metrics::stop('kernel_resolution');
    }
}
