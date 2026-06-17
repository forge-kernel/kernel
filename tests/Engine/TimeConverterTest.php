<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use Forge\Core\Helpers\TimeConverter;

#[Group('helpers')]
final class TimeConverterTest extends TestCase
{
    #[Test('minutesToMilliseconds converts positive minutes correctly')]
    public function minutes_to_ms(): void
    {
        $this->assertEquals(60000, TimeConverter::minutesToMilliseconds(1));
        $this->assertEquals(120000, TimeConverter::minutesToMilliseconds(2));
    }

    #[Test('minutesToMilliseconds returns 0 for 0 or negative minutes')]
    public function minutes_to_ms_zero_or_negative(): void
    {
        $this->assertEquals(0, TimeConverter::minutesToMilliseconds(0));
        $this->assertEquals(0, TimeConverter::minutesToMilliseconds(-5));
    }

    #[Test('millisecondsToMinutes converts positive ms correctly')]
    public function ms_to_minutes(): void
    {
        $this->assertEquals(1.0, TimeConverter::millisecondsToMinutes(60000));
        $this->assertEquals(2.5, TimeConverter::millisecondsToMinutes(150000));
    }

    #[Test('millisecondsToMinutes returns 0 for 0 or negative ms')]
    public function ms_to_minutes_zero_or_negative(): void
    {
        $this->assertEquals(0.0, TimeConverter::millisecondsToMinutes(0));
        $this->assertEquals(0.0, TimeConverter::millisecondsToMinutes(-1000));
    }
}
