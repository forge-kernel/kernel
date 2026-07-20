<?php

declare(strict_types=1);

namespace Forge\Core\Bootstrap;

final class CliErrorHandler
{
    private const MAX_TRACE_FRAMES = 5;
    private const LOG_FILE = 'storage/logs/cli_errors.log';

    private const RESET = "\033[0m";
    private const RED = "\033[0;31m";
    private const YELLOW = "\033[1;33m";
    private const CYAN = "\033[0;36m";
    private const GRAY = "\033[0;90m";
    private const BORDER = '▓';

    public static function handle(\Throwable $e, bool $debug = true): void
    {
        self::render($e, $debug);
        self::log($e);
    }

    private static function render(\Throwable $e, bool $debug): void
    {
        $originFile = $e->getFile();
        $originLine = $e->getLine();
        $errorType = get_class($e);
        $errorMessage = $e->getMessage();

        $previous = $e->getPrevious();
        if ($previous !== null) {
            $originFile = $previous->getFile();
            $originLine = $previous->getLine();
            $errorType = get_class($previous);
            $errorMessage = $previous->getMessage();
        }

        $basePrefix = defined('BASE_PATH') ? BASE_PATH . '/' : '';
        if ($basePrefix && str_starts_with($originFile, $basePrefix)) {
            $originFile = substr($originFile, strlen($basePrefix));
        }

        $shortType = (new \ReflectionClass($errorType))->getShortName();
        $width = 70;
        $border = str_repeat(self::BORDER, $width);

        $titleSpaced = implode(' ', str_split(strtoupper($shortType)));
        $titlePadding = (int) (($width - strlen($titleSpaced)) / 2);
        $titleLine = str_repeat(' ', max(0, $titlePadding)) . $titleSpaced;

        $out = PHP_EOL;
        $out .= self::RED . $border . self::RESET . PHP_EOL;
        $out .= self::YELLOW . $titleLine . self::RESET . PHP_EOL;
        $out .= self::RED . $border . self::RESET . PHP_EOL;
        $out .= PHP_EOL;
        $out .= '  ' . self::CYAN . 'Type:' . self::RESET . ' ' . $errorType . PHP_EOL;
        $out .= PHP_EOL;
        $out .= '  ' . self::CYAN . 'Message:' . self::RESET . PHP_EOL;
        $out .= '    ' . $errorMessage . PHP_EOL;
        $out .= PHP_EOL;
        $out .= '  ' . self::CYAN . 'Origin:' . self::RESET . PHP_EOL;
        $out .= '    ' . $originFile . ':' . $originLine . PHP_EOL;

        $trace = $e->getTrace();
        $frames = array_slice($trace, 0, self::MAX_TRACE_FRAMES);

        if (!empty($frames)) {
            $out .= PHP_EOL;
            $out .= '  ' . self::CYAN . 'Stack:' . self::RESET . PHP_EOL;

            foreach ($frames as $i => $frame) {
                $num = $i + 1;
                $file = $frame['file'] ?? '{internal}';
                $line = $frame['line'] ?? '?';
                if ($basePrefix && str_starts_with($file, $basePrefix)) {
                    $file = substr($file, strlen($basePrefix));
                }
                $function = $frame['function'] ?? '';
                $class = $frame['class'] ?? '';
                $method = $class ? $class . '::' . $function : $function;
                $out .= '    ' . self::YELLOW . "{$num}." . self::RESET . " {$file}:{$line}" . PHP_EOL;
                if ($method) {
                    $out .= '       ' . self::GRAY . "{$method}()" . self::RESET . PHP_EOL;
                }
            }

            if (count($trace) > self::MAX_TRACE_FRAMES) {
                $remaining = count($trace) - self::MAX_TRACE_FRAMES;
                $out .= '    ' . self::GRAY . "... {$remaining} more frame" . ($remaining > 1 ? 's' : '') . self::RESET . PHP_EOL;
            }
        }

        if (!$debug) {
            $lines = explode(PHP_EOL, $out);
            $filtered = [];
            $skipStack = false;
            foreach ($lines as $line) {
                if (str_contains($line, 'Stack:')) {
                    $skipStack = true;
                }
                if ($skipStack) {
                    continue;
                }
                $filtered[] = $line;
            }
            $out = implode(PHP_EOL, $filtered);
        }

        $out .= PHP_EOL;
        $out .= '  ' . self::GRAY . 'Logged to: ' . self::LOG_FILE . self::RESET . PHP_EOL;
        $out .= PHP_EOL;

        fwrite(STDERR, $out);
    }

    private static function log(\Throwable $e): void
    {
        $logDir = defined('BASE_PATH') ? BASE_PATH . '/storage/logs' : 'storage/logs';
        $logFile = $logDir . '/cli_errors.log';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $trace = $e->getTraceAsString();
        $entry = sprintf(
            "[%s] %s: %s in %s:%d\nTrace: %s\n\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $trace,
        );

        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
