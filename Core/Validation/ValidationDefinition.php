<?php

declare(strict_types=1);

namespace Forge\Core\Validation;

final class ValidationDefinition
{
    /**
     * @param array<string, mixed> $data,
     * @param array<string,array<string>> $rules
     * @param array<string, string> $messages
     */
    public function __construct(
        public readonly array $data,
        public readonly array $rules,
        public readonly array $messages = []
    ) {
    }
}
