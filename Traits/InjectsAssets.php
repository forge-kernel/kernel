<?php

declare(strict_types=1);

namespace Forge\Traits;

trait InjectsAssets
{
    /**
     * Stores assets grouped by the tag they should be placed before (e.g., '</body>', '</head>').
     * * @var array<string, array<string>>
     */
    protected array $assetsToInject = [];

    /**
     * Registers an asset (script, link, meta, etc.) to be injected into the response.
     *
     * @param string $assetHtml The full HTML string of the asset (e.g., '<script src="..."></script>').
     * @param string $beforeTag The closing tag to inject the asset before (e.g., '</body>', '</head>').
     * @return void
     */
    protected function registerAsset(string $assetHtml, string $beforeTag = '</body>'): void
    {
        $normalizedTag = strtolower($beforeTag);

        if (!in_array($assetHtml, $this->assetsToInject[$normalizedTag] ?? [])) {
            $this->assetsToInject[$normalizedTag][] = $assetHtml;
        }
    }

    /**
     * Executes the injection of all registered assets into the Response content.
     * This method should be called in the LifecycleHookName::AFTER_REQUEST hook.
     * * @param Response $response The response object to modify.
     * @return void
     */
    protected function injectAssets(object $response): void
    {
        $contentType = $response->getHeader('Content-Type') ?? '';

        if (!str_contains(strtolower($contentType), 'text/html')) {
            $content = $response->getContent();

            if (str_starts_with(trim($content), '{"html":')) {
                return;
            }

            if (!str_starts_with(trim($content), '<!DOCTYPE html>')) {
                return;
            }
        }

        if (empty($this->assetsToInject)) {
            return;
        }

        $content = $response->getContent();
        $injectedIntoBody = false;

        foreach ($this->assetsToInject as $tag => $assets) {
            $filteredAssets = [];
            foreach ($assets as $asset) {
                if (!str_contains($content, $asset)) {
                    $filteredAssets[] = $asset;
                }
            }

            if (empty($filteredAssets)) {
                if ($tag === '</body>') {
                    $injectedIntoBody = true;
                }
                continue;
            }

            $assetBlock = implode("\n", $filteredAssets) . "\n";
            $replacements = 0;

            $content = preg_replace(
                '/' . preg_quote($tag, '/') . '/i',
                $assetBlock . $tag,
                $content,
                1,
                $replacements
            );

            if ($tag === '</body>' && $replacements > 0) {
                $injectedIntoBody = true;
            }
        }

        if (isset($this->assetsToInject['</body>']) && !$injectedIntoBody) {
            $content .= implode("\n", $this->assetsToInject['</body>']);
        }

        $response->setContent($content);
    }
}
