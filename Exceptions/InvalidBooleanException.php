<?php

declare(strict_types=1);

namespace Forge\Exceptions;

final class InvalidBooleanException extends BaseException
{
    public function __construct(string $key, mixed $value)
    {
        parent::__construct("Invalid boolean value for $key: $value");
    }
}
