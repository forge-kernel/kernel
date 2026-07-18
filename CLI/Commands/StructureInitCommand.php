<?php

declare(strict_types=1);

namespace Forge\CLI\Commands;

use Forge\CLI\Command;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Traits\OutputHelper;
use Forge\CLI\Traits\Wizard;

#[Cli(
    command: 'structure:init',
    description: 'Initialize forge_structure.php file for customizing folder structure',
    usage: 'structure:init',
    examples: [
        'structure:init'
    ]
)]
final class StructureInitCommand extends Command
{
    use OutputHelper;
    use Wizard;

    private const string INTERNAL_STRUCTURE_PATH = BASE_PATH . '/kernel/Core/Structure/forge_structure.php';
    private const string OUTPUT_FILE = BASE_PATH . '/forge_structure.php';

    private const array ALL_TYPES = [
        'controllers',
        'injectable',
        'migrations',
        'views',
        'components',
        'commands',
        'events',
        'tests',
        'models',
        'dto',
        'seeders',
        'middlewares',
    ];

    public function execute(array $args): int
    {
        $this->info("Forge Structure File Generator");
        $this->line("");
        $this->warning("IMPORTANT WARNINGS:");
        $this->line("  • Changing folder structure paths will NOT automatically move existing files");
        $this->line("  • If you delete forge_structure.php, files will NOT be moved back to default locations");
        $this->line("  • Your application may break if files are not manually moved to match the new structure");
        $this->line("  • Always backup your project before modifying structure configuration");
        $this->line("  • Modules with #[Structure] attributes will NOT be affected by this configuration");
        $this->line("    (Module-defined structures have the highest priority and are sovereign)");
        $this->line("");
        $this->comment("⚠️  Forge will trust this configuration without validation.");
        $this->comment("   You are responsible for ensuring your filesystem matches the defined structure.");
        $this->line("");
        $this->warning("This will create a forge_structure.php file in your project root.");
        $this->line("You can customize folder paths for app and modules.");
        $this->line("");

        $existingStructure = null;
        if (file_exists(self::OUTPUT_FILE)) {
            $this->warning("forge_structure.php already exists!");
            $this->line("");
            $this->line("What would you like to do?");
            $this->line("  1) Re-initialize (overwrite existing file)");
            $this->line("  2) Add new entries (merge with existing)");
            $this->prompt("\033[1;36mSelect option (1-2):\033[0m ");

            $actionChoice = trim(fgets(STDIN));
            $this->line("");

            if ($actionChoice === '2') {
                $existingStructure = require self::OUTPUT_FILE;
                if (!is_array($existingStructure)) {
                    $this->error("Existing structure file is invalid!");
                    return 1;
                }
            } elseif ($actionChoice !== '1') {
                $this->error("Invalid option. Please select 1 or 2.");
                return 1;
            }
        }

        if (!file_exists(self::INTERNAL_STRUCTURE_PATH)) {
            $this->error("Internal structure file not found!");
            return 1;
        }

        $internalStructure = require self::INTERNAL_STRUCTURE_PATH;

        $this->line("What would you like to customize?");
        $this->line("  1) Full structure (roots + app + modules)");
        $this->line("  2) App structure only");
        $this->line("  3) Modules structure only");
        $this->line("  4) Partial (select specific entries)");
        $this->line("  5) Roots & namespaces only (app_root, modules_root, etc.)");
        $this->prompt("\033[1;36mSelect option (1-5):\033[0m ");

        $choice = trim(fgets(STDIN));
        $this->line("");

        $structure = [];

        switch ($choice) {
            case '1':
                $structure = $internalStructure;
                break;

            case '2':
                $structure = [
                    'app_root' => $internalStructure['app_root'] ?? 'app',
                    'app_namespace' => $internalStructure['app_namespace'] ?? 'App',
                    'app' => $internalStructure['app'] ?? [],
                ];
                break;

            case '3':
                $structure = [
                    'modules_root' => $internalStructure['modules_root'] ?? 'modules',
                    'modules_namespace' => $internalStructure['modules_namespace'] ?? 'Modules',
                    'module_entry_files' => $internalStructure['module_entry_files'] ?? ['{name}Module.php', '{name}.php'],
                    'module_entry_glob' => $internalStructure['module_entry_glob'] ?? '*Module.php',
                    'modules' => $internalStructure['modules'] ?? [],
                ];
                break;

            case '4':
                $structure = $this->selectPartialStructure($internalStructure);
                break;

            case '5':
                $structure = $this->selectRootsAndNamespaces($internalStructure);
                break;

            default:
                $this->error("Invalid option. Please select 1-5.");
                return 1;
        }

        $isUpdate = $existingStructure !== null;
        if ($isUpdate) {
            $structure = $this->mergeStructures($existingStructure, $structure);
        }

        $this->writeStructureFile($structure);

        if ($isUpdate) {
            $this->success("forge_structure.php updated successfully!");
        } else {
            $this->success("forge_structure.php created successfully!");
        }
        $this->line("");
        $this->info("You can now edit " . self::OUTPUT_FILE . " to customize your folder structure.");
        $this->line("Run 'php forge.php structure:info' to see your current configuration.");

        return 0;
    }

