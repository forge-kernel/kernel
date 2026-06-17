<?php

declare(strict_types=1);

use Forge\Core\Cache\CacheManager;
use Forge\Core\Config\Environment;
use Forge\Core\DI\Container;
use Forge\Core\Helpers\Debuger;
use Forge\Core\Helpers\Html;
use Forge\Core\Security\AssetRegistry;
use Forge\Core\Services\TokenManager;
use Forge\Core\Contracts\ViewInterface;
use Forge\Core\Config\Config;
use Forge\Exceptions\MissingServiceException;
use Forge\Exceptions\ResolveParameterException;
use Forge\Core\Module\ModuleResourceResolver;

if (!function_exists("env")) {
    /**
     * Get environment value by key.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Environment::getInstance()->get($key, $default);
    }
}


if (!function_exists("cache")) {
    /**
     * Get cache data by key.
     *
     * @param string $key
     * @param mixed|null $value
     * @param int|null $ttl
     * @return mixed
     * @throws MissingServiceException
     * @throws ReflectionException
     * @throws ResolveParameterException
     */
    function cache(string $key, mixed $value = null, ?int $ttl = null): mixed
    {
        $cache = Container::getInstance()->make(
            CacheManager::class,
        );

        if (func_num_args() === 1) {
            return $cache->get($key);
        }

        $cache->set($key, $value, $ttl);
        return $value;
    }
}

if (!function_exists("config")) {
    /**
     * Get the config value by key.
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     * @throws MissingServiceException
     */
    function config(string $key, mixed $default = null): mixed
    {
        /** @var Config $config */
        $config = Container::getInstance()->make(Config::class);
        return $config->get($key, $default);
    }
}

if (!function_exists("request_host")) {
    /**
     * Get the current request host (domain + port if available).
     *
     */
    function request_host(): string
    {
        $host = $_SERVER["HTTP_HOST"] ?? "localhost";
        return strtolower(trim($host));
    }
}

if (!function_exists("get_data")) {
    /**
     * Get an item from an array or object using dot notation.
     *
     * @param mixed $target The array or object to retrieve from.
     * @param string $key The key, in dot notation (e.g., 'user.address.street').
     * @param mixed $default The default value to return if the key is not found.
     * @return mixed
     */
    function data_get(mixed $target, string $key, mixed $default = null): mixed
    {
        if (empty($key)) {
            return $target;
        }

        $keys = explode(".", $key);
        $current = $target;
        foreach ($keys as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } elseif (
                is_object($current) &&
                property_exists($current, $segment)
            ) {
                $current = $current->$segment;
            } else {
                return $default;
            }
        }

        return $current;
    }
}


if (!function_exists("e")) {
    /**
     * Escape HTML entities in a string.
     *
     * @param mixed $value The string to escape.
     * @return string Returns the escaped string.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");
    }
}

if (!function_exists("raw")) {
    /**
     * Output a value without escaping.
     *
     * @param mixed $value The value to output raw.
     * @return string Returns the raw string representation of the value.
     */
    function raw(mixed $value): string
    {
        return (string) $value;
    }
}

if (!function_exists("csrf_token")) {
    /**
     * @throws MissingServiceException
     */
    function csrf_token(): string
    {
        /** @var TokenManager $mgr */
        $mgr = Container::getInstance()->make(TokenManager::class);
        return $mgr->getToken("web");
    }
}

if (!function_exists("csrf_meta")) {

    function csrf_meta(): string
    {
        return '<meta name="csrf-token" content="' .
            htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") .
            '">';
    }
}

if (!function_exists("window_csrf_token")) {
    function window_csrf_token(): string
    {
        return "<script>
        window.csrfToken = document.querySelector('meta[name='csrf-token']')?.getAttribute('content') || '';
        </script>";
    }
}

if (!function_exists("csrf_input")) {
    function csrf_input(): string
    {
        return '<input type="hidden" name="_token" value="' .
            htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") .
            '">';
    }
}

if (!function_exists("tap")) {
    function tap(mixed $value, callable $cb): mixed
    {
        $cb($value);
        return $value;
    }
}


