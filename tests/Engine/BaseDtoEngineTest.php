<?php
declare(strict_types=1);

namespace Forge\tests\Engine;

use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use Forge\Core\Dto\BaseDto;
use Forge\tests\Engine\Fixtures\DummyDto;

#[Group('dto')]
final class BaseDtoEngineTest extends TestCase
{
    #[Test('sanitize removes specified properties')]
    public function sanitize_removes_specified_properties(): void
    {
        $dummy = DummyDto::from([
            'username' => 'john_doe',
            'secret' => 'top-secret',
            'password' => '123456',
        ]);

        $clean = $dummy->sanitize();

        self::assertNull($clean->secret);
        self::assertNull($clean->password);
        self::assertSame('john_doe', $clean->username);
    }

    #[Test("Sanitize works when no Sanitize attribute is present")]
    public function sanitize_no_attribute_keeps_all_properties(): void
    {
        $dto = new class(['foo' => 'bar', 'baz' => 'qux']) extends BaseDto {
            public string $foo;
            public string $baz;

            public function __construct(array $data = [])
            {
                $this->foo = $data['foo'] ?? '';
                $this->baz = $data['baz'] ?? '';
            }
        };

        $sanitized = $dto->sanitize();

        $this->assertEquals('bar', $sanitized->foo);
        $this->assertEquals('qux', $sanitized->baz);
    }
}