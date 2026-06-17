<?php

declare(strict_types=1);

namespace Forge\Exceptions;

final class UserNotFoundException extends BaseException
{
    public function __construct()
    {
        parent::__construct("The user was not found");
    }
}
