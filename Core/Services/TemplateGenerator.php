<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Exception;
use Forge\Core\DI\Attributes\Injectable;

#[Injectable]
final class TemplateGenerator
{
    private string $baseTemplatePath;

    public function __construct(
        private readonly InteractiveSelect $interactiveSelect
    ) {
        $this->baseTemplatePath = BASE_PATH . "/kernel/Core/Templates/";
    }

    /**
     * Generate a file from a template.
     * Automatically creates the directory if missing.
     * Won't overwrite an existing file.
     *
     * @param string $templateName Template file name relative to base path
     * @param string $outputPath Full path where the file should be generated
     * @param array<string,string> $replacements Key=>value replacements in the template
     * @param bool $forceOverwrite Whether to overwrite existing files
     * @throws Exception
     */
    public function generateFileFromTemplate(
        string $templateName,
        string $outputPath,
        array $replacements,
        bool $forceOverwrite = false
    ): void {
        $templatePath = $this->baseTemplatePath . $templateName;
        $templateContent = file_get_contents($templatePath);

        if ($templateContent === false) {
            throw new Exception("Error: Could not read template file from: {$templatePath}");
        }

        $fileContent = str_replace(array_keys($replacements), array_values($replacements), $templateContent);

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($outputPath) && !$forceOverwrite) {
            echo "File already exists, skipping: {$outputPath}\n";
            return;
        }

        file_put_contents($outputPath, $fileContent);
        echo "Created file: {$outputPath}\n";
    }

    public function askQuestion(string $questionText, string $default): string
    {
        $answer = readline($questionText . " [$default]: ");
        return $answer ?: $default;
    }

    public function selectFromList(string $prompt, array $options, ?string $default = null): ?string
    {
        if (empty($options)) {
            return null;
        }

        $defaultIndex = null;
        if ($default !== null) {
            $defaultIndex = array_search($default, $options, true);
            if ($defaultIndex === false) {
                $defaultIndex = null;
            }
        }

        $selectedIndex = $this->interactiveSelect->select($options, $prompt, $defaultIndex);

        if ($selectedIndex === null) {
            return null;
        }

        return $options[$selectedIndex] ?? null;
    }

    public function selectMultipleFromList(string $prompt, array $options, array $defaultSelected = []): ?array
    {
        if (empty($options)) {
            return null;
        }

        $defaultIndices = [];
        foreach ($defaultSelected as $defaultValue) {
            $index = array_search($defaultValue, $options, true);
            if ($index !== false) {
                $defaultIndices[] = $index;
            }
        }

        $selectedIndices = $this->interactiveSelect->multiSelect($options, $prompt, $defaultIndices);

        if ($selectedIndices === null) {
            return null;
        }

        $result = [];
        foreach ($selectedIndices as $index) {
            if (isset($options[$index])) {
                $result[] = $options[$index];
            }
        }

        return empty($result) ? null : $result;
    }

    public function selectFromListMultiColumn(string $prompt, array $options, ?string $default = null, ?int $terminalWidth = null): ?string
    {
        if (empty($options)) {
            return null;
        }

        $terminalWidth = $terminalWidth ?? $this->getTerminalWidth();

        $defaultIndex = null;
        if ($default !== null) {
            $defaultIndex = array_search($default, $options, true);
            if ($defaultIndex === false) {
                $defaultIndex = null;
            }
        }

        $selectedIndex = $this->interactiveSelect->selectMultiColumn($options, $prompt, $defaultIndex, $terminalWidth);

        if ($selectedIndex === null) {
            return null;
        }

        return $options[$selectedIndex] ?? null;
    }

    private function getTerminalWidth(): int
    {
        if (function_exists('exec')) {
            $output = [];
            $return = 0;
            @exec('tput cols 2>/dev/null', $output, $return);
            if ($return === 0 && !empty($output) && is_numeric($output[0])) {
                return (int) $output[0];
            }
        }

        if (isset($_ENV['COLUMNS']) && is_numeric($_ENV['COLUMNS'])) {
            return (int) $_ENV['COLUMNS'];
        }

        return 80;
    }
}
