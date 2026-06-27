<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

use Forge\Core\DI\Container;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;
use ReflectionException;

/**
 * Sets up error handling by discovering ErrorHandlerInterface implementations.
 * This allows any module to provide error handling capabilities.
 */
final class ErrorHandlerSetup
{
    /**
     * Discover and register error handlers from modules.
     * Error handlers are registered early to catch errors during bootstrap.
     *
     * @param Container $container
     * @return void
     * @throws ReflectionException
     * @throws MissingServiceException
     * @throws ResolveParameterException
     */
    public static function setup(Container $container): void
    {
        ini_set(
            "display_errors",
            \Forge\Core\Config\Environment::getInstance()->isDevelopment()
                ? "1"
                : "0",
        );
        error_reporting(E_ALL);

        $errorHandlerInterface = 'App\Modules\ForgeRouter\Contracts\ErrorHandlerInterface';
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
                error_log("Failed to discover error handler: " . $e->getMessage());
            }
        }

        if (!$errorHandler) {
            self::registerDefaultErrorHandler();
        }
    }

    /**
     * Register a default error handler when no module provides one.
     * This ensures users see PHP errors instead of white screens.
     */
    private static function registerDefaultErrorHandler(): void
    {
        set_error_handler(function (
            int $severity,
            string $message,
            string $file,
            int $line,
        ): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            $errorTypes = [
                E_ERROR => "Fatal Error",
                E_WARNING => "Warning",
                E_PARSE => "Parse Error",
                E_NOTICE => "Notice",
                E_CORE_ERROR => "Core Error",
                E_CORE_WARNING => "Core Warning",
                E_COMPILE_ERROR => "Compile Error",
                E_COMPILE_WARNING => "Compile Warning",
                E_USER_ERROR => "User Error",
                E_USER_WARNING => "User Warning",
                E_USER_NOTICE => "User Notice",
                E_RECOVERABLE_ERROR => "Recoverable Error",
                E_DEPRECATED => "Deprecated",
                E_USER_DEPRECATED => "User Deprecated",
            ];

            $errorType = $errorTypes[$severity] ?? "Unknown Error";

            if (
                \Forge\Core\Config\Environment::getInstance()->isDevelopment()
            ) {
                echo "<!DOCTYPE html><html><head><title>{$errorType}</title></head><body>";
                echo "<h1>{$errorType}</h1>";
                echo "<p><strong>Message:</strong> " .
                    htmlspecialchars($message) .
                    "</p>";
                echo "<p><strong>File:</strong> " .
                    htmlspecialchars($file) .
                    "</p>";
                echo "<p><strong>Line:</strong> {$line}</p>";
                echo "<hr>";
                echo "<small>This is a default error handler. Install ForgeErrorHandler for better error handling.</small>";
                echo "</body></html>";
            } else {
                error_log(
                    "PHP {$errorType}: {$message} in {$file} on line {$line}",
                );
                echo "<!DOCTYPE html><html><head><title>Application Error</title></head><body>";
                echo "<h1>Application Error</h1>";
                echo "<p>Something went wrong. Please try again later.</p>";
                echo "</body></html>";
            }

            return true;
        });

        set_exception_handler(function (\Throwable $exception): void {
            if (
                \Forge\Core\Config\Environment::getInstance()->isDevelopment()
            ) {
                echo "<!DOCTYPE html><html><head><title>Fatal Error</title></head><body>";
                echo "<h1>Fatal Error</h1>";
                echo "<p><strong>Type:</strong> " .
                    get_class($exception) .
                    "</p>";
                echo "<p><strong>Message:</strong> " .
                    htmlspecialchars($exception->getMessage()) .
                    "</p>";
                echo "<p><strong>File:</strong> " .
                    htmlspecialchars($exception->getFile()) .
                    "</p>";
                echo "<p><strong>Line:</strong> {$exception->getLine()}</p>";
                echo "<h3>Stack Trace</h3>";
                echo "<pre>" .
                    htmlspecialchars($exception->getTraceAsString()) .
                    "</pre>";
                echo "<hr>";
                echo "<small>This is a default error handler. Install ForgeErrorHandler for better error handling.</small>";
                echo "</body></html>";
            } else {
                error_log(
                    "Uncaught exception: " .
                        $exception->getMessage() .
                        " in " .
                        $exception->getFile() .
                        " on line " .
                        $exception->getLine(),
                );
                echo "<!DOCTYPE html><html><head><title>Application Error</title></head><body>";
                echo "<h1>Application Error</h1>";
                echo "<p>Something went wrong. Please try again later.</p>";
                echo "</body></html>";
            }
        });
    }
}