if (!function_exists("component")) {
    function component(string $name, array|object|null $props = [], array $slots = []): string
    {
        $reference = ModuleResourceResolver::parse($name);
        $view = Container::getInstance()->get(ViewInterface::class);
        return $view->viewComponent($reference->name, $props, $reference->module ?? null, $slots);
    }
}

if (!function_exists("slot")) {
    function slot(string $name = 'default', string $default = ''): string
    {
        return Container::getInstance()->get(ViewInterface::class)->slot($name, $default);
    }
}

if (!function_exists("layout")) {
    /**
     * Helper to load a layout.
     *
     * @deprecated Use #[Layout] attribute on controller method instead.
     * @param string $name Layout name
     * @param array<string, mixed> $props Layout props
     * @param array<string, mixed> $slots Layout slots
     */
    function layout(
        string $name,
        array $props = [],
        array $slots = [],
    ): void {
        trigger_error(
            "layout() is deprecated. Use #[Layout] attribute on controller method instead.",
            E_USER_DEPRECATED
        );
        Container::getInstance()->get(ViewInterface::class)->layout(
            name: $name,
            props: $props,
            slots: $slots
        );
    }
}

if (!function_exists("startSection")) {
    /**
     * @deprecated Use $layoutSlots array in view files instead.
     */
    function startSection(string $name): void
    {
        trigger_error(
            "startSection() is deprecated. Use \$layoutSlots array in view files instead.",
            E_USER_DEPRECATED
        );
        Container::getInstance()->get(ViewInterface::class)->startSection($name);
    }
}

if (!function_exists("endSection")) {
    /**
     * @deprecated Use $layoutSlots array in view files instead.
     */
    function endSection(): void
    {
        trigger_error(
            "endSection() is deprecated. Use \$layoutSlots array in view files instead.",
            E_USER_DEPRECATED
        );
        Container::getInstance()->get(ViewInterface::class)->endSection();
    }
}

if (!function_exists("section")) {
    /**
     * Helper to render a section.
     *
     * @deprecated Use $layoutSlots array in layout files instead.
     * @param string $name Section name
     * @return string
     */
    function section(string $name): string
    {
        trigger_error(
            "section() is deprecated. Use \$layoutSlots array in layout files instead.",
            E_USER_DEPRECATED
        );
        return Container::getInstance()->get(ViewInterface::class)->section($name);
    }
}

if (!function_exists('form_open')) {
    /**
     * Open a form with CSRF protection and method spoofing support.
     */
    function form_open(string $action = '', string $method = 'POST', array $attrs = []): string
    {
        $method = strtoupper($method);
        $realMethod = in_array($method, ['GET', 'POST']) ? $method : 'POST';

        $attrString = '';
        foreach ($attrs as $key => $value) {
            $attrString .= sprintf(' %s="%s"', htmlspecialchars($key), htmlspecialchars($value));
        }

        $html = sprintf('<form action="%s" method="%s"%s>', htmlspecialchars($action), $realMethod, $attrString);

        if (function_exists('csrf_input')) {
            $html .= csrf_input();
        }

        if ($realMethod === 'POST' && $method !== 'POST') {
            $html .= sprintf('<input type="hidden" name="_method" value="%s">', htmlspecialchars($method));
        }

        return $html;
    }
}

if (!function_exists('form_close')) {
    /**
     * Close the form tag.
     */
    function form_close(): string
    {
        return '</form>';
    }
}

if (!function_exists('dd')) {
    function dd(...$vars): void
    {
        Debuger::dumpAndExit($vars);
    }
}



if (!function_exists('external_asset_config')) {
    /**
     * Define an external asset configuration entry.
     *
     * This helper is intended for configuration files only.
     * It returns a plain array describing an external asset
     * that may be safely referenced via `external_asset()`.
     *
     * This function does NOT register the asset or affect CSP.
     * It exists purely for readability and IDE autocomplete.
     *
     * @param string      $name         Logical asset name (used by external_asset())
     * @param string      $url          Full external asset URL
     * @param string|null $integrity    Optional Subresource Integrity hash
     * @param string|null $crossorigin  Optional crossorigin attribute
     *
     * @return array<string, array<string, string>>
     */
    function external_asset_config(
        string $name,
        string $url,
        ?string $integrity = null,
        ?string $crossorigin = null
    ): array {
        return [
            $name => array_filter([
                'url' => $url,
                'integrity' => $integrity,
                'crossorigin' => $crossorigin,
            ]),
        ];
    }
}


