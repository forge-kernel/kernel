<?php
declare(strict_types=1);

namespace Forge\Core\Helpers;

final class Path
{
    public static function resolve(string ...$segments): string
    {
        $segments = array_filter($segments, fn($segment) => $segment !== '');
        if ($segments === []) {
            return '';
        }

        $first = array_shift($segments);

        $segments = array_map(
            fn(string $segment) => trim(str_replace('\\', '/', $segment), '/'),
            $segments
        );

        return $first . (empty($segments) ? '' : '/' . implode('/', $segments));
    }
}
