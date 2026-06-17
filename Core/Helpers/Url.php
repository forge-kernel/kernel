<?php

declare(strict_types=1);

namespace Forge\Core\Helpers;

final class Url
{
  private const UPLOAD_PATH = "file/uploads";

  public static function generateLinks(int $page, int $perPage, int $totalPages, ?string $orderBy = 'created_at', ?string $sortBy = 'ASC'): array
  {
    $baseUrl = self::baseUrl();
    $links = [];
    $links['self'] = "$baseUrl?page=$page&per_page=$perPage&order=$orderBy&sort=$sortBy";

    if ($page > 1) {
      $links['first'] = "$baseUrl?page=1&per_page=$perPage&order=$orderBy&sort=$sortBy";
      $links['prev'] = "$baseUrl?page=" . ($page - 1) . "&per_page=$perPage&order=$orderBy&sort=$sortBy";
    }

    if ($page < $totalPages) {
      $links['next'] = "$baseUrl?page=" . ($page + 1) . "&per_page=$perPage&order=$orderBy&sort=$sortBy";
      $links['last'] = "$baseUrl?page=$totalPages&per_page=$perPage&order=$orderBy&sort=$sortBy";
    }

    return $links;
  }

  public static function getUrl(?string $path = self::UPLOAD_PATH): string
  {
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER["HTTP_HOST"] . '/' . $path;
    return "$scheme://$host";
  }

  public static function baseUrl(?string $path = ''): string
  {
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER["HTTP_HOST"] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $basePath = strtok($uri, '?');
    $basePath = $basePath !== false ? $basePath : '/';
    return "$scheme://$host$basePath";
  }
}
