<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Generate;

use Exception;
use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Traits\StringHelper;
use Forge\Core\Services\TemplateGenerator;
use Forge\Core\Structure\StructureResolver;

#[Cli(
    command: 'generate:module',
    description: 'Create a new module with basic structure',
    usage: 'generate:module [--name=ModuleName] [--description="Module description"] [--version=1.0.0]',
    examples: [
        'generate:module --name=Blog',
        'generate:module --name=Blog --description="Blog module" --version=1.0.0',
        'generate:module   (starts wizard)',
    ]
)]
final class GenerateModuleCommand extends Command
{
    use StringHelper;
    use CliGenerator;

    #[Arg(name: 'name', description: 'Module name in PascalCase (without suffix)', validate: '/^\w+$/')]
    private string $name;

    #[Arg(name: 'description', description: 'Module description', required: false)]
    private ?string $description = null;

    #[Arg(name: 'version', description: 'Module version (e.g., 0.1.0)', default: '0.1.0', required: false)]
    private string $version = '0.1.0';

    #[Arg(name: 'category', description: 'Module category: module (default) or capability', validate: '/^(module|capability)$/', required: false)]
    private string $category = 'module';

    public function __construct(
        private readonly TemplateGenerator $templateGenerator,
        private readonly StructureResolver $structureResolver
    ) {
    }

    /**
     * @throws Exception
     */
    public function execute(array $args): int
    {
        $this->wizard($args);

        $this->name = $this->toPascalCase($this->name);
        if (!$this->isPascalCase($this->name)) {
            $this->error("Invalid module name. Use PascalCase (e.g., MyModule).");
            return 1;
        }

        if (!$this->description) {
            $this->description = $this->templateGenerator->askQuestion(
                "Module description: ",
                "A brief description of the module."
            );
        }

        if (!$this->version) {
            $this->version = $this->templateGenerator->askQuestion(
                "Module version (e.g., 1.0.0): ",
                "1.0.0"
            );
        }

        $includeHttp = $this->templateGenerator->askQuestion(
            "Need HTTP routes (controllers, middlewares)? (y/n): ",
            "y"
        );
        $includeHttp = strtolower(trim($includeHttp)) === 'y';

        $moduleDir = BASE_PATH . '/' . $this->getModulesRootForCategory($this->category) . '/' . $this->name;
        if (is_dir($moduleDir)) {
            $this->error("Module directory already exists: $moduleDir");
            return 1;
        }

        $moduleStructure = $this->getDefaultModuleStructure();
        if ($includeHttp) {
            $moduleStructure['controllers'] = 'src/Controllers';
            $moduleStructure['middlewares'] = 'src/Middlewares';
        }
        $this->createModuleDirectories($moduleDir, $moduleStructure);

        $persistStructure = $this->templateGenerator->askQuestion(
            "Persist structure configuration? (y/n): ",
            "n"
        );
        $persistStructure = strtolower(trim($persistStructure)) === 'y';

        $this->generateModuleFiles($moduleDir, $moduleStructure, $persistStructure);

        $this->success("Module '{$this->name}' created successfully!");
        if ($persistStructure) {
            $this->info("Structure configuration has been persisted in the module class.");
        }
        return 0;
    }

    private function getModulesRootForCategory(string $category): string
    {
        $roots = $this->structureResolver->getModulesRoots();
        $namespaces = $this->structureResolver->getModulesNamespaces();

        $categoryLower = strtolower($category);
        foreach ($roots as $i => $root) {
            if (strtolower($namespaces[$i] ?? '') === $categoryLower) {
                return $root;
            }
        }

        return $roots[0] ?? 'modules';
    }

    private function getModulesNamespaceForCategory(string $category): string
    {
        $namespaces = $this->structureResolver->getModulesNamespaces();

        $categoryLower = strtolower($category);
        foreach ($namespaces as $ns) {
            if (strtolower($ns) === $categoryLower) {
                return $ns;
            }
        }

        return $namespaces[0] ?? 'Modules';
    }

    private function getDefaultModuleStructure(): array
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

    private function createModuleDirectories(string $moduleDir, array $structure): void
    {
        foreach ($structure as $path) {
            $fullPath = $moduleDir . '/' . $path;
            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
        }

        $contractsDir = $moduleDir . '/src/Contracts';
        if (!is_dir($contractsDir)) {
            mkdir($contractsDir, 0755, true);
        }
    }

    private function generateModuleFiles(string $moduleDir, array $moduleStructure, bool $persistStructure): void
    {
        $structureAttribute = '';
        if ($persistStructure) {
            $structureArray = $this->formatStructureArray($moduleStructure);
            $structureAttribute = "use Forge\Core\Module\Attributes\Structure;\n\n";
            $structureAttribute .= "#[Structure(structure: {$structureArray})]\n";
        }

        $moduleNamespace = $this->getModulesNamespaceForCategory($this->category);

        $tokens = [
            '{{ moduleName }}' => $this->name,
            '{{ moduleNamespace }}' => $moduleNamespace,
            '{{ command }}' => $this->toKebabCase($this->name),
            '{{ interfaceName }}' => $this->name . 'Interface',
            '{{ serviceName }}' => $this->name . 'Service',
            '{{ moduleDescription }}' => $this->description,
            '{{ moduleVersion }}' => $this->version,
            '{{ moduleConfig }}' => $this->toSnakeCase($this->name),
            '{{ structureAttribute }}' => $structureAttribute,
            '{{ frameworkVersion }}' => KERNEL_VERSION,
            '{{ category }}' => $this->category,
        ];

        $commandsPath = $moduleStructure['commands'] ?? 'src/Commands';
        $servicesPath = $moduleStructure['injectable'] ?? 'src/Services';
        $contractsPath = 'src/Contracts';
        $modulePath = 'src';

        $this->generateFromStub(
            'modules/command',
            $moduleDir . '/' . $commandsPath . '/' . $this->name . 'Command.php',
            $tokens
        );

        $this->generateFromStub(
            'modules/interface',
            $moduleDir . '/' . $contractsPath . '/' . $this->name . 'Interface.php',
            $tokens
        );

        $this->generateFromStub(
            'modules/service',
            $moduleDir . '/' . $servicesPath . '/' . $this->name . 'Service.php',
            $tokens
        );

        $this->generateFromStub(
            'modules/module',
            $moduleDir . '/' . $modulePath . '/' . $this->name . 'Module.php',
            $tokens
        );
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
}
