<?php

namespace Forge\Core\Helpers;

// use Forge\Core\DI\Container;

use App\Modules\ForgeDebugbar\Collectors\ExceptionCollector;
use App\Modules\ForgeDebugbar\Collectors\MessageCollector;
use Forge\Core\DI\Container;
//use Forge\Modules\ForgeDebugbar\Collectors\ExceptionCollector;
// use Forge\Modules\ForgeDebugbar\Collectors\MessageCollector;
// use Forge\Modules\ForgeDebugbar\Collectors\TimelineCollector;
use ReflectionClass;

final class Debuger
{
    private const DEFAULT_DD_CSS = <<<CSS
		.dd-container {
			background-color: #18181A;
			border: 1px solid #555;
			border-radius: 4px;
			padding: 15px;
			font-family: 'Consolas', Courier, monospace;
			font-size: 0.9rem;
			line-height: 1.5;
			color: #f8f8f2;
			box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
			max-width: 100%;
			overflow-x: auto;
			overflow-y: auto;
			max-height: 80vh;
		}
		.dd-container pre {
			margin: 0;
			white-space: pre-wrap;
			word-wrap: break-word;
			font-family: inherit;
			font-size: inherit;
			line-height: inherit;
			color: inherit;
		}
		.dd-container .key {
			color: #fff;
			font-weight: 500;
		}
		.dd-container .string {
			color: #3bdb3a;
		}
		.dd-container .number {
			color: #e6b450;
		}
		.dd-container .boolean {
			color: #61afef;
			font-weight: 700;
		}
		.dd-container .null {
			color: #ff8400;
		}
		.dd-container .object,
		.dd-container .array,
		.dd-container .object-class {
			color: #61afef;
			font-weight: bold;
		}
		.dd-container .object-property,
		.dd-container .array-element {
			margin-left: 15px;
			display: block;
		}
		.dd-container .value {
			font-weight: normal;
		}
		.dd-container .object-class {
			font-style: italic;
			color: #777;
		}
		.dd-trace {
			color: #999;
			font-size: 0.8rem;
			margin-bottom: 10px;
		}
		.dd-trace-file {
			color: #eee;
			font-weight: bold;
		}
		.dd-trace-line {
			color: #eee;
		}
	CSS;

    public function __construct()
    {
    }

    public static function printPre(...$vars): void
    {
        echo "<pre>";
        foreach ($vars as $var) {
            print_r($var);
            echo '<br />';
        }
        echo "</pre></div>";
        die(1);
    }

    /**
     * Dump and Exit
     * Prints human readable information about one ore more variables and then exits.
     *
     * @param mixed
     * @return void
     */
    public static function dumpAndExit(...$vars): void
    {
        $fileContent = '';
        $cssToUse = empty($fileContent) ? self::DEFAULT_DD_CSS : $fileContent;

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $callInfo = null;

        if (isset($backtrace[0])) {
            $call = $backtrace[0];
            $file = isset($call['file']) ? $call['file'] : 'unknown file';
            $line = isset($call['line']) ? $call['line'] : 'unknown line';

            $filePath = $file ?? null;
            if (strpos($filePath, BASE_PATH) === 0) {
                $filePath = substr($filePath, strlen(BASE_PATH));
            }

            $callInfo = "<div class='dd-trace'><code>Debuger::dumAndExit() called from <span class='dd-trace-file'>" . e($filePath) . "</span>:<span class='dd-trace-line'>" . $line . "</span></code></div>\n";
        }

        echo "<style>\n" . $cssToUse . "\n</style>";
        echo "<div class='dd-container'>";

        if ($callInfo) {
            echo $callInfo;
        }

        echo "<pre>";
        foreach ($vars as $var) {
            echo self::formatHtmlVariable($var);
        }
        echo "</pre></div>";
        die(1);
    }

