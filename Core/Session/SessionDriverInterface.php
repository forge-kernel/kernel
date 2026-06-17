<?php

declare(strict_types=1);

namespace Forge\Core\Session;

interface SessionDriverInterface
{
    public function start(): void;
    public function save(): void;
}
