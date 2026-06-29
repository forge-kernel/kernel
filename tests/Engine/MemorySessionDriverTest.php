<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use Modules\ForgeTesting\Attributes\AfterEach;
use Modules\ForgeTesting\Attributes\BeforeEach;
use Modules\ForgeTesting\Attributes\Group;
use Modules\ForgeTesting\Attributes\Test;
use Modules\ForgeTesting\TestCase;
use Forge\Core\Session\Drivers\MemorySessionDriver;

#[Group('session')]
final class MemorySessionDriverTest extends TestCase
{
    private MemorySessionDriver $driver;

    #[BeforeEach]
    public function setup(): void
    {
        $this->driver = new MemorySessionDriver();
    }

    #[Test('open always returns true')]
    public function open_returns_true(): void
    {
        $this->assertTrue($this->driver->open('/tmp', 'PHPSESSID'));
    }

    #[Test('close always returns true')]
    public function close_returns_true(): void
    {
        $this->assertTrue($this->driver->close());
    }

    #[Test('write stores data and read retrieves it')]
    public function write_and_read(): void
    {
        $this->driver->write('sess1', 'serialized_data');
        $this->assertEquals('serialized_data', $this->driver->read('sess1'));
    }

    #[Test('read returns empty string for unknown session')]
    public function read_unknown_returns_empty(): void
    {
        $this->assertEquals('', $this->driver->read('nonexistent'));
    }

    #[Test('destroy removes session data')]
    public function destroy_removes_data(): void
    {
        $this->driver->write('sess_del', 'data');
        $this->driver->destroy('sess_del');
        $this->assertEquals('', $this->driver->read('sess_del'));
    }

    #[Test('destroy returns true')]
    public function destroy_returns_true(): void
    {
        $this->assertTrue($this->driver->destroy('any_id'));
    }

    #[Test('gc always returns 0')]
    public function gc_returns_zero(): void
    {
        $this->assertEquals(0, $this->driver->gc(3600));
    }

    #[Test('multiple sessions stored independently')]
    public function multiple_sessions_independent(): void
    {
        $this->driver->write('s1', 'data_one');
        $this->driver->write('s2', 'data_two');
        $this->assertEquals('data_one', $this->driver->read('s1'));
        $this->assertEquals('data_two', $this->driver->read('s2'));
    }
}
