<?php

declare(strict_types=1);

namespace Forge\Exceptions;

final class SessionNotStartedException extends BaseException
{
    public function __construct()
    {
        parent::__construct("Cannot save session before starting it");
    }
}
