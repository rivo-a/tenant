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

if (!function_exists('csrf_field')) {
    /**
     * Hidden input for any form that is verified with verify_csrf().
     * Added because the onboarding, signup and task pages call it while only
     * csrf_token() existed, which fataled the moment those forms rendered.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf" value="'
            . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')
            . '">';
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