<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use Forge\Core\Helpers\UUID;
use InvalidArgumentException;

#[Group('helpers')]
final class UUIDTest extends TestCase
{
    #[Test('UUID v4 matches standard format')]
    public function uuid_v4_format(): void
    {
        $uuid = UUID::generate('uuid');
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $this->assertTrue((bool) preg_match($pattern, $uuid));
    }

    #[Test('UUID v1 matches standard format')]
    public function uuid_v1_format(): void
    {
        $uuid = UUID::generate('uuid', ['version' => 1]);
        $this->assertTrue(strlen($uuid) === 36);
        $this->assertEquals(4, substr_count($uuid, '-'));
    }

    #[Test('NanoId default length is 21')]
    public function nanoid_default_length(): void
    {
        $id = UUID::generate('nanoid');
        $this->assertEquals(21, strlen($id));
    }

    #[Test('NanoId respects custom size')]
    public function nanoid_custom_size(): void
    {
        $id = UUID::generate('nanoid', ['size' => 10]);
        $this->assertEquals(10, strlen($id));
    }

    #[Test('ULID is 26 uppercase alphanumeric chars')]
    public function ulid_format(): void
    {
        $ulid = UUID::generate('ulid');
        $this->assertEquals(26, strlen($ulid));
        $this->assertTrue((bool) preg_match('/^[0-9A-Z]{26}$/', $ulid));
    }

    #[Test('Invalid type throws InvalidArgumentException')]
    public function invalid_type_throws(): void
    {
        $this->shouldFail(function () {
            UUID::generate('invalid_type');
        });
    }

    #[Test('UUID v4 values are unique')]
    public function uuid_v4_uniqueness(): void
    {
        $a = UUID::generate('uuid');
        $b = UUID::generate('uuid');
        $this->assertTrue($a !== $b);
    }
}
