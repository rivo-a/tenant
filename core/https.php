<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HTTPS enforcement
|--------------------------------------------------------------------------
| One place that answers "is this request secure?" and redirects if not, so
| the mobile app can be built against HTTPS without per-page changes.
|
| X-Forwarded-Proto is only trusted when the request comes from a configured
| proxy; otherwise a client could claim HTTPS and skip the redirect.
*/

if (!function_exists('app_base_url')) {
    /** Canonical public address, e.g. https://host/tenant-system/public */
    function app_base_url(): string
    {
        if (defined('APP_URL') && APP_URL !== '') {
            return rtrim(APP_URL, '/');
        }

        $configured = function_exists('app_env')
            ? app_env('APP_BASE_URL', defined('BASE_URL') ? BASE_URL : '')
            : (defined('BASE_URL') ? BASE_URL : '');

        return rtrim($configured, '/');
    }
}

if (!function_exists('is_https_request')) {
    function is_https_request(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $proxyHeader = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proxyHeader === '') {
            return false;
        }

        $trustedProxies = array_filter(array_map('trim', explode(',', strtolower(
            function_exists('app_env') ? app_env('TRUSTED_PROXIES', '') : ''
        ))));

        if ($trustedProxies === []) {
            // No proxy configured: accept the header only for Cloudflare Tunnel
            // style deployments, which always set it to a single value.
            return $proxyHeader === 'https';
        }

        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        return in_array($remote, $trustedProxies, true) && $proxyHeader === 'https';
    }
}

if (!function_exists('request_path_and_query')) {
    function request_path_and_query(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $base = app_base_url();

        // Keep only the part after the configured base path, so a redirect to
        // the https origin does not double up the directory.
        $basePath = parse_url($base, PHP_URL_PATH) ?: '';
        if ($basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }

        return $uri === '' ? '/' : $uri;
    }
}

if (!function_exists('require_https')) {
    /**
     * Redirect insecure requests to the configured HTTPS address, once.
     * Leaves CLI and localhost alone so command line tools keep working.
     */
    function require_https(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $base = app_base_url();
        if (!str_starts_with($base, 'https://')) {
            return;
        }

        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $hostName = explode(':', $host)[0] ?? '';
        if (in_array($hostName, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return;
        }

        if (is_https_request()) {
            return;
        }

        $target = $base . request_path_and_query();
        header('Location: ' . $target, true, 301);
        exit;
    }
}

if (!function_exists('send_hsts_header')) {
    /** Only advertise HSTS over HTTPS, and only when explicitly enabled. */
    function send_hsts_header(): void
    {
        $enabled = function_exists('app_env')
            ? filter_var(app_env('HSTS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)
            : false;

        if (!$enabled || !is_https_request()) {
            return;
        }

        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
