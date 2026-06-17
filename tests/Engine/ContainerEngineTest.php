<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use Forge\Core\DI\Container;
use Forge\Exceptions\ResolveParameterException;
use Forge\tests\Engine\Fixtures\Bar;
use Forge\tests\Engine\Fixtures\Foo;
use Forge\tests\Engine\Fixtures\NeedsInt;

#[Group("container")]
final class ContainerEngineTest extends TestCase
{
    #[Test("container is a singleton")]
    public function container_is_singleton(): void
    {
        $a = Container::getInstance();
        $b = Container::getInstance();

        $this->assertSame($a, $b);
    }

    #[Test("setInstance and get by id returns the same object")]
    public function set_and_get_instance_by_id(): void
    {
        $container = Container::getInstance();

        $service = new \stdClass();
        $service->name = 'example';

        $container->setInstance('example.service', $service);

        $resolved = $container->get('example.service');

        $this->assertSame($service, $resolved);
    }

    #[Test("bind with factory returns new instances when not singleton")]
    public function bind_factory_returns_instances(): void
    {
        $container = Container::getInstance();

        $container->bind('factory.std', fn($c) => new \stdClass(), false);

        $first = $container->make('factory.std');
        $second = $container->make('factory.std');

        $this->assertNotSame($first, $second);
        $this->assertInstanceOf(\stdClass::class, $first);
    }

    #[Test("singleton bind returns same instance")]
    public function singleton_bind_returns_same(): void
    {
        $container = Container::getInstance();

        $container->bind('singleton.std', fn($c) => new \stdClass(), true);

        $first = $container->make('singleton.std');
        $second = $container->make('singleton.std');

        $this->assertSame($first, $second);
    }

    #[Test("tagged services are resolved and returned as array")]
    public function tagged_services_resolve(): void
    {
        $container = Container::getInstance();

        $container->bind('tag.a', fn($c) => (object)['id' => 'a']);
        $container->bind('tag.b', fn($c) => (object)['id' => 'b']);

        $container->tag('group.tests', ['tag.a', 'tag.b']);

        $items = $container->tagged('group.tests');

        $this->assertCount(2, $items);
        $this->assertEquals('a', $items[0]->id);
        $this->assertEquals('b', $items[1]->id);
    }

    #[Test("parameters can be set and retrieved")]
    public function parameters_set_and_get(): void
    {
        $container = Container::getInstance();

        $container->setParameter('app.env', 'testing');

        $this->assertEquals('testing', $container->getParameter('app.env'));
    }

    #[Test("make will auto-resolve class dependencies")]
    public function make_autowires_dependencies(): void
    {
        $container = Container::getInstance();

        $bar = $container->make(Bar::class);

        $this->assertInstanceOf(Bar::class, $bar);
        $this->assertInstanceOf(Foo::class, $bar->foo);
    }

    #[Test("resolving class with builtin scalar constructor throws")]
    public function resolving_with_scalar_parameter_throws(): void
    {
        $container = Container::getInstance();

        $this->shouldFail(
            fn() => $container->make(NeedsInt::class),
            ResolveParameterException::class,
        );
    }
}
