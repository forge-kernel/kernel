<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use Modules\ForgeTesting\Attributes\AfterEach;
use Modules\ForgeTesting\Attributes\BeforeEach;
use Modules\ForgeTesting\Attributes\Group;
use Modules\ForgeTesting\Attributes\Test;
use Modules\ForgeTesting\TestCase;
use Forge\Core\Helpers\Flash;

#[Group('helpers')]
final class FlashTest extends TestCase
{
    #[BeforeEach]
    public function setup(): void
    {
        $_SESSION = [];
    }

    #[AfterEach]
    public function cleanup(): void
    {
        $_SESSION = [];
    }

    #[Test('Flash::set stores a value in session')]
    public function set_stores_value(): void
    {
        Flash::set('success', 'Saved!');
        $this->assertEquals('Saved!', $_SESSION['_flash']['success']);
    }

    #[Test('Flash::has returns true when key exists')]
    public function has_returns_true(): void
    {
        Flash::set('info', 'Note');
        $this->assertTrue(Flash::has('info'));
    }

    #[Test('Flash::has returns false when key missing')]
    public function has_returns_false(): void
    {
        $this->assertFalse(Flash::has('missing'));
    }

    #[Test('Flash::get returns value and removes it')]
    public function get_removes_after_read(): void
    {
        Flash::set('error', 'Oh no');
        $value = Flash::get('error');
        $this->assertEquals('Oh no', $value);
        $this->assertFalse(Flash::has('error'));
    }

    #[Test('Flash::get returns default when missing')]
    public function get_returns_default(): void
    {
        $value = Flash::get('nope', 'fallback');
        $this->assertEquals('fallback', $value);
    }

    #[Test('Flash::all returns all messages and clears them')]
    public function all_clears_all(): void
    {
        Flash::set('a', 'msg1');
        Flash::set('b', 'msg2');
        $all = Flash::all();
        $this->assertEquals('msg1', $all['a']);
        $this->assertEquals('msg2', $all['b']);
        $this->assertFalse(Flash::has('a'));
        $this->assertFalse(Flash::has('b'));
    }

    #[Test('Flash::flat returns scalar values as type/message pairs')]
    public function flat_scalar_values(): void
    {
        Flash::set('success', 'Done');
        $flat = Flash::flat();
        $this->assertEquals(1, count($flat));
        $this->assertEquals('success', $flat[0]['type']);
        $this->assertEquals('Done', $flat[0]['message']);
    }

    #[Test('Flash::flat flattens array values recursively')]
    public function flat_array_values(): void
    {
        Flash::set('errors', ['field1' => 'required', 'field2' => 'invalid']);
        $flat = Flash::flat();
        $this->assertEquals(2, count($flat));
        foreach ($flat as $item) {
            $this->assertEquals('errors', $item['type']);
        }
    }
}
