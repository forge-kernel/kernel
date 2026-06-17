<?php

declare(strict_types=1);

namespace Forge\CLI\Commands\Registry;

use Forge\CLI\Attributes\Arg;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\CliGenerator;
use Forge\Core\Services\RegistryService;
use Forge\Core\Services\TemplateGenerator;

#[Cli(
    command: 'registry:init',
    description: 'Initialize a new registry (framework or modules) with wizard',
    usage: 'dev:registry:init [--type=framework|modules]',
    examples: [
        'dev:registry:init',
        'dev:registry:init --type=modules',
    ]
)]
final class RegistryInitCommand extends Command
{
    use CliGenerator;
    
    #[Arg(name: 'type', description: 'Registry type (framework or modules)', required: false)]
    private ?string $type = null;
    
    public function __construct(
        private readonly RegistryService $registryService,
        private readonly TemplateGenerator $templateGenerator
    ) {}
    
    public function execute(array $args): int
    {
        $typeFromArgs = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--type=')) {
                $typeFromArgs = substr($arg, 7);
                break;
            }
        }
        
        if ($typeFromArgs && in_array($typeFromArgs, ['framework', 'modules'], true)) {
            $this->type = $typeFromArgs;
            
            if ($this->registryService->isRegistryDirectoryInitialized($this->type)) {
                if (!$this->showDestructiveWarning($this->type)) {
                    return 0;
                }
            }
        } else {
            $frameworkExists = $this->registryService->isRegistryDirectoryInitialized('framework');
            $modulesExists = $this->registryService->isRegistryDirectoryInitialized('modules');
            
            if ($frameworkExists || $modulesExists) {
                $messages = [];
                $messages[] = "One or more registries already exist:";
                if ($frameworkExists) {
                    $messages[] = "Framework registry: " . $this->registryService->getRegistryPath('framework');
                }
                if ($modulesExists) {
                    $messages[] = "Modules registry: " . $this->registryService->getRegistryPath('modules');
                }
                $messages[] = "Initializing will:";
                $messages[] = "Create/overwrite initial structure files";
                $messages[] = "Set/update git remote origin";
                $messages[] = "Create an initial commit (if not already committed)";
                $messages[] = "Potentially overwrite existing configuration";
                
                $this->showDangerBox('DESTRUCTIVE ACTION WARNING', $messages, 'This action may cause data loss if the registry is already in use!');
                
                $confirm = $this->templateGenerator->askQuestion(
                    'Type "yes, continue" to proceed (you will be asked which registry to initialize) or press Enter to cancel: ',
                    ''
                );
                
                if (strtolower(trim($confirm)) !== 'yes, continue') {
                    $this->info('Initialization cancelled. Existing registries left untouched.');
                    return 0;
                }
                
                $this->line("");
            }
        }
        
        $this->wizardWithoutDescription($args);
        
        if (!$this->type) {
            $this->type = $this->templateGenerator->askQuestion(
                'Registry type (framework/modules): ',
                'modules'
            );
        }
        
        if (!in_array($this->type, ['framework', 'modules'], true)) {
            $this->error('Invalid registry type. Must be "framework" or "modules".');
            return 1;
        }
        
        if (!$typeFromArgs && $this->registryService->isRegistryDirectoryInitialized($this->type)) {
            if (!$this->showDestructiveWarning($this->type)) {
                return 0;
            }
        }
        
        $url = $this->templateGenerator->askQuestion(
            'Git repository URL: ',
            ''
        );
        
        if (empty($url)) {
            $this->error('Git repository URL is required.');
            return 1;
        }
        
        $branch = $this->templateGenerator->askQuestion(
            'Branch name: ',
            'main'
        );
        
        $isPrivateInput = $this->templateGenerator->askQuestion(
            'Is this a private repository? (yes/no): ',
            'no'
        );
        $isPrivate = in_array(strtolower($isPrivateInput), ['yes', 'y', '1', 'true'], true);
        
        $this->info("Initializing {$this->type} registry...");
        
        try {
            $this->registryService->initializeRegistry(
                $this->type,
                $url,
                $branch,
                $isPrivate
            );
            
            $this->success("Registry initialized successfully!");
            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to initialize registry: " . $e->getMessage());
            return 1;
        }
    }
    
    private function showDestructiveWarning(string $type): bool
    {
        $registryPath = $this->registryService->getRegistryPath($type);
        
        $messages = [];
        $messages[] = "The {$type} registry already exists at: {$registryPath}";
        $messages[] = "This directory contains a git repository and may have existing data.";
        $messages[] = "Initializing will:";
        $messages[] = "Create/overwrite initial structure files";
        $messages[] = "Set/update git remote origin";
        $messages[] = "Create an initial commit (if not already committed)";
        $messages[] = "Potentially overwrite existing configuration";
        
        $this->showDangerBox('DESTRUCTIVE ACTION WARNING', $messages, 'This action may cause data loss if the registry is already in use!');
        
        $confirm = $this->templateGenerator->askQuestion(
            "Type \"yes, initialize\" to proceed with {$type} registry initialization (or press Enter to cancel): ",
            ''
        );
        
        if (strtolower(trim($confirm)) !== 'yes, initialize') {
            $this->info("{$type} registry initialization cancelled. Existing registry left untouched.");
            return false;
        }
        
        $this->line("");
        $this->warning("Proceeding with {$type} registry initialization...");
        $this->line("");
        
        return true;
    }
    
    private function wizardWithoutDescription(array $argv): void
    {
        $ref = new \ReflectionObject($this);

        foreach ($ref->getProperties() as $prop) {
            $attr = $prop->getAttributes(\Forge\CLI\Attributes\Arg::class)[0] ?? null;
            if (!$attr) {
                continue;
            }

            /** @var \Forge\CLI\Attributes\Arg $arg */
            $arg = $attr->newInstance();
            $value = $this->extractValue($arg->name, $argv) ?? $arg->default;

            if ($value === null && $arg->required) {
                $ref = new \ReflectionObject($this);
                $hasCli = $ref->getAttributes(\Forge\CLI\Attributes\Cli::class)[0] ?? null;

                $prompt = $arg->ask ?? ucfirst(str_replace("_", " ", $arg->name));
                if ($hasCli && $arg->description) {
                    $prompt .= " ({$arg->description})";
                }
                $prompt .= ":";

                $this->prompt("\033[1;36m$prompt\033[0m");
                $input = trim(fgets(STDIN));

                if ($input === "" && $arg->default !== null) {
                    $this->comment("Using default: $arg->default");
                    $value = $arg->default;
                } else {
                    $value = $input;
                }
            }

            if ($value === null) {
                $type = $prop->getType();
                if ($type instanceof \ReflectionNamedType && $type->getName() === 'bool') {
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
}

