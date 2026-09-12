<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

/* Load .env values once for every web entry point. */
$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (class_exists('Dotenv\Dotenv')) {
    try {
        \Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
    } catch (Throwable $ignored) {
        // Missing or malformed optional environment values are handled by callers.
    }
}

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
*/
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Detect HTTPS even behind reverse proxies (Cloudflare, Nginx, etc.)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
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

// Generate a secure, one-time nonce for inline scripts
$GLOBALS['csp_nonce'] = bin2hex(random_bytes(16));

if (!function_exists('csp_nonce')) {
    function csp_nonce(): string {
        return $GLOBALS['csp_nonce'] ?? '';
    }
}

// Enforce CSP with the generated nonce (uncommented and fixed)
// Note: Add 'unsafe-inline' to style-src only if you absolutely need it for Tailwind/legacy CSS
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . csp_nonce() . "'; style-src 'self' 'unsafe-inline';");

/*
|--------------------------------------------------------------------------
| Core Configuration & Helpers
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';   // NOW properly provides getDB()
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
        if (!$date) {
            return '—';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '—';
        }

        // Add Uganda's UTC+3 offset
        $timestamp += (3 * 60 * 60);

        return date('d M Y', $timestamp);
    }
}

/**
 * Audit logger (Fail-safe)
 * Call: logAudit($adminId, 'ACTION', 'Description...')
 */
if (!function_exists('logAudit')) {
    function logAudit(int $adminId, string $action, string $description): void
    {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare(
                "INSERT INTO audit_logs (admin_id, action, description)
                 VALUES (?, ?, ?)"
            );
            $stmt->execute([$adminId, $action, $description]);
        } catch (Throwable $e) {
            // Fail silently for audit logs so the main user action isn't blocked
            // In production, you might want to send this to a log file instead
            error_log("Audit log failed for admin {$adminId}: " . $e->getMessage());
        }
    }
}
?>