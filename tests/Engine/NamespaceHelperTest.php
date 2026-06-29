<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use Modules\ForgeTesting\Attributes\AfterEach;
use Modules\ForgeTesting\Attributes\BeforeEach;
use Modules\ForgeTesting\Attributes\Group;
use Modules\ForgeTesting\Attributes\Test;
use Modules\ForgeTesting\TestCase;
use Forge\Traits\NamespaceHelper;

#[Group('helpers')]
final class NamespaceHelperTest extends TestCase
{
    private string $tmpDir = '';
    private object $helper;

    #[BeforeEach]
    public function setup(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ns_test_' . uniqid();
        mkdir($this->tmpDir);

        $this->helper = new class {
            use NamespaceHelper;

            public function className(string $path): ?string
            {
                return $this->getClassNameFromFile($path);
            }

            public function namespaceName(string $path, string $base): ?string
            {
                return $this->getNamespaceFromFile($path, $base);
            }
        };
    }

    #[AfterEach]
    public function cleanup(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*.php') ?: []);
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    private function writeFile(string $name, string $content): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    #[Test('getClassNameFromFile returns fully qualified class name')]
    public function extracts_fqcn(): void
    {
        $path = $this->writeFile('MyClass.php', '<?php namespace App\Services; class MyClass {}');
        $result = $this->helper->className($path);
        $this->assertEquals('App\Services\MyClass', $result);
    }

    #[Test('getClassNameFromFile returns class name without namespace')]
    public function extracts_class_without_namespace(): void
    {
        $path = $this->writeFile('Bare.php', '<?php class Bare {}');
        $result = $this->helper->className($path);
        $this->assertEquals('Bare', $result);
    }

    #[Test('getClassNameFromFile returns null for file without a class')]
    public function returns_null_for_no_class(): void
    {
        $path = $this->writeFile('functions.php', '<?php function helper() {}');
        $result = $this->helper->className($path);
        $this->assertNull($result);
    }

    #[Test('getNamespaceFromFile extracts namespace from file content')]
    public function extracts_namespace(): void
    {
        $path = $this->writeFile('NS.php', "<?php\nnamespace Foo\\Bar;\nclass X {}");
        $result = $this->helper->namespaceName($path, '/base');
        $this->assertEquals('Foo\\Bar', $result);
    }

    #[Test('getNamespaceFromFile returns null for file without namespace')]
    public function returns_null_if_no_namespace(): void
    {
        $path = $this->writeFile('NoNS.php', '<?php class Z {}');
        $result = $this->helper->namespaceName($path, '/base');
        $this->assertNull($result);
    }
}