if (!function_exists('external_asset')) {
    /**
     * Render an external asset and register it for CSP handling.
     *
     * This helper must be used in views/layouts.
     * It renders the appropriate HTML tag and ensures
     * the asset origin is added to the CSP header
     * for the current request.
     *
     * @param string $name Asset name defined in forge_router.csp.external_assets
     *
     * @return string HTML <link> or <script> tag
     *
     * @throws RuntimeException If the asset is not defined or unsupported
     */
    function external_asset(string $name): string
    {
        $asset = config("security.csp.external_assets.$name") ?? config("forge_router.csp.external_assets.$name");

        if (!$asset) {
            throw new RuntimeException(
                "External asset [$name] not defined. " .
                "Did you forget to unpack external_asset_config() with ... ?"
            );
        }

        $url = $asset['url'];
        $type = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);

        AssetRegistry::registerExternal($asset);

        return match ($type) {
            'css' => Html::link(
                $url,
                $asset['integrity'] ?? null,
                $asset['crossorigin'] ?? null
            ),
            'js' => Html::script(
                $url,
                $asset['integrity'] ?? null,
                $asset['crossorigin'] ?? null
            ),
            default => throw new RuntimeException("Unsupported external asset type [$type]."),
        };
    }
}

if (!function_exists('class_merge')) {
    function class_merge(array|string $base, array|string $additional = [], array|string $overrides = []): string
    {
        $flatten = function ($input) use (&$flatten) {
            if (is_string($input)) {
                return array_filter(explode(' ', trim($input)));
            }
            if (!is_array($input)) {
                return [];
            }
            $result = [];
            foreach ($input as $item) {
                if (is_array($item)) {
                    $result = array_merge($result, $flatten($item));
                } elseif (is_string($item) && !empty(trim($item))) {
                    $result[] = trim($item);
                }
            }
            return array_filter($result);
        };

        $baseClasses = $flatten($base);
        $additionalClasses = $flatten($additional);
        $overrideClasses = $flatten($overrides);

        $merged = array_merge($baseClasses, $additionalClasses, $overrideClasses);

        return implode(' ', array_unique($merged));
    }
}

if (!function_exists('pagination')) {
    /**
     * Render pagination links as HTML.
     * This function is available globally and can be used in views.
     *
     * @param \App\Modules\ForgeSqlOrm\ORM\Paginator $paginator The paginator instance
     * @param array $options Optional rendering options (class, itemClass, linkClass, activeClass, disabledClass, showPages)
     * @return string HTML string with pagination links
     */
    function pagination(\App\Modules\ForgeSqlOrm\ORM\Paginator $paginator, array $options = []): string
    {
        return \Forge\Core\Helpers\PaginationHelper::render($paginator, $options);
    }
}

if (!function_exists('pagination_info')) {
    /**
     * Render pagination info text (e.g., "Showing 1-10 of 100 results").
     * This function is available globally and can be used in views.
     *
     * @param \App\Modules\ForgeSqlOrm\ORM\Paginator $paginator The paginator instance
     * @return string Info text string
     */
    function pagination_info(\App\Modules\ForgeSqlOrm\ORM\Paginator $paginator): string
    {
        return \Forge\Core\Helpers\PaginationHelper::info($paginator);
    }
}

if (!function_exists('paginate')) {
    /**
     * Alias for pagination() function for shorter syntax.
     * This function is available globally and can be used in views.
     *
     * @param \App\Modules\ForgeSqlOrm\ORM\Paginator $paginator The paginator instance
     * @param array $options Optional rendering options
     * @return string HTML string with pagination links
     */
    function paginate(\App\Modules\ForgeSqlOrm\ORM\Paginator $paginator, array $options = []): string
    {
        return pagination($paginator, $options);
    }
}
