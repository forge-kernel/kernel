<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Dev;

use Forge\CLI\Command;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Traits\OutputHelper;
use Forge\Core\DI\Container;
use Forge\Core\Module\Attributes\Module;
use Forge\Core\Module\Attributes\Structure;
use Forge\Core\Services\InteractiveSelect;
use Forge\Core\Structure\StructureResolver;
use ReflectionClass;

#[Cli(
    command: 'dev:structure:add',
    description: 'Add Structure attribute to existing modules (interactive)',
    usage: 'dev:structure:add [--module=ModuleName]',
    examples: [
        'dev:structure:add',
        'dev:structure:add --module=Blog',
    ]
)]
final class DevStructureAddCommand extends Command
{
    use OutputHelper;

    public function execute(array $args): int
    {
        $container = Container::getInstance();

        if (!$container->has(StructureResolver::class)) {
            $this->error("StructureResolver not available");
            return 1;
        }

        $structureResolver = $container->get(StructureResolver::class);
        $interactiveSelect = $container->has(InteractiveSelect::class)
            ? $container->get(InteractiveSelect::class)
            : null;

        $modules = $this->discoverModules();

        if (empty($modules)) {
            $this->warning("No modules found.");
            return 0;
        }

        $this->info("Add Structure Attribute to Modules");
        $this->line("");

        $structureInfo = $this->analyzeStructure();
        $this->displayStructureInfo($structureInfo);

        $this->line("");

        $selectedModules = $this->selectModules($modules, $interactiveSelect, $args);

        if (empty($selectedModules)) {
            $this->info("No modules selected.");
            return 0;
        }

        $modulesWithStructure = $this->checkModulesWithStructure($selectedModules);
        $overwriteMode = false;

        if (!empty($modulesWithStructure)) {
            $this->line("");
            $this->warning("IMPORTANT: Some modules already have #[Structure] attribute:");
            foreach ($modulesWithStructure as $moduleName) {
                $this->line("  • {$moduleName}");
            }
            $this->line("");
            $this->warning("⚠️  DESTRUCTIVE OPERATION WARNING:");
            $this->line("  • Changing the structure attribute will NOT automatically move existing files");
            $this->line("  • If the new structure differs from the current one, files must be moved manually");
            $this->line("  • Your module may break if files are not in the correct locations");
            $this->line("  • Always backup your project before proceeding");
            $this->line("");
            $this->comment("What would you like to do with modules that already have structure?");
            $this->line("  1) Overwrite (replace existing structure with new one)");
            $this->line("  2) Skip modules with existing structure");
            $this->line("  3) Cancel");
            $this->prompt("\033[1;36mSelect option (1-3):\033[0m ");

            $choice = trim(fgets(STDIN));
            $this->line("");

            if ($choice === '3') {
                $this->info("Cancelled.");
                return 0;
            }

            if ($choice === '2') {
                $selectedModules = array_filter($selectedModules, function ($module) use ($modulesWithStructure) {
                    return !in_array($module['name'], $modulesWithStructure);
                });
                $selectedModules = array_values($selectedModules);

                if (empty($selectedModules)) {
                    $this->info("No modules remaining after filtering.");
                    return 0;
                }

                $this->info("Proceeding with " . count($selectedModules) . " module(s) without existing structure.");
                $this->line("");
            } elseif ($choice === '1') {
                $confirmed = $this->confirm("Are you sure you want to overwrite existing structure attributes?");
                if (!$confirmed) {
                    $this->info("Cancelled.");
                    return 0;
                }
                $overwriteMode = true;
                $this->line("");
            } else {
                $this->error("Invalid option.");
                return 1;
            }
        }

        $moduleStructure = $this->getDefaultModuleStructure($structureResolver);
        $this->displayStructurePreview($moduleStructure, $structureInfo);

        $confirmed = $this->confirm("Proceed with adding Structure attribute to " . count($selectedModules) . " module(s)?");
        if (!$confirmed) {
            $this->info("Cancelled.");
            return 0;
        }

        $successCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($selectedModules as $moduleInfo) {
            $hasExistingStructure = in_array($moduleInfo['name'], $modulesWithStructure ?? []);
            $shouldOverwrite = $overwriteMode && $hasExistingStructure;
            $result = $this->addStructureAttribute($moduleInfo, $moduleStructure, $shouldOverwrite);

            if ($result === 'success') {
                $successCount++;
                if ($hasExistingStructure) {
                    $this->success("✓ Updated Structure attribute for {$moduleInfo['name']}");
                } else {
                    $this->success("✓ Added Structure attribute to {$moduleInfo['name']}");
                }
            } elseif ($result === 'skipped') {
                $skippedCount++;
                $this->comment("⊘ {$moduleInfo['name']} already has Structure attribute (skipped)");
            } else {
                $errorCount++;
                $this->error("✗ Failed to add Structure attribute to {$moduleInfo['name']}: {$result}");
            }
        }

        $this->line("");
        $this->info("Summary:");
        $this->line("  Success: {$successCount}");
        if ($skippedCount > 0) {
            $this->line("  Skipped: {$skippedCount}");
        }
        if ($errorCount > 0) {
            $this->line("  Errors: {$errorCount}");
        }

        return $errorCount > 0 ? 1 : 0;
    }

