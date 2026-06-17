<?php

declare(strict_types=1);

namespace Forge\Core\Contracts;

interface ViewInterface
{
    public function render(string $view, array $data = [], ?string $layoutName = null): string;

    public static function layout(
        string $name,
        array $props = [],
        array $slots = [],
        bool $loadFromModule = false,
        ?string $moduleName = null,
    ): void;

    public static function slot(string $name = "default", string $default = ""): string;

    public static function startSection(string $name): void;

    public static function endSection(): void;

    public static function section(string $name): string;

    public static function viewComponent(
        string $path,
        array|object|null $props = [],
        ?string $module = null,
        array $slots = [],
    ): string;

    public function renderComponentView(
        string $viewSubPath,
        array|object|null $data = [],
        array $slots = [],
        ?string $module = null,
    ): string;
}
