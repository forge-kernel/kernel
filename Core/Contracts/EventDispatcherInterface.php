<?php

declare(strict_types=1);

namespace Forge\Core\Contracts;

interface EventDispatcherInterface
{
    public function addListener(string $eventClass, callable $handler): void;
    public function dispatch(object $event): void;
}
