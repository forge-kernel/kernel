<?php

declare(strict_types=1);

namespace Forge\Core\Contracts;

interface LoggerInterface
{
    public function log(string $message, string $level = 'INFO', array $context = []): void;
    public function debug(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;
    public function exception(\Throwable $e, array $context = []): void;
}