    private function discoverModules(): array
    {
        $modules = [];
        $modulesPath = BASE_PATH . '/modules';

        if (!is_dir($modulesPath)) {
            return $modules;
        }

        $directories = array_filter(
            scandir($modulesPath),
            fn($item) => is_dir("$modulesPath/$item") && !in_array($item, ['.', '..'])
        );

        foreach ($directories as $directoryName) {
            $modulePath = "$modulesPath/$directoryName";
            $srcPath = "$modulePath/src";

            if (!is_dir($srcPath)) {
                continue;
            }

            $moduleClass = $this->findModuleClass($srcPath);
            if ($moduleClass) {
                $modules[] = [
                    'name' => $directoryName,
                    'className' => $moduleClass['className'],
                    'filePath' => $moduleClass['filePath'],
                ];
            }
        }

        return $modules;
    }

    private function findModuleClass(string $srcPath): ?array
    {
        $directoryIterator = new \RecursiveDirectoryIterator($srcPath);
        $iterator = new \RecursiveIteratorIterator($directoryIterator);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getRealPath();
                $namespace = $this->getNamespaceFromFile($filePath);
                if ($namespace) {
                    $className = $namespace . '\\' . pathinfo($file->getFilename(), PATHINFO_FILENAME);
                    try {
                        if (class_exists($className)) {
                            $reflectionClass = new ReflectionClass($className);
                            $attributes = $reflectionClass->getAttributes(Module::class);
                            if (!empty($attributes)) {
                                return [
                                    'className' => $className,
                                    'filePath' => $filePath,
                                ];
                            }
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }
        }

        return null;
    }

    private function getNamespaceFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if (preg_match('#^namespace\s+(.+?);#sm', $content, $match)) {
            return trim($match[1]);
        }
        return null;
    }

    private function selectModules(array $modules, ?InteractiveSelect $interactiveSelect, array $args): array
    {
        if (isset($args['module']) && !empty($args['module'])) {
            $moduleName = $args['module'];
            $selected = array_filter($modules, fn($m) => $m['name'] === $moduleName);
            return array_values($selected);
        }

        $this->line("How would you like to select modules?");
        $this->line("  1) Type module name(s)");
        $this->line("  2) Single select from list");
        $this->line("  3) Multiple select from list");
        $this->line("  4) All modules");
        $this->prompt("\033[1;36mSelect option (1-4):\033[0m ");

        $choice = trim(fgets(STDIN));
        $this->line("");

        switch ($choice) {
            case '1':
                return $this->selectByTyping($modules);

            case '2':
                return $this->selectSingle($modules, $interactiveSelect);

            case '3':
                return $this->selectMultiple($modules, $interactiveSelect);

            case '4':
                return $modules;

            default:
                $this->error("Invalid option.");
                return [];
        }
    }

