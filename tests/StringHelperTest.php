<?php

declare(strict_types=1);

namespace Forge\tests;

use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use Forge\Traits\StringHelper;

#[Group('helpers')]
final class StringHelperTest extends TestCase
{
    private static function runner(): string
    {
        return get_class(new class {
            use StringHelper;
        });
    }
    #[Test('String converted to camelCase')]
    public function string_to_camel_case(): void
    {
        $expected = 'thisIsATest';
        $runner = self::runner();
        $actual = $runner::toCamelCase('this is a test');
        $this->assertEquals($expected, $actual);
    }

    #[Test('String converted to PascalCase')]
    public function string_to_pascal_case(): void
    {
        $expected = 'ThisIsATest';
        $runner = self::runner();
        $actual = $runner::toPascalCase('this is a test');
        $this->assertEquals($expected, $actual);
    }

    #[Test('String converted to_snake_case')]
    public function string_to_snake_case(): void
    {
        $expected = 'this_is_a_test';
        $runner = self::runner();
        $actual = $runner::toSnakeCase('thisIsATest');
        $this->assertEquals($expected, $actual);
    }

    #[Test('String converted to-friendly-url')]
    public function string_to_friendly_url(): void
    {
        $expected = 'this-is-a-test';
        $runner = self::runner();
        $actual = $runner::slugify('this is a test');
        $this->assertEquals($expected, $actual);
    }

    #[Test('Truncate string to spesified length')]
    public function truncate_string(): void
    {
        $string = 'this is just some random test';
        $expected = 'this...';
        $runner = self::runner();
        $actual = $runner::truncate($string, 4);
        $this->assertEquals($expected, $actual);
    }

    #[Test('Truncate should fail')]
    public function truncate_string_should_fail(): void
    {
        $string = 'this is just some random test';
        $expected = 'this...';
        $runner = self::runner();
        $actual = $runner::truncate($string, 10);
        $this->shouldFail(function () use ($expected, $actual) {
            $this->assertEquals($expected, $actual);
        });
    }

    #[Test('String to Title Case')]
    public function string_to_title_case(): void
    {
        $string = 'this is a test';
        $expected = 'This Is A Test';
        $runner = self::runner();
        $actual = $runner::toTitleCase($string);
        $this->assertEquals($expected, $actual);
    }

    #[Test('is camelCase')]
    public function string_is_camel_case(): void
    {
        $string = 'isCamelCase';
        $runner = self::runner();
        $expected = $runner::isCamelCase($string);
        $this->assertTrue($expected);
    }

    #[Test('is PascalCase')]
    public function string_is_pascal_case(): void
    {
        $string = 'IsPascalCase';
        $runner = self::runner();
        $expected = $runner::isPascalCase($string);
        $this->assertTrue($expected);
    }

    #[Test('is snake_case')]
    public function string_is_snake_case(): void
    {
        $string = 'is_snake_case';
        $runner = self::runner();
        $expected = $runner::isSnakeCase($string);
        $this->assertTrue($expected);
    }

    #[Test('is kebab-case')]
    public function string_is_kebab_case(): void
    {
        $string = 'is-kebab-case';
        $runner = self::runner();
        $expected = $runner::isKebabCase($string);
        $this->assertTrue($expected);
    }
}
