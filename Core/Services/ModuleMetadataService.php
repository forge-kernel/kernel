<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\DI\Attributes\Service;

#[Service]
final class ModuleMetadataService
{
  public function extractFromFile(string $filePath): ?array
  {
    if (!file_exists($filePath)) {
      return null;
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
      return null;
    }

    $metadata = [
      'name' => null,
      'version' => '0.1.0',
      'description' => null,
      'author' => null,
      'license' => null,
      'tags' => null,
    ];

    $attributeContent = $this->extractModuleAttributeContent($content);
    if ($attributeContent === null) {
      return null;
    }

    $metadata['name'] = $this->extractStringParameter($attributeContent, 'name');
    $version = $this->extractStringParameter($attributeContent, 'version');
    if ($version !== null) {
      $metadata['version'] = $version;
    }
    $metadata['description'] = $this->extractStringParameter($attributeContent, 'description');
    $metadata['author'] = $this->extractStringParameter($attributeContent, 'author');
    $metadata['license'] = $this->extractStringParameter($attributeContent, 'license');

    $tagsValue = $this->extractArrayParameter($attributeContent, 'tags');
    if ($tagsValue !== null) {
      $metadata['tags'] = $tagsValue;
    }

    return $metadata;
  }

  public function getLatestVersion(string $moduleName, string $manifestPath): ?string
  {
    if (!file_exists($manifestPath)) {
      return null;
    }

    $content = file_get_contents($manifestPath);
    if ($content === false) {
      return null;
    }

    $manifest = json_decode($content, true);
    if (!is_array($manifest)) {
      return null;
    }

    $moduleNameKebab = $this->toKebabCase($moduleName);
    return $manifest[$moduleNameKebab]['latest'] ?? null;
  }

  public function getModuleInfo(string $moduleName, string $registryPath): array
  {
    $modulePath = $registryPath . '/modules/' . $this->toKebabCase($moduleName);
    if (!is_dir($modulePath)) {
      return [];
    }

    $versions = [];
    $dirs = glob($modulePath . '/*', GLOB_ONLYDIR);
    foreach ($dirs as $dir) {
      $version = basename($dir);
      if (preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        $versions[] = $version;
      }
    }

    usort($versions, 'version_compare');
    $latestVersion = !empty($versions) ? end($versions) : null;

    return [
      'name' => $moduleName,
      'latest_version' => $latestVersion,
      'versions' => $versions,
    ];
  }

  private function extractStringParameter(string $content, string $paramName): ?string
  {
    $pattern = "/{$paramName}\s*:\s*['\"]([^'\"]+)['\"]/s";
    if (preg_match($pattern, $content, $matches)) {
      return $matches[1];
    }

    return null;
  }

  private function extractArrayParameter(string $content, string $paramName): ?array
  {
    $pattern = "/{$paramName}\s*:\s*\[([^\]]+)\]/s";
    if (!preg_match($pattern, $content, $matches)) {
      return null;
    }

    $arrayContent = $matches[1];
    if (empty(trim($arrayContent))) {
      return [];
    }

    $items = [];
    $currentItem = '';
    $inQuotes = false;
    $quoteChar = null;
    $length = strlen($arrayContent);

    for ($i = 0; $i < $length; $i++) {
      $char = $arrayContent[$i];

      if (($char === '"' || $char === "'") && ($i === 0 || $arrayContent[$i - 1] !== '\\')) {
        if (!$inQuotes) {
          $inQuotes = true;
          $quoteChar = $char;
        } elseif ($char === $quoteChar) {
          $inQuotes = false;
          $quoteChar = null;
        }
        $currentItem .= $char;
      } elseif ($char === ',' && !$inQuotes) {
        $item = trim($currentItem, " \t\n\r\0\x0B'\"");
        if (!empty($item)) {
          $items[] = $item;
        }
        $currentItem = '';
      } else {
        $currentItem .= $char;
      }
    }

    if (!empty(trim($currentItem))) {
      $item = trim($currentItem, " \t\n\r\0\x0B'\"");
      if (!empty($item)) {
        $items[] = $item;
      }
    }

    return $items;
  }

  private function toKebabCase(string $string): string
  {
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $string));
  }

  /**
   * Extract Module attribute content handling multi-line attributes with balanced parentheses.
   * Handles nested parentheses, strings (single and double quotes), and closing parenthesis on new lines.
   */
  private function extractModuleAttributeContent(string $content): ?string
  {
    $startPattern = '/#\[Module\s*\(/s';
    if (!preg_match($startPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
      return null;
    }

    $startPos = $matches[0][1] + strlen($matches[0][0]);
    $length = strlen($content);
    $depth = 1;
    $inQuotes = false;
    $quoteChar = null;
    $attributeStart = $startPos;

    for ($i = $startPos; $i < $length; $i++) {
      $char = $content[$i];
      $prevChar = $i > 0 ? $content[$i - 1] : '';

      if (($char === '"' || $char === "'") && $prevChar !== '\\') {
        if (!$inQuotes) {
          $inQuotes = true;
          $quoteChar = $char;
        } elseif ($char === $quoteChar) {
          $inQuotes = false;
          $quoteChar = null;
        }
        continue;
      }

      if (!$inQuotes) {
        if ($char === '(') {
          $depth++;
        } elseif ($char === ')') {
          $depth--;
          if ($depth === 0) {
            return substr($content, $attributeStart, $i - $attributeStart);
          }
        }
      }
    }

    return null;
  }
}
