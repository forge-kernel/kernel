<?php

declare(strict_types=1);

namespace Forge\Exceptions;

final class ValidationException extends BaseException
{
    private array $errors;

    public function __construct(array $errors = [], string $message = 'Invalid validation')
    {
        if ($message === 'Invalid validation' && !empty($errors)) {
            $firstError = reset($errors);
            if (is_array($firstError) && !empty($firstError)) {
                $message = reset($firstError);
            } elseif (is_string($firstError)) {
                $message = $firstError;
            }
        }

        parent::__construct($message);
        $this->errors = $errors;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
