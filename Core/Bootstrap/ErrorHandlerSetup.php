<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\DI\Container;
use Forge\Core\Helpers\Logger;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;
use ReflectionException;

/**
 * Sets up error handling by discovering ErrorHandlerInterface implementations.
 * This allows any module to provide error handling capabilities.
 */
final class ErrorHandlerSetup
{
    public static function setup(Container $container): void
    {
        ini_set(
            "display_errors",
            \Forge\Core\Config\Environment::getInstance()->isDevelopment()
                ? "1"
                : "0",
        );
        error_reporting(E_ALL);

        $errorHandlerInterface = 'Modules\ForgeRouter\Contracts\ErrorHandlerInterface';
        $errorHandler = null;

        if (interface_exists($errorHandlerInterface)) {
            try {
                if ($container->has($errorHandlerInterface)) {
                    $errorHandler = $container->get($errorHandlerInterface);
                }

                if (!$errorHandler) {
                    $errorHandlers = $container->getAll(
                        $errorHandlerInterface,
                    );

                    if (!empty($errorHandlers)) {
                        $errorHandler = $errorHandlers[0];
                    }
                }
            } catch (\Throwable $e) {
                Logger::log("Failed to discover error handler", $e->getMessage());
            }
        }

        if (!$errorHandler) {
            self::registerDefaultHandler();
        }
    }

    private static function registerDefaultHandler(): void
    {
        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (\Throwable $e): void {
            try {
                self::render($e);
            } catch (\Throwable $fatal) {
                $msg = "Fatal error in default handler: {$fatal->getMessage()}";
                if (PHP_SAPI === 'cli') {
                    fwrite(STDERR, $msg . PHP_EOL);
                } else {
                    http_response_code(500);
                    echo $msg;
                }
            } finally {
                exit(1);
            }
        });
    }

    private static function render(\Throwable $e): void
    {
        $isCli = PHP_SAPI === 'cli';
        $dev = \Forge\Core\Config\Environment::getInstance()->isDevelopment();
        $basePrefix = defined('BASE_PATH') ? BASE_PATH . '/' : '';

        $originFile = $e->getFile();
        $originLine = $e->getLine();
        $originalType = get_class($e);
        $originalMessage = $e->getMessage();

        $previous = $e->getPrevious();
        if ($previous !== null) {
            $originFile = $previous->getFile();
            $originLine = $previous->getLine();
            $originalType = get_class($previous);
            $originalMessage = $previous->getMessage();
        }

        if ($basePrefix && str_starts_with($originFile, $basePrefix)) {
            $originFile = substr($originFile, strlen($basePrefix));
        }

        if ($dev) {
            self::renderDebug($e, $isCli, $originFile, $originLine, $originalType, $originalMessage);
        } else {
            Logger::log($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            self::renderProduction($isCli);
        }
    }

    private static function renderDebug(
        \Throwable $e,
        bool $isCli,
        string $originFile,
        int $originLine,
        string $originalType,
        string $originalMessage,
    ): void {
        if ($isCli) {
            fwrite(STDERR, "\n  " . $originalType . "\n\n");
            fwrite(STDERR, "  Message:\n");
            fwrite(STDERR, "    " . $originalMessage . "\n\n");
            fwrite(STDERR, "  Origin:\n");
            fwrite(STDERR, "    " . $originFile . ":" . $originLine . "\n\n");
            fwrite(STDERR, "  Stack Trace:\n");
            foreach (explode("\n", $e->getTraceAsString()) as $line) {
                fwrite(STDERR, "    " . $line . "\n");
            }
            fwrite(STDERR, "\n");
            return;
        }

        $type = htmlspecialchars($originalType);
        $msg = htmlspecialchars($originalMessage);
        $origin = htmlspecialchars($originFile) . ':' . $originLine;
        $trace = htmlspecialchars($e->getTraceAsString());

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Error</title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f1f2f4;padding:2rem;margin:0}
 .card{background:#fff;border-radius:8px;padding:2rem;max-width:100%;margin:0 auto;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.badge{display:inline-block;background:#212936;color:#fff;padding:.2rem .8rem;border-radius:4px;font-weight:600;font-size:.8rem;margin-bottom:.75rem}
.box{background:#fff6f6;border:1px solid #f5c6cb;border-left:4px solid #dc3545;border-radius:8px;padding:1rem;font-family:ui-monospace,monospace;margin-bottom:1rem;word-break:break-word}
.lbl{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#888;margin-bottom:.25rem}
.chip{background:#f0f0f0;border-radius:4px;padding:.3rem .7rem;display:inline-block;font-family:ui-monospace,monospace;font-size:.85rem;margin-bottom:1rem}
pre{background:#f8f9fa;border:1px solid #e0e0e0;border-radius:4px;padding:1rem;overflow-x:auto;font-size:.85rem;font-family:ui-monospace,monospace}
hr{border:none;border-top:1px solid #e0e0e0;margin:1.5rem 0}
.note{color:#888;font-size:.8rem}
</style>
</head>
<body>
<div class="card">
<div class="badge">{$type}</div>
<div class="box"><div class="lbl">Message</div>{$msg}</div>
<div class="chip">{$origin}</div>
<div style="margin-top:1rem"><div class="lbl">Stack Trace</div>
<pre>{$trace}</pre></div>
<hr>
<div class="note">Kernel default error handler. Install ForgeErrorHandler for enhanced diagnostics.</div>
</div>
</body>
</html>
HTML;
    }

    private static function renderProduction(bool $isCli): void
    {
        if ($isCli) {
            fwrite(STDERR, "An error occurred. Please check the logs." . PHP_EOL);
            return;
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Application Error</title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f8fafc;padding:2rem}
.box{max-width:600px;margin:2rem auto;padding:2rem;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
</style>
</head>
<body>
<div class="box">
<h1>Something went wrong</h1>
<p>We have been notified. Please try again later.</p>
<p><a href="/">Go home</a></p>
</div>
</body>
</html>
HTML;
    }
}
