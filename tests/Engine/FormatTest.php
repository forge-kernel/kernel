<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use Modules\ForgeTesting\Attributes\Group;
use Modules\ForgeTesting\Attributes\Test;
use Modules\ForgeTesting\TestCase;
use Forge\Core\Helpers\Format;

#[Group('helpers')]
final class FormatTest extends TestCase
{
    #[Test('errorCode wraps each digit in a span inside h1')]
    public function error_code_wraps_digits(): void
    {
        $result = Format::errorCode(404);
        $this->assertEquals('<h1><span>4</span><span>0</span><span>4</span></h1>', $result);
    }

    #[Test('errorCode escapes HTML in input')]
    public function error_code_escapes_html(): void
    {
        $result = Format::errorCode('<script>');
        $this->assertTrue(str_contains($result, '&lt;'));
        $this->assertFalse(str_contains($result, '<script>'));
    }

    #[Test('fileSize formats 0 bytes correctly')]
    public function file_size_zero(): void
    {
        $this->assertEquals('0 Bytes', Format::fileSize(0));
    }

    #[Test('fileSize formats bytes')]
    public function file_size_bytes(): void
    {
        $this->assertEquals('512.00 Bytes', Format::fileSize(512));
    }

    #[Test('fileSize formats kilobytes')]
    public function file_size_kb(): void
    {
        $this->assertEquals('1.00 KB', Format::fileSize(1024));
    }

    #[Test('fileSize formats megabytes')]
    public function file_size_mb(): void
    {
        $this->assertEquals('1.00 MB', Format::fileSize(1024 * 1024));
    }

    #[Test('fileSize formats gigabytes')]
    public function file_size_gb(): void
    {
        $this->assertEquals('1.00 GB', Format::fileSize(1024 * 1024 * 1024));
    }
}
