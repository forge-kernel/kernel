<?php

declare(strict_types=1);

namespace Forge\Core\Module\ModuleLoader;

use Forge\Core\Module\Attributes\Structure;
use Forge\Core\Structure\StructureResolver;
use ReflectionClass;

final readonly class RegisterModuleStructure
{
  public function __construct(
    private StructureResolver $structureResolver,
    private ReflectionClass $reflectionClass
  ) {
  }

  public function init(): void
  {
    $this->registerModuleStructure();
  }

  private function registerModuleStructure(): void
  {
    $structureAttribute = $this->reflectionClass->getAttributes(Structure::class)[0] ?? null;
    if ($structureAttribute) {
      $structure = $structureAttribute->newInstance()->structure;
      $moduleName = $this->extractModuleName();
      if ($moduleName) {
        $this->structureResolver->registerModuleStructure($moduleName, $structure);
      }
    }
  }

  private function extractModuleName(): ?string
  {
    $className = $this->reflectionClass->getName();
    $namespaces = StructureResolver::resolveModulesNamespaces();
    foreach ($namespaces as $ns) {
      $pattern = '/' . preg_quote($ns, '/') . '\\\\([^\\\\]+)\\\\/';
      if (preg_match($pattern, $className, $matches)) {
        return $matches[1];
      }
    }
    return null;
  }
}
