<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use Forge\Core\Helpers\Hash;

#[Group('helpers')]
final class HashTest extends TestCase
{
    #[Test('Hash::make returns a non-empty string')]
    public function make_returns_string(): void
    {
        $hash = Hash::make('secret');
        $this->assertNotNull($hash);
        $this->assertTrue(strlen($hash) > 0);
    }

    #[Test('Hash::make produces different results for same input (salted)')]
    public function make_is_salted(): void
    {
        $a = Hash::make('password');
        $b = Hash::make('password');
        $this->assertTrue($a !== $b);
    }

    #[Test('Hash::check returns true for correct password')]
    public function check_correct_password(): void
    {
        $hash = Hash::make('mysecret');
        $this->assertTrue(Hash::check('mysecret', $hash));
    }

    #[Test('Hash::check returns false for wrong password')]
    public function check_wrong_password(): void
    {
        $hash = Hash::make('correct');
        $this->assertFalse(Hash::check('wrong', $hash));
    }

    #[Test('Hash::check returns false for empty password')]
    public function check_empty_against_hash(): void
    {
        $hash = Hash::make('something');
        $this->assertFalse(Hash::check('', $hash));
    }
}
