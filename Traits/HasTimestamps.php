<?php

declare(strict_types=1);

namespace Forge\Traits;


use App\Modules\ForgeSqlOrm\ORM\Attributes\Column;
use App\Modules\ForgeSqlOrm\ORM\Values\Cast;
use DateTimeImmutable;

trait Hastimestamps
{
    #[Column(cast: Cast::DATETIME)]
    public ?DateTimeImmutable $created_at = null;

    #[Column(cast: Cast::DATETIME)]
    public ?DateTimeImmutable $updated_at = null;
}