    /**
     * Recursively formats a variable for HTML output with CSS classes.
     *
     * @param mixed $var The variable to format
     * @param int $indentationLevel Current indentation level (for nested structures)
     * @return string HTML markup for the variable
     */
    public static function formatHtmlVariable($var, int $indentationLevel = 0): string
    {
        $output = '';
        $indent = str_repeat('  ', $indentationLevel);

        if ($var === null) {
            return "<span class='null'>null</span>";
        }

        if (is_bool($var)) {
            return "<span class='boolean'>" . ($var ? 'true' : 'false') . "</span>";
        }
        if (is_int($var)) {
            return "<span class='number'>$var</span>";
        }
        if (is_float($var)) {
            return "<span class='number'>$var</span>";
        }
        if (is_string($var)) {
            return "<span class='string'>\"" . htmlspecialchars($var, ENT_QUOTES) . "\"</span>";
        }

        if (is_array($var)) {
            $output .= "<span class='array'>[</span>\n";
            foreach ($var as $k => $v) {
                $output .= $indent . '  '
                       .  "<span class='key'>" . htmlspecialchars($k) . "</span> => "
                       .  self::formatHtmlVariable($v, $indentationLevel + 2) . ",\n";
            }
            $output .= $indent . "<span class='array'>]</span>";
            return $output;
        }

        if (is_object($var)) {
            $output .= "<span class='object'>Object</span> <span class='object-class'>(" . get_class($var) . ")</span> <span class='object'>{</span>\n";

            $refl  = new ReflectionClass($var);
            $props = $refl->getProperties();

            foreach ($props as $p) {
                $p->setAccessible(true);
                if (!$p->isInitialized($var)) {
                    $output .= $indent . '  '
                           .  "<span class='key'>" . $p->getName() . "</span>: "
                           .  "<span class='null'>*uninitialised*</span>,\n";
                    continue;
                }
                try {
                    $value = $p->getValue($var);
                } catch (\Throwable $e) {
                    $value = '*error*';
                }

                $output .= $indent . '  '
                       .  "<span class='key'>" . $p->getName() . "</span>: "
                       .  self::formatHtmlVariable($value, $indentationLevel + 2) . ",\n";
            }

            $output .= $indent . "<span class='object'>}</span>";
            return $output;
        }

        return "<span class='unknown'>" . htmlspecialchars(print_r($var, true)) . "</span>";
    }

    /**
     * Log messages to the debugbar
     *
     * @param mixed $message
     * @param string $label
     *
     * @return void
     */
    public static function message(mixed $message, string $label = 'info'): void
    {
        if (class_exists(\App\Modules\ForgeDebugbar\DebugBar::class)) {
            if (env('APP_ENV', false)) {
                /** @var MessageCollector $messageCollector */
                $messageCollector = Container::getInstance()->get(MessageCollector::class);
                $messageCollector::instance()->addMessage($message, $label);
            }
        }
    }

    public static function logException(\Throwable $exception): void
    {
        if (class_exists(ExceptionCollector::class)) {
            if (env('APP_ENV', false)) {
                /** @var ExceptionCollector $exceptionCollector */
                $exceptionCollector = Container::getInstance()->get(ExceptionCollector::class);
                $exceptionCollector::instance()->addException($exception);
            }
        }
    }

    /**
     * Track new event during request lifecycle
     *
     * @param string $name
     * @param string $label
     * @param array $data
     *
     * @return void
     */
    // public static function addEvent(string $name, string $label, array $data = []): void
    // {
    //     if (class_exists(TimelineCollector::class)) {
    //         if (filter_var($_ENV["FORGE_APP_DEBUG"] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    //             /** @var TimelineCollector $timelineCollector */
    //             $timelineCollector = Container::getContainer()->get(TimelineCollector::class);
    //             $timelineCollector::instance()->addEvent($name, $label, $data);
    //         }
    //     }
    // }

    public static function backtraceOrigin(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);

        $origin = 'Unknown Origin';

        foreach ($trace as $frame) {
            if (isset($frame['class']) && $frame['class'] !== self::class) {
                if (strpos($frame['class'], 'Forge\\Modules\\ForgeDebugbar') === 0) {
                    continue;
                }

                $class = $frame['class'] ?? 'unknown class';
                $function = $frame['function'] ?? 'unknown function';
                $origin = "{$class}@{$function}";
            }
        }
        return $origin;
    }
}