    private function selectPartialStructure(array $internalStructure): array
    {
        $structure = [];

        $this->line("Select which sections to include:");
        $this->line("  a) App structure");
        $this->line("  m) Modules structure");
        $this->line("  b) Both");
        $this->prompt("\033[1;36mSelect section (a/m/b):\033[0m ");

        $sectionChoice = strtolower(trim(fgets(STDIN)));
        $this->line("");

        $includeApp = in_array($sectionChoice, ['a', 'b']);
        $includeModules = in_array($sectionChoice, ['m', 'b']);

        if (!$includeApp && !$includeModules) {
            $this->error("Invalid option. Please select a, m, or b.");
            return [];
        }

        if ($includeApp) {
            $this->line("Select app structure entries to include (comma-separated numbers, or 'all'):");
            $appTypes = $this->displayTypes('app', $internalStructure['app'] ?? []);
            $selectedApp = $this->selectTypes($appTypes, 'app');
            if (!empty($selectedApp)) {
                $structure['app'] = $selectedApp;
            }
        }

        if ($includeModules) {
            $this->line("");
            $this->line("Select modules structure entries to include (comma-separated numbers, or 'all'):");
            $moduleTypes = $this->displayTypes('modules', $internalStructure['modules'] ?? []);
            $selectedModules = $this->selectTypes($moduleTypes, 'modules');
            if (!empty($selectedModules)) {
                $structure['modules'] = $selectedModules;
            }
        }

        return $structure;
    }

    private function displayTypes(string $section, array $types): array
    {
        $index = 1;
        $typeMap = [];

        foreach ($types as $type => $path) {
            $displayPath = is_array($path) ? implode(', ', $path) : $path;
            $this->line("  {$index}) {$type} => {$displayPath}");
            $typeMap[$index] = $type;
            $index++;
        }

        return $typeMap;
    }

    private function selectTypes(array $typeMap, string $section): array
    {
        $this->prompt("\033[1;36mYour selection:\033[0m ");
        $input = trim(fgets(STDIN));

        if (strtolower($input) === 'all') {
            $internalStructure = require self::INTERNAL_STRUCTURE_PATH;
            return $internalStructure[$section] ?? [];
        }

        $selected = [];
        $numbers = array_map('trim', explode(',', $input));

        foreach ($numbers as $num) {
            $num = (int) $num;
            if (isset($typeMap[$num])) {
                $type = $typeMap[$num];
                $internalStructure = require self::INTERNAL_STRUCTURE_PATH;
                if (isset($internalStructure[$section][$type])) {
                    $selected[$type] = $internalStructure[$section][$type];
                }
            }
        }

        return $selected;
    }