    private function selectByTyping(array $modules): array
    {
        $this->prompt("\033[1;36mEnter module name(s) separated by commas:\033[0m ");
        $input = trim(fgets(STDIN));
        $this->line("");

        if (empty($input)) {
            return [];
        }

        $names = array_map('trim', explode(',', $input));
        $selected = [];

        foreach ($names as $name) {
            $found = array_filter($modules, fn($m) => $m['name'] === $name);
            if (!empty($found)) {
                $selected = array_merge($selected, array_values($found));
            } else {
                $this->warning("Module '{$name}' not found.");
            }
        }

        return $selected;
    }

    private function selectSingle(array $modules, ?InteractiveSelect $interactiveSelect): array
    {
        $options = array_map(fn($m) => $m['name'], $modules);
        $options[] = 'Cancel';

        if ($interactiveSelect) {
            $selectedIndex = $interactiveSelect->select($options, "Select a module");
            if ($selectedIndex === null || $selectedIndex === count($options) - 1) {
                return [];
            }
            return [$modules[$selectedIndex]];
        }

        foreach ($options as $index => $option) {
            $this->line("  [" . ($index + 1) . "] {$option}");
        }
        $this->prompt("\033[1;36mSelect module (1-" . count($options) . "):\033[0m ");
        $input = trim(fgets(STDIN));
        $index = (int) $input - 1;

        if ($index >= 0 && $index < count($modules)) {
            return [$modules[$index]];
        }

        return [];
    }

    private function selectMultiple(array $modules, ?InteractiveSelect $interactiveSelect): array
    {
        $options = array_map(fn($m) => $m['name'], $modules);

        if ($interactiveSelect) {
            $selectedIndices = $interactiveSelect->multiSelect($options, "Select modules (Space to toggle, Enter to confirm)");
            if ($selectedIndices === null || empty($selectedIndices)) {
                return [];
            }
            return array_values(array_intersect_key($modules, array_flip($selectedIndices)));
        }

        foreach ($options as $index => $option) {
            $this->line("  [" . ($index + 1) . "] {$option}");
        }
        $this->prompt("\033[1;36mEnter numbers separated by commas (e.g., 1,3,5):\033[0m ");
        $input = trim(fgets(STDIN));
        $numbers = array_map('trim', explode(',', $input));
        $selected = [];

        foreach ($numbers as $num) {
            $index = (int) $num - 1;
            if ($index >= 0 && $index < count($modules)) {
                $selected[] = $modules[$index];
            }
        }

        return $selected;
    }

    private function getDefaultModuleStructure(StructureResolver $structureResolver): array
    {
        $internalStructurePath = BASE_PATH . '/kernel/Core/Structure/forge_structure.php';
        $internalStructure = require $internalStructurePath;
        $defaultStructure = $internalStructure['modules'] ?? [];

        $userStructurePath = BASE_PATH . '/forge_structure.php';
        if (file_exists($userStructurePath)) {
            $userStructure = require $userStructurePath;
            if (is_array($userStructure) && isset($userStructure['modules'])) {
                $defaultStructure = array_merge($defaultStructure, $userStructure['modules']);
            }
        }

        return $defaultStructure;
    }

    private function analyzeStructure(): array
    {
        $userStructurePath = BASE_PATH . '/forge_structure.php';
        $hasUserStructure = file_exists($userStructurePath);
        $internalStructurePath = BASE_PATH . '/kernel/Core/Structure/forge_structure.php';
        $internalStructure = require $internalStructurePath;
        $internalModuleStructure = $internalStructure['modules'] ?? [];

        $userModuleStructure = [];
        $structureDiffCount = 0;
        $customKeys = [];

        if ($hasUserStructure) {
            $userStructure = require $userStructurePath;
            if (is_array($userStructure) && isset($userStructure['modules'])) {
                $userModuleStructure = $userStructure['modules'];
                foreach ($userModuleStructure as $key => $value) {
                    if (!isset($internalModuleStructure[$key]) || $internalModuleStructure[$key] !== $value) {
                        $structureDiffCount++;
                        $customKeys[] = $key;
                    }
                }
            }
        }

        return [
            'hasUserStructure' => $hasUserStructure,
            'structureDiffCount' => $structureDiffCount,
            'customKeys' => $customKeys,
            'internalModuleStructure' => $internalModuleStructure,
            'userModuleStructure' => $userModuleStructure,
        ];
    }

