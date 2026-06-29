<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use Modules\ForgeTesting\Attributes\Group;
use Modules\ForgeTesting\Attributes\Test;
use Modules\ForgeTesting\TestCase;
use Forge\Core\Helpers\Html;

#[Group('helpers')]
final class HtmlTest extends TestCase
{
    #[Test('Html::link generates a link tag with href and rel')]
    public function link_basic(): void
    {
        $tag = Html::link('/style.css');
        $this->assertTrue(str_contains($tag, "href='/style.css'"));
        $this->assertTrue(str_contains($tag, "rel='stylesheet'"));
    }

    #[Test('Html::link includes integrity when provided')]
    public function link_with_integrity(): void
    {
        $tag = Html::link('/style.css', 'sha256-abc123');
        $this->assertTrue(str_contains($tag, "integrity='sha256-abc123'"));
    }

    #[Test('Html::link includes crossorigin when provided')]
    public function link_with_crossorigin(): void
    {
        $tag = Html::link('/style.css', null, 'anonymous');
        $this->assertTrue(str_contains($tag, "crossorigin='anonymous'"));
    }

    #[Test('Html::script generates a script tag with src')]
    public function script_basic(): void
    {
        $tag = Html::script('/app.js');
        $this->assertTrue(str_contains($tag, "src='/app.js'"));
        $this->assertTrue(str_contains($tag, '<script'));
        $this->assertTrue(str_contains($tag, '</script>'));
    }

    #[Test('Html::script includes defer by default')]
    public function script_has_defer_by_default(): void
    {
        $tag = Html::script('/app.js');
        $this->assertTrue(str_contains($tag, 'defer'));
    }

    #[Test('Html::script omits defer when disabled')]
    public function script_without_defer(): void
    {
        $tag = Html::script('/app.js', null, null, false);
        $this->assertFalse(str_contains($tag, 'defer'));
    }

    #[Test('Html::script includes integrity when provided')]
    public function script_with_integrity(): void
    {
        $tag = Html::script('/app.js', 'sha256-xyz');
        $this->assertTrue(str_contains($tag, "integrity='sha256-xyz'"));
    }
}
