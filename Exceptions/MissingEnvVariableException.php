<?php

declare(strict_types=1);

namespace Forge\Exceptions;

final class MissingEnvVariableException extends BaseException
{
    public function __construct(string $key)
    {
        parent::__construct("Missing required environment variable: $key");
    }
}
