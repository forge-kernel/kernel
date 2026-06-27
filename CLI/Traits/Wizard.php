<?php

declare(strict_types=1);

namespace Forge\CLI\Traits;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Attributes\Command as CommandAttr;
use ReflectionNamedType;
use ReflectionObject;

trait Wizard
{
    use OutputHelper;

    /** @var array<string,true> keeps asked questions */
    private array $asked = [];

    protected function wizard(array $argv): void
    {
        $ref = new ReflectionObject($this);

        $commandAttr = $ref->getAttributes(CommandAttr::class)[0] ?? $ref->getAttributes(Cli::class)[0] ?? null;
        $commandDesc = $commandAttr?->newInstance()->description ?? null;

        if ($commandDesc) {
            $this->line("\033[1;33m$commandDesc\033[0m\n");
        }

        foreach ($ref->getProperties() as $prop) {
            $attr = $prop->getAttributes(Arg::class)[0] ?? null;
            if (!$attr) {
                continue;
            }

            /** @var Arg $arg */
            $arg = $attr->newInstance();
            $value = $this->extractValue($arg->name, $argv) ?? $arg->default;

            if (
                $arg->name === "module" &&
                isset($this->type) &&
                $this->type === "module"
            ) {
                $arg->required = true;
            }

            if ($value === null && $arg->required) {
                $value = $this->askUntilValid($arg);
            }

            if ($value === null) {
                $type = $prop->getType();
                if ($type instanceof ReflectionNamedType && $type->getName() === 'bool') {
                    $value = $prop->getDefaultValue() ?? false;
                }
            }

            $prop->setAccessible(true);
            $prop->setValue($this, $value);
        }
    }

    private function extractValue(string $name, array $argv): mixed
    {
        foreach ($argv as $i => $token) {
            if (str_starts_with($token, "--$name=")) {
                $value = substr($token, strlen("--$name="));
                if (in_array(strtolower($value), ['true', '1', 'yes', 'y'], true)) {
                    return true;
                }
                if (in_array(strtolower($value), ['false', '0', 'no', 'n'], true)) {
                    return false;
                }
                return $value;
            }
            if ($token === "--$name") {
                if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                    return $argv[$i + 1];
                }
                return true;
            }
        }
        return null;
    }

    private function askUntilValid(Arg $arg): string
    {
        $ref = new ReflectionObject($this);
        $hasCli = $ref->getAttributes(CommandAttr::class)[0] ?? $ref->getAttributes(Cli::class)[0] ?? null;

        $prompt = $arg->ask ?? ucfirst(str_replace("_", " ", $arg->name));
        if ($hasCli && $arg->description) {
            $prompt .= " ({$arg->description})";
        }
        $prompt .= ":";

        $this->prompt("\033[1;36m$prompt\033[0m");
        $input = trim(fgets(STDIN));

        if ($input === "" && $arg->default !== null) {
            $this->comment("Using default: $arg->default");
            return $arg->default;
        }

        if ($error = $this->validate($input, $arg)) {
            $this->error($error);
            return $this->askUntilValid($arg);
        }

        return $input;
    }

    private function validate(string $value, Arg $arg): ?string
    {
        if ($arg->validate === null) {
            return null;
        }

        return match ($arg->validate) {
            "int" => is_numeric($value) ? null : "Value must be numeric.",
            "bool" => in_array($value, ["yes", "no", "y", "n", "1", "0"], true)
                ? null
                : "Answer yes/no.",
            "file" => is_file($value) ? null : "File does not exist.",
            "dir" => is_dir($value) ? null : "Directory does not exist.",
            default => $this->matchPattern($value, $arg->validate),
        };
    }

    private function matchPattern(string $value, string $pattern): ?string
    {
        if (@preg_match($pattern, "") === false) {
            $pattern = "/" . str_replace("/", "\/", $pattern) . "/";
        }

        return preg_match($pattern, $value) ? null : "Invalid format.";
    }

    protected function askYesNo(string $question, string $expected): bool
    {
        $this->prompt($question . ": ");
        $answer = trim(fgets(STDIN));
        return $answer === $expected;
    }

    protected function generate(
        string $stubPath,
        string $targetPath,
        array $replacements,
    ): void {
        if (!is_file($stubPath)) {
            $this->error("Stub not found: $stubPath");
            exit(1);
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = file_get_contents($stubPath);
        $content = strtr($content, $replacements);
        file_put_contents($targetPath, $content);

        $this->success("Created: $targetPath");
    }
}
