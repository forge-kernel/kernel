<?php

declare(strict_types=1);

namespace Forge\Traits;

use App\Modules\ForgeSqlOrm\ORM\Attributes\Column;
use App\Modules\ForgeSqlOrm\ORM\Values\Cast;

trait HasMetaData
{
    #[Column(cast: Cast::JSON)]
    public ?array $metadata = null;
}
