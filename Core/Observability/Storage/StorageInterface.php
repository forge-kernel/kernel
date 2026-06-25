<?php

declare(strict_types=1);

namespace Forge\Core\Observability\Storage;

use Forge\Core\Observability\Trace;

interface StorageInterface
{
    public function saveTrace(Trace $trace): void;
}
