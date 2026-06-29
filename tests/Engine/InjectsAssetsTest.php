<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use Modules\ForgeTesting\Attributes\BeforeEach;
use Modules\ForgeTesting\Attributes\Group;
use Modules\ForgeTesting\Attributes\Test;
use Modules\ForgeTesting\TestCase;
use Modules\ForgeRouter\Http\Response;
use Forge\Traits\InjectsAssets;

#[Group('core')]
final class InjectsAssetsTest extends TestCase
{
    private $testClass;

    #[BeforeEach]
    public function setUp(): void
    {
        parent::setUp();
        $this->testClass = new class {
            use InjectsAssets;

            public function clearAssets()
            {
                $this->assetsToInject = [];
            }

            public function callRegisterAsset(string $assetHtml, string $beforeTag = '</body>')
            {
                $this->registerAsset($assetHtml, $beforeTag);
            }

            public function callInjectAssets(Response $response)
            {
                $this->injectAssets($response);
            }
        };
    }

    #[Test('registerAsset prevents duplicates in memory')]
    public function register_asset_prevents_memory_duplicates(): void
    {
        $asset = '<script src="test.js"></script>';
        $this->testClass->callRegisterAsset($asset);
        $this->testClass->callRegisterAsset($asset);

        $response = new Response('<html><body></body></html>');
        $response->setHeader('Content-Type', 'text/html');

        $this->testClass->callInjectAssets($response);

        $content = $response->getContent();
        $occurrences = substr_count($content, $asset);

        $this->assertEquals(1, $occurrences, 'Should only inject the asset once');
    }

    #[Test('injectAssets is idempotent (already in content)')]
    public function inject_assets_idempotent_against_existing_content(): void
    {
        $asset = '<script src="existing.js"></script>';
        $content = "<html><body>$asset</body></html>";
        $response = new Response($content);
        $response->setHeader('Content-Type', 'text/html');

        $this->testClass->callRegisterAsset($asset);
        $this->testClass->callInjectAssets($response);

        $newContent = $response->getContent();
        $occurrences = substr_count($newContent, $asset);

        $this->assertEquals(1, $occurrences, 'Should not inject asset if already present in content');
    }
}