    private function displayStructureInfo(array $info): void
    {
        $this->line("Structure Configuration Information:");
        $this->line("");

        if (!$info['hasUserStructure']) {
            $this->info("  • No user-defined structure found");
            $this->line("    Using kernel-defined structure (recommended for module distribution)");
            $this->line("");
            $this->comment("  Benefits of kernel-defined structure:");
            $this->line("    ✓ Consistent across all Forge installations");
            $this->line("    ✓ Best for module distribution and sharing");
            $this->line("    ✓ Modules work out-of-the-box for all users");
            $this->line("");
            $this->comment("  Note: Without #[Structure] attribute, modules can be overridden");
            $this->line("        by user structure changes in forge_structure.php");
        } else {
            if ($info['structureDiffCount'] === 0) {
                $this->info("  • User structure matches kernel defaults");
                $this->line("    Using kernel-defined structure (recommended)");
                $this->line("");
                $this->comment("  Benefits: Same as kernel-defined structure above");
            } elseif ($info['structureDiffCount'] === 1) {
                $this->info("  • User structure has 1 custom key: " . $info['customKeys'][0]);
                $this->line("    Using user-defined structure");
                $this->line("");
                $this->comment("  Considerations:");
                $this->line("    ℹ  Single-key changes reduce consistency across installations");
                $this->line("    ℹ  Modules with this structure may not work for users");
                $this->line("        with different structure configurations");
                $this->line("    ℹ  Consider if this change is essential for distribution");
                $this->line("    ℹ  Best for internal/project-specific modules");
            } else {
                $this->info("  • User structure has {$info['structureDiffCount']} custom keys");
                $this->line("    Using user-defined structure");
                $this->line("");
                $this->comment("  Considerations:");
                $this->line("    ℹ  Custom structure reflects your project's organization");
                $this->line("    ℹ  Modules with this structure will maintain consistency");
                $this->line("        within your project but may differ from standard Forge");
                $this->line("    ℹ  Suitable for internal/project-specific modules");
                $this->line("    ℹ  For distribution, consider using kernel defaults");
            }
        }
    }

    private function displayStructurePreview(array $moduleStructure, array $structureInfo): void
    {
        $this->line("");
        $this->info("Structure configuration that will be applied:");
        if ($structureInfo['hasUserStructure'] && $structureInfo['structureDiffCount'] > 0) {
            $this->comment("  (User-defined structure)");
        } else {
            $this->comment("  (Kernel-defined structure)");
        }
        $this->line("");
        foreach ($moduleStructure as $type => $path) {
            $isCustom = $structureInfo['hasUserStructure'] &&
                isset($structureInfo['internalModuleStructure'][$type]) &&
                isset($structureInfo['userModuleStructure'][$type]) &&
                $structureInfo['internalModuleStructure'][$type] !== $structureInfo['userModuleStructure'][$type];
            $marker = $isCustom ? ' *' : '';
            $this->line("  {$type} => {$path}{$marker}");
        }
        if ($structureInfo['hasUserStructure'] && $structureInfo['structureDiffCount'] > 0) {
            $this->line("");
            $this->comment("  * = Customized from kernel defaults");
        }
        $this->line("");
    }

