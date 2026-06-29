<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use Modules\ForgeTesting\Attributes\Group;
use Modules\ForgeTesting\Attributes\Test;
use Modules\ForgeTesting\TestCase;
use Forge\Traits\EnumHelper;

enum TestStatus: string
{
    use EnumHelper;

    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}

#[Group('helpers')]
final class EnumHelperTest extends TestCase
{
    #[Test('values returns all enum case values')]
    public function values_returns_all(): void
    {
        $values = TestStatus::values();
        $this->assertEquals(['active', 'inactive', 'pending'], $values);
    }

    #[Test('fromName returns matching case')]
    public function from_name_matches(): void
    {
        $case = TestStatus::fromName('Active');
        $this->assertTrue($case === TestStatus::Active);
    }

    #[Test('fromName returns null for unknown name')]
    public function from_name_unknown_returns_null(): void
    {
        $result = TestStatus::fromName('Deleted');
        $this->assertNull($result);
    }

    #[Test('toArray returns name => value map')]
    public function to_array_returns_map(): void
    {
        $arr = TestStatus::toArray();
        $this->assertEquals([
            'Active' => 'active',
            'Inactive' => 'inactive',
            'Pending' => 'pending',
        ], $arr);
    }

    #[Test('valueArray returns all raw values')]
    public function value_array_returns_values(): void
    {
        $arr = TestStatus::valueArray();
        $this->assertEquals(['active', 'inactive', 'pending'], $arr);
    }

    #[Test('valueList joins values with default separator')]
    public function value_list_default_separator(): void
    {
        $list = TestStatus::valueList();
        $this->assertEquals('active, inactive, pending', $list);
    }

    #[Test('valueList respects custom separator')]
    public function value_list_custom_separator(): void
    {
        $list = TestStatus::valueList('|');
        $this->assertEquals('active|inactive|pending', $list);
    }
}