    private function mergeStructures(array $existing, array $new): array
    {
        $merged = $existing;

        $rootKeys = ['app_root', 'app_namespace', 'modules_root', 'modules_namespace'];
        foreach ($rootKeys as $key) {
            if (isset($new[$key])) {
                $merged[$key] = $new[$key];
            }
        }

        if (isset($new['app'])) {
            if (!isset($merged['app'])) {
                $merged['app'] = [];
            }
            $merged['app'] = array_merge($merged['app'], $new['app']);
        }

        if (isset($new['modules'])) {
            if (!isset($merged['modules'])) {
                $merged['modules'] = [];
            }
            $merged['modules'] = array_merge($merged['modules'], $new['modules']);
        }

        return $merged;
    }

    private function selectRootsAndNamespaces(array $internalStructure): array
    {
        $structure = [];

        $this->line("Current roots and namespaces:");
        $this->line("  app_root: " . (is_array($internalStructure['app_root'] ?? null) ? implode(', ', $internalStructure['app_root']) : ($internalStructure['app_root'] ?? 'app')));
        $this->line("  app_namespace: " . ($internalStructure['app_namespace'] ?? 'App'));
        $this->line("  modules_root: " . (is_array($internalStructure['modules_root'] ?? null) ? implode(', ', $internalStructure['modules_root']) : ($internalStructure['modules_root'] ?? 'modules')));
        $this->line("  modules_namespace: " . (is_array($internalStructure['modules_namespace'] ?? null) ? implode(', ', $internalStructure['modules_namespace']) : ($internalStructure['modules_namespace'] ?? 'Modules')));
        $this->line("");

        $this->line("Leave blank to keep current value, or enter new value.");
        $this->line("For arrays, separate values with commas (e.g., 'modules,capabilities').");
        $this->line("");

        $this->prompt("\033[1;36mapp_root:\033[0m ");
        $appRoot = trim(fgets(STDIN));
        if (!empty($appRoot)) {
            $structure['app_root'] = str_contains($appRoot, ',') ? array_map('trim', explode(',', $appRoot)) : $appRoot;
        }

        $this->prompt("\033[1;36mapp_namespace:\033[0m ");
        $appNs = trim(fgets(STDIN));
        if (!empty($appNs)) {
            $structure['app_namespace'] = str_contains($appNs, ',') ? array_map('trim', explode(',', $appNs)) : $appNs;
        }

        $this->prompt("\033[1;36mmodules_root:\033[0m ");
        $modulesRoot = trim(fgets(STDIN));
        if (!empty($modulesRoot)) {
            $structure['modules_root'] = str_contains($modulesRoot, ',') ? array_map('trim', explode(',', $modulesRoot)) : $modulesRoot;
        }

        $this->prompt("\033[1;36mmodules_namespace:\033[0m ");
        $modulesNs = trim(fgets(STDIN));
        if (!empty($modulesNs)) {
            $structure['modules_namespace'] = str_contains($modulesNs, ',') ? array_map('trim', explode(',', $modulesNs)) : $modulesNs;
        }

        return $structure;
    }

    private function writeStructureFile(array $structure): void
    {
        $content = "<?php\n\nreturn " . $this->exportArray($structure, 0) . ";\n";

        file_put_contents(self::OUTPUT_FILE, $content);
    }

    private function exportValue(string|array $value, int $depth = 0): string
    {
        if (is_array($value)) {
            return $this->exportArray($value, $depth);
        }
        return "'" . addslashes($value) . "'";
    }

    private function exportArray(array $array, int $depth = 0): string
    {
        if (empty($array)) {
            return '[]';
        }

        $isAssoc = !array_is_list($array);
        $innerIndent = str_repeat('  ', $depth + 1);
        $lines = [];
        foreach ($array as $key => $item) {
            $keyStr = $isAssoc ? "'" . addslashes($key) . "' => " : '';
            if (is_array($item)) {
                $lines[] = "{$innerIndent}{$keyStr}" . $this->exportArray($item, $depth + 1) . ",";
            } else {
                $lines[] = "{$innerIndent}{$keyStr}'" . addslashes($item) . "',";
            }
        }
        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('  ', $depth) . "]";
    }
}
