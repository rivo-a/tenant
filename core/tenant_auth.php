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

    /*
     * The session alone is not proof of a usable account: a suspended, exited
     * or deleted tenant must lose access on the next request, and a temporary
     * password must be replaced before anything else is reachable.
     */
    if (!function_exists('getDB')) {
        return;
    }

    try {
        $stmt = getDB()->prepare(
            "SELECT account_status, must_change_password, status, exit_date
             FROM tenants
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => (int)$_SESSION['tenant_id']]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $ignored) {
        $tenant = null;
    }

    if (!$tenant) {
        destroyTenantSession();
        header('Location: /tenant-system/public/portal/login.php?error=invalid');
        exit;
    }

    $accountStatus = strtolower((string)($tenant['account_status'] ?? 'active'));
    $tenantStatus = strtolower((string)($tenant['status'] ?? 'active'));

    if (
        in_array($accountStatus, ['suspended', 'revoked', 'locked', 'closed'], true)
        || ($tenantStatus !== '' && !in_array($tenantStatus, ['active'], true))
        || !empty($tenant['exit_date'])
    ) {
        destroyTenantSession();
        header('Location: /tenant-system/public/portal/login.php?error=account_closed');
        exit;
    }

    if ((int)($tenant['must_change_password'] ?? 0) === 1) {
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($script, ['change_password.php', 'logout.php'], true)) {
            header('Location: /tenant-system/public/portal/change_password.php?first=1');
            exit;
        }
    }
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