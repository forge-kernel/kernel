<?php

declare(strict_types=1);

namespace Forge\Exceptions;

final class AuthorizationException extends BaseException
{
    public function __construct(string $message = 'Insufficient permissions')
    {
        parent::__construct($message);
    }
}
