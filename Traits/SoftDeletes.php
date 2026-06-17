<?php

declare(strict_types=1);

namespace Forge\Traits;

use Forge\Core\Database\Attributes\Column;

trait SoftDeletes
{
    #[Column("timestamp", nullable: true)]
    public ?string $deleted_at = null;
}
