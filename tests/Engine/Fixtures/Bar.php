<?php
declare(strict_types=1);

namespace Forge\tests\Engine\Fixtures;

final class Bar
{
    public Foo $foo;

    public function __construct(Foo $foo)
    {
        $this->foo = $foo;
    }
}