    private function checkModulesWithStructure(array $modules): array
    {
        $modulesWithStructure = [];

        foreach ($modules as $moduleInfo) {
            try {
                $reflectionClass = new ReflectionClass($moduleInfo['className']);
                $hasStructure = !empty($reflectionClass->getAttributes(Structure::class));

                if ($hasStructure) {
                    $modulesWithStructure[] = $moduleInfo['name'];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $modulesWithStructure;
    }

    private function addStructureAttribute(array $moduleInfo, array $structure, bool $overwrite = false): string
    {
        $filePath = $moduleInfo['filePath'];

        if (!file_exists($filePath)) {
            return "File not found";
        }

        $content = file_get_contents($filePath);

        try {
            $reflectionClass = new ReflectionClass($moduleInfo['className']);
            $hasStructure = !empty($reflectionClass->getAttributes(Structure::class));

            if ($hasStructure && !$overwrite) {
                return 'skipped';
            }
        } catch (\Throwable $e) {
            return "Error checking existing attribute: " . $e->getMessage();
        }

        if (strpos($content, '#[Structure') !== false && !$overwrite) {
            return 'skipped';
        }

        if ($overwrite && strpos($content, '#[Structure') !== false) {
            $content = $this->removeExistingStructureAttribute($content);
        }

        $structureArray = $this->formatStructureArray($structure);
        $structureAttribute = "use Forge\Core\Module\Attributes\Structure;\n\n";
        $structureAttributeLine = "#[Structure(structure: {$structureArray})]\n";

        if (strpos($content, 'use Forge\Core\Module\Attributes\Structure;') === false) {
            $useStatementsEnd = $this->findUseStatementsEnd($content);
            if ($useStatementsEnd !== null) {
                $content = substr_replace($content, "use Forge\Core\Module\Attributes\Structure;\n", $useStatementsEnd, 0);
            }
        }

        $attributeInsertPosition = $this->findAttributeInsertPosition($content);
        if ($attributeInsertPosition === null) {
            return "Could not find insertion point for attribute";
        }

        $content = substr_replace($content, $structureAttributeLine, $attributeInsertPosition, 0);

        if (file_put_contents($filePath, $content) === false) {
            return "Failed to write file";
        }

        return 'success';
    }

    private function removeExistingStructureAttribute(string $content): string
    {
        $lines = explode("\n", $content);
        $newLines = [];
        $skipNextEmpty = false;
        $inStructureAttribute = false;

        foreach ($lines as $line) {
            if (preg_match('/#\[Structure\(/', $line)) {
                $inStructureAttribute = true;
                $skipNextEmpty = true;
                continue;
            }

            if ($inStructureAttribute) {
                if (preg_match('/^\s*\)\]/', $line)) {
                    $inStructureAttribute = false;
                    continue;
                }
                if (preg_match('/^\s*\]/', $line) && strpos($line, 'Structure') === false) {
                    $inStructureAttribute = false;
                    continue;
                }
                if ($inStructureAttribute) {
                    continue;
                }
            }

            if ($skipNextEmpty && trim($line) === '') {
                $skipNextEmpty = false;
                continue;
            }

            $skipNextEmpty = false;
            $newLines[] = $line;
        }

        return implode("\n", $newLines);
    }

    private function findUseStatementsEnd(string $content): ?int
    {
        $lines = explode("\n", $content);
        $lastUseLine = -1;

        foreach ($lines as $index => $line) {
            if (preg_match('/^use\s+/', $line)) {
                $lastUseLine = $index;
            } elseif (trim($line) !== '' && $lastUseLine >= 0) {
                break;
            }
        }

        if ($lastUseLine >= 0) {
            $pos = 0;
            for ($i = 0; $i <= $lastUseLine; $i++) {
                $pos += strlen($lines[$i]) + 1;
            }
            return $pos;
        }

        return null;
    }

    private function findAttributeInsertPosition(string $content): ?int
    {
        if (preg_match('/#\[Repository\([^)]*\)\]\s*\n/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $matches[0][1] + strlen($matches[0][0]);
        }

        if (preg_match('/#\[Module\([^)]*\)\]\s*\n/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $matches[0][1] + strlen($matches[0][0]);
        }

        if (preg_match('/#\[Service\]\s*\n/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $matches[0][1] + strlen($matches[0][0]);
        }

        if (preg_match('/^(final\s+)?class\s+\w+/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $matches[0][1];
        }

        return null;
    }

    private function formatStructureArray(array $structure): string
    {
        $lines = ['['];
        foreach ($structure as $key => $value) {
            $lines[] = "    '{$key}' => '{$value}',";
        }
        $lines[] = ']';
        return implode("\n", $lines);
    }

    private function confirm(string $message): bool
    {
        $this->prompt("\033[1;36m{$message} (y/n):\033[0m ");
        $input = strtolower(trim(fgets(STDIN)));
        return $input === 'y' || $input === 'yes';
    }
}
