<?php

declare(strict_types=1);

namespace Forge\Core\Security;

final class AssetRegistry
{
    /**
     * @var array<string, array{
     *     url: string,
     *     origin: string,
     *     type: string
     * }>
     */
    private static array $externalAssets = [];

    private function __construct()
    {
    }

    public static function registerExternal(array $asset): void
    {
        $url = $asset['url'];

        if (isset(self::$externalAssets[$url])) {
            return;
        }

        $parsed = parse_url($url);

        if (!isset($parsed['scheme'], $parsed['host'])) {
            return;
        }

        $origin = $parsed['scheme'] . '://' . $parsed['host'];

        $extension = pathinfo($parsed['path'] ?? '', PATHINFO_EXTENSION);

        $type = match ($extension) {
            'css' => 'style-src',
            'js' => 'script-src',
            default => null,
        };

        if (!$type) {
            return;
        }

        self::$externalAssets[$url] = [
            'origin' => $origin,
            'type' => $type,
        ];

        if ($type === 'style-src') {
            $fontKey = $origin . '#font';

            if (!isset(self::$externalAssets[$fontKey])) {
                self::$externalAssets[$fontKey] = [
                    'origin' => $origin,
                    'type' => 'font-src',
                ];
            }
        }
    }

    /**
     * @return array<string, array<string>>
     */
    public static function getCspSources(): array
    {
        $sources = [];

        foreach (self::$externalAssets as $asset) {
            $sources[$asset['type']][] = $asset['origin'];
        }

        foreach ($sources as $type => $origins) {
            $sources[$type] = array_values(array_unique($origins));
        }

        return $sources;
    }

    public static function reset(): void
    {
        self::$externalAssets = [];
    }
}