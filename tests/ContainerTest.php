<?php

declare(strict_types=1);

namespace Forge\tests;

use Modules\ForgeTesting\Attributes\Group;
use Modules\ForgeTesting\Attributes\Test;
use Modules\ForgeTesting\TestCase;
use Forge\Core\DI\Container;
use ReflectionClass;

interface TestServiceInterface
{
}
class TestServiceA implements TestServiceInterface
{
}
class TestServiceB implements TestServiceInterface
{
}
class TestServiceC implements TestServiceInterface
{
}

#[Group('di')]
final class ContainerTest extends TestCase
{
    #[Test('Container getAll caches interface map for O(1) resolution')]
    public function caches_interface_map(): void
    {
        $container = Container::getInstance();
        $this->resetContainer($container);

        $container->bind(TestServiceA::class, TestServiceA::class);
        $container->bind(TestServiceB::class, TestServiceB::class);

        // Initial O(n) resolution
        $implementations1 = $container->getAll(TestServiceInterface::class);
        $this->assertCount(2, $implementations1);

        // Second call should hit the fast path
        $implementations2 = $container->getAll(TestServiceInterface::class);
        $this->assertCount(2, $implementations2);

        // Binding a new service should invalidate the cache
        $container->bind(TestServiceC::class, TestServiceC::class);

        $implementations3 = $container->getAll(TestServiceInterface::class);
        $this->assertCount(3, $implementations3);
    }

    private function resetContainer(Container $container): void
    {
        $reflection = new ReflectionClass(Container::class);

        $services = $reflection->getProperty('services');
        $services->setAccessible(true);
        $services->setValue($container, []);

        $instances = $reflection->getProperty('instances');
        $instances->setAccessible(true);
        $instances->setValue($container, []);

        $map = $reflection->getProperty('interfaceMapCompiled');
        $map->setAccessible(true);
        $map->setValue($container, []);
    }
}
