<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Redirect Helper (Safe)
|--------------------------------------------------------------------------
*/
if (!function_exists('redirect')) {
    function redirect(string $path = ''): never
    {
        if (!headers_sent()) {
            if ($path !== '' && str_starts_with($path, 'http')) {
                header("Location: {$path}");
            } else {
                $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $path = ltrim($path, '/\\');
                header("Location: {$base}/{$path}");
            }
        }
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Escape Output (NULL-safe)
|--------------------------------------------------------------------------
*/
if (!function_exists('e')) {
    function e($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| Current Admin Helpers (supports legacy + new session style)
|--------------------------------------------------------------------------
*/
if (!function_exists('current_admin_id')) {
    function current_admin_id(): int
    {
        if (isset($_SESSION['admin']['id'])) {
            return (int)$_SESSION['admin']['id'];
        }
        return (int)($_SESSION['admin_id'] ?? 0);
    }
}

if (!function_exists('current_admin_role')) {
    function current_admin_role(): string
    {
        if (isset($_SESSION['admin']['role'])) {
            return (string)$_SESSION['admin']['role'];
        }
        return (string)($_SESSION['admin_role'] ?? 'caretaker');
    }
}

/*
|--------------------------------------------------------------------------
| Auth State
|--------------------------------------------------------------------------
*/
if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        return current_admin_id() > 0;
    }
}

/*
|--------------------------------------------------------------------------
| Auth Middleware
|--------------------------------------------------------------------------
*/
if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (!isLoggedIn()) {
            redirect('index.php?action=login');
        }
    }
}

/*
|--------------------------------------------------------------------------
| Session Security
|--------------------------------------------------------------------------
*/
if (!function_exists('secure_login')) {
    function secure_login(): void
    {
        session_regenerate_id(true);
    }
}

if (!function_exists('logout')) {
    function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'] ?? '',
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }

        session_destroy();
        redirect('index.php?action=login');
    }
}

/*
|--------------------------------------------------------------------------
| Tenant Helper (if you ever log in as tenant)
|--------------------------------------------------------------------------
*/
if (!function_exists('current_tenant_id')) {
    function current_tenant_id(): int
    {
        if (!isset($_SESSION['tenant_id'])) {
            http_response_code(403);
            exit('Tenant not set');
        }
        return (int)$_SESSION['tenant_id'];
    }
}
?>