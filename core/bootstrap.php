<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

/*
|--------------------------------------------------------------------------
| Application Bootstrap (Hardened)
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/error_handler.php';

/*
|--------------------------------------------------------------------------
| Secure Session Configuration
|--------------------------------------------------------------------------
| Must be set BEFORE session_start()
*/
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}

/*
|--------------------------------------------------------------------------
| Security Headers
|--------------------------------------------------------------------------
*/
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// NOTE: keep strict CSP for now; expand later if you add CDNs/fonts.
header("Content-Security-Policy: default-src 'self'");

/*
|--------------------------------------------------------------------------
| Core Configuration & Helpers
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';   // provides getDB() (Option A)
require_once __DIR__ . '/helpers.php';              // redirect(), e(), require_login(), etc.
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/permissions.php';          // requireRole(), requireCaretaker(), isSuperAdmin()

/*
|--------------------------------------------------------------------------
| Utility helpers that belong to bootstrap (small + harmless)
|--------------------------------------------------------------------------
*/
if (!function_exists('humanDate')) {
    function humanDate(?string $date): string
    {
        if (!$date) return '—';
        return date('d M Y', strtotime($date));
    }
}

/**
 * Audit logger (Option A friendly)
 * Call: logAudit($adminId, 'ACTION', 'Description...')
 */
if (!function_exists('logAudit')) {
    function logAudit(int $adminId, string $action, string $description): void
    {
        $pdo = getDB();

        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (admin_id, action, description)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$adminId, $action, $description]);
    }
}
?>