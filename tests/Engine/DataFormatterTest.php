<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use DateTimeImmutable;
use Forge\Traits\DataFormatter;
use JsonSerializable;

#[Group('helpers')]
final class DataFormatterTest extends TestCase
{
    private function runner(): object
    {
        return new class {
            use DataFormatter;

            public function format(mixed $data): mixed
            {
                return $this->formatDebugData($data);
            }
        };
    }

    #[Test('formatDebugData returns scalar values unchanged')]
    public function scalar_passthrough(): void
    {
        $r = $this->runner();
        $this->assertEquals('hello', $r->format('hello'));
        $this->assertEquals(42, $r->format(42));
        $this->assertTrue($r->format(true));
    }

    #[Test('formatDebugData converts null to empty string')]
    public function null_becomes_empty_string(): void
    {
        $this->assertEquals('', $this->runner()->format(null));
    }

    #[Test('formatDebugData recurses into arrays')]
    public function array_is_recursed(): void
    {
        $result = $this->runner()->format(['a' => 'hello', 'b' => null]);
        $this->assertEquals('hello', $result['a']);
        $this->assertEquals('', $result['b']);
    }

    #[Test('formatDebugData recurses nested arrays')]
    public function nested_array_recursed(): void
    {
        $result = $this->runner()->format(['outer' => ['inner' => null]]);
        $this->assertEquals('', $result['outer']['inner']);
    }

    #[Test('formatDebugData calls toArray on objects that have it')]
    public function object_with_to_array(): void
    {
        $obj = new class {
            public function toArray(): array
            {
                return ['x' => 'val', 'y' => null];
            }
        };
        $result = $this->runner()->format($obj);
        $this->assertEquals('val', $result['x']);
        $this->assertEquals('', $result['y']);
    }

    #[Test('formatDebugData calls jsonSerialize on JsonSerializable objects')]
    public function json_serializable_object(): void
    {
        $obj = new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['encoded' => 'yes'];
            }
        };
        $result = $this->runner()->format($obj);
        $this->assertEquals('yes', $result['encoded']);
    }

    #[Test('formatDebugData formats DateTimeInterface as ATOM string')]
    public function datetime_formatted_as_atom(): void
    {
        $dt = new DateTimeImmutable('2025-06-01T10:00:00+00:00');
        $result = $this->runner()->format($dt);
        $this->assertTrue(str_contains($result, '2025-06-01'));
    }

    #[Test('formatDebugData casts unknown objects to array and cleans null-byte keys')]
    public function generic_object_cleaned(): void
    {
        $obj = new class {
            public string $pub = 'public';
        };
        $result = $this->runner()->format($obj);
        $this->assertEquals('public', $result['pub']);
    }
}
