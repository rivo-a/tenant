<?php
declare(strict_types=1);

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(string $token): void
    {
        if (
            empty($_SESSION['_csrf']) ||
            !hash_equals($_SESSION['_csrf'], $token)
        ) {
            http_response_code(419);
            exit('Invalid CSRF token');
        }
    }
}
?>