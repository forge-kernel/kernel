<?php

declare(strict_types=1);

namespace Forge\Traits;

use Forge\Core\Autoloader;

trait NamespaceHelper
{
  private function getNamespaceFromFile(string $filePath, string $basePath): ?string
  {
    $content = file_get_contents($filePath);
    if (preg_match('#^namespace\s+(.+?);#sm', $content, $match)) {
      return trim($match[1]);
    }
    return null;
  }

  private function registerModuleAutoloadPath(string $moduleName, string $modulePath): void
  {
    $moduleNamespacePrefix = 'App\\Modules\\' . str_replace('-', '\\', $moduleName);

    $container = \Forge\Core\DI\Container::getInstance();
    $basePath = $modulePath . '/src';

    if ($container->has(\Forge\Core\Structure\StructureResolver::class)) {
      try {
        $structureResolver = $container->get(\Forge\Core\Structure\StructureResolver::class);
        $controllersPath = $structureResolver->getModulePath($moduleName, 'controllers');
        if (str_starts_with($controllersPath, 'src/')) {
          $basePath = $modulePath . '/src';
        } else {
          $basePath = $modulePath;
        }
      } catch (\InvalidArgumentException $e) {
      }
    }

    Autoloader::addPath($moduleNamespacePrefix . '\\', $basePath);
  }

  private function getClassNameFromFile(string $path): ?string
  {
    $contents = file_get_contents($path);
    $tokens = token_get_all($contents);

    $namespace = $class = '';
    $classFound = false;
    $namespaceFound = false;

    foreach ($tokens as $index => $token) {
      if ($token[0] === T_NAMESPACE && !$namespaceFound) {
        $namespace = '';
        for ($i = $index + 1; $i < count($tokens); $i++) {
          if ($tokens[$i] === ';') {
            break;
          }
          if (is_array($tokens[$i])) {
            $namespace .= $tokens[$i][1];
          }
        }
        $namespace = trim($namespace);
        $namespaceFound = true;
      }

      if ($token[0] === T_CLASS && !$classFound) {
        for ($i = $index + 1; $i < count($tokens); $i++) {
          if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
            $class = $tokens[$i][1];
            $classFound = true;
            break;
          }
        }
      }

      if ($namespaceFound && $classFound) {
        break;
      }
    }

    if (!$class) {
      return null;
    }

    return $namespace ? "{$namespace}\\{$class}" : $class;
  }
}
