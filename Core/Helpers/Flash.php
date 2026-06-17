<?php

declare(strict_types=1);

namespace Forge\Core\Helpers;

final class Flash
{
    /**
     * Set a flash message (available for the next request only).
     */
    public static function set(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Get and remove a flash message.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    /**
     * Check if a flash message exists.
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION['_flash'][$key]);
    }

    /**
     * Get all flash messages and clear them.
     */
    public static function all(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $messages;
    }

    public static function flat(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        $flatMessages = [];
        foreach ($messages as $type => $messageList) {
            if (!is_array($messageList)) {
                $flatMessages[] = ["type" => $type, "message" => $messageList];
                continue;
            }
            array_walk_recursive(
                $messageList,
                function ($msg) use ($type, &$flatMessages) {
                    $flatMessages[] = ["type" => $type, "message" => $msg];
                }
            );
        }
        return $flatMessages;
    }
}
