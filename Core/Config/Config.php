<?php

declare(strict_types=1);

namespace Forge\Core\Config;

use Forge\Core\Helpers\FileExistenceCache;
use InvalidArgumentException;

final class Config
{
  private array $config;
  private string $configPath;

  /** @var array<string, array{data: array, mtime: int}> */
  private static array $configCache = [];

  public function __construct(string $configPath)
  {
    $this->configPath = $configPath;
    $this->loadApplicationConfig();
  }

  private function loadApplicationConfig(): void
  {
    $cacheKey = $this->configPath;

    if (isset(self::$configCache[$cacheKey])) {
      $cached = self::$configCache[$cacheKey];
      if ($this->isCacheValid($cached['mtime'], $cached['files'], $cached['filesGlob'] ?? null)) {
        $this->config = $cached['data'];
        return;
      }
    }

    $files = glob($this->configPath . '/*.php');

    if ($files === false) {
      $this->config = [];
      return;
    }

    $latestMtime = 0;
    $this->config = [];
    $fileList = [];

    if (!empty($files)) {
      FileExistenceCache::preload($files);
    }

    foreach ($files as $file) {
      $filename = basename($file, '.php');
      $fileMtime = FileExistenceCache::getMtime($file);

      if ($fileMtime !== null) {
        $latestMtime = max($latestMtime, $fileMtime);
        $fileList[$file] = $fileMtime;
      }

      $configData = require $file;
      if (!is_array($configData)) {
        throw new InvalidArgumentException("Configuration file '{$filename}' must return an array");
      }
      $this->config[$filename] = $configData;
    }

    self::$configCache[$cacheKey] = [
      'data' => $this->config,
      'mtime' => $latestMtime,
      'files' => $fileList,
      'filesGlob' => $files,
    ];
  }

  /**
   * Check if the cached config is still valid by comparing modification times.
   *
   * @param int $cachedMtime The modification time when cache was created
   * @param array<string, int> $cachedFiles File list with mtimes from when cache was created
   * @param array|null $cachedGlob Previously cached glob result to avoid duplicate glob() calls
   * @return bool True if cache is still valid, false if files have changed
   */
  private function isCacheValid(int $cachedMtime, array $cachedFiles, ?array $cachedGlob): bool
  {
    if (!empty($cachedFiles)) {
      $filePaths = array_keys($cachedFiles);
      FileExistenceCache::preload($filePaths);
    }

    foreach ($cachedFiles as $file => $fileMtime) {
      $currentMtime = FileExistenceCache::getMtime($file);
      if ($currentMtime === null || $currentMtime > $fileMtime) {
        return false;
      }
    }

    if ($cachedGlob === null) {
      $currentFiles = glob($this->configPath . '/*.php');
      if ($currentFiles === false) {
        return false;
      }
      $cachedGlob = $currentFiles;
    }

    if (count($cachedGlob) !== count($cachedFiles)) {
      return false;
    }

    return true;
  }

  /**
   * Clear the config cache for a specific path or all paths.
   * Useful for testing or when config files are modified programmatically.
   *
   * @param string|null $configPath If provided, clears cache for this path only. If null, clears all caches.
   */
  public static function clearCache(?string $configPath = null): void
  {
    if ($configPath === null) {
      self::$configCache = [];
    } else {
      unset(self::$configCache[$configPath]);
    }
  }

  public function get(string $key, mixed $default = null): mixed
  {
    if (str_contains($key, '.')) {
      $keys = explode('.', $key);
      $value = $this->config;
      foreach ($keys as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
          return $default;
        }
        $value = $value[$segment];
      }
      return $value;
    }

    return $this->config[$key] ?? $default;
  }

  public function set(string $key, mixed $value): void
  {
    if (str_contains($key, '.')) {
      $keys = explode('.', $key);
      $current = &$this->config;
      $lastSegment = array_pop($keys);

      foreach ($keys as $segment) {
        if (!isset($current[$segment]) || !is_array($current[$segment])) {
          $current[$segment] = [];
        }
        $current = &$current[$segment];
      }
      $current[$lastSegment] = $value;
      return;
    }

    $this->config[$key] = $value;
  }

  public function merge(string $key, array $data): void
  {
    if (isset($this->config[$key]) && is_array($this->config[$key])) {
      $this->config[$key] = $this->arrayMergeReplace($this->config[$key], $data);
    } else {
      $this->config[$key] = $data;
    }
  }

  private function arrayMergeReplace(array $existing, array $new): array
  {
    foreach ($new as $key => $value) {
      if (isset($existing[$key]) && is_array($existing[$key]) && is_array($value)) {
        $existing[$key] = $this->arrayMergeReplace($existing[$key], $value);
      } else {
        $existing[$key] = $value;
      }
    }
    return $existing;
  }

  public function mergeModuleDefaults(array $defaults): void
  {
    foreach ($defaults as $key => $value) {
      if (!isset($this->config[$key])) {
        $this->config[$key] = $value;
      } elseif (is_array($value) && is_array($this->config[$key])) {
        $this->config[$key] = $this->arrayMergeAddMissing($this->config[$key], $value);
      }
    }
  }

  private function arrayMergeAddMissing(array $existing, array $defaults): array
  {
    if (empty($existing)) {
      return $existing;
    }

    foreach ($defaults as $key => $value) {
      if (!array_key_exists($key, $existing)) {
        $existing[$key] = $value;
      } elseif (is_array($value) && is_array($existing[$key])) {
        $existing[$key] = $this->arrayMergeAddMissing($existing[$key], $value);
      }
    }
    return $existing;
  }

  public function getConfigPath(): string
  {
    return $this->configPath;
  }
}
