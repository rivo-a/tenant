<?php
declare(strict_types=1);

/**
 * Tenant Portal Authentication Guard
 * Call requireTenantAuth() at the top of every protected portal page.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Secure session settings
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isSecure ? '1' : '0');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '7200'); // 2 hours
    session_start();
}

function requireTenantAuth(): void
{
    if (
        !isset($_SESSION['tenant_id']) ||
        !isset($_SESSION['tenant_role']) ||
        $_SESSION['tenant_role'] !== 'tenant'
    ) {
        header('Location: /tenant-system/public/portal/login.php');
        exit;
    }

    // Enforce session expiration
    if (isset($_SESSION['tenant_last_activity'])) {
        if (time() - $_SESSION['tenant_last_activity'] > 7200) {
            destroyTenantSession();
            header('Location: /tenant-system/public/portal/login.php?error=session_expired');
            exit;
        }
    }
    $_SESSION['tenant_last_activity'] = time();
}

function getLoggedInTenantId(): int
{
    requireTenantAuth();
    return (int)$_SESSION['tenant_id'];
}

function destroyTenantSession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}
?>