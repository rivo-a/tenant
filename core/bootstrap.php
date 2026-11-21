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

// Enforce CSP with the generated nonce
// Inline <script> blocks and event handlers must carry csp_nonce() to run.
// Google Fonts is allowed for style and font sources only.
header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'nonce-" . csp_nonce() . "'; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
    . "font-src 'self' https://fonts.gstatic.com data:; "
    . "img-src 'self' data:;"
);

/*
|--------------------------------------------------------------------------
| Core Configuration & Helpers
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';   // NOW properly provides getDB()
require_once __DIR__ . '/helpers.php';              // redirect(), e(), require_login(), etc.
require_once __DIR__ . '/rent.php';                 // rent + payment expressions, rent propagation
require_once __DIR__ . '/room_type_colors.php';     // room type colour keys
require_once __DIR__ . '/audit.php';                // audit_logs writer, redaction, business context
require_once __DIR__ . '/auth.php';                 // live identity, role vocabulary, business scope
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/permissions.php';          // capability matrix, require_capability()

/*
|--------------------------------------------------------------------------
| Session enforcement
|--------------------------------------------------------------------------
| A deleted or suspended account loses its session on the next request, and a
| role changed in the database replaces the role cached in the session.
*/
auth_enforce_session();

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
 * Audit logger (fail-safe by default).
 * Call: logAudit($adminId, 'ACTION', 'Description...', ['target_type' => 'payment', 'target_id' => 42])
 *
 * Writes through the shared audit writer, which uses the real audit_logs
 * columns and redacts credential material. Operations that must roll back when
 * their audit event cannot be stored should call audit_write() inside their
 * own transaction instead.
 */
if (!function_exists('logAudit')) {
    function logAudit(int $adminId, string $action, string $description = '', array $context = []): void
    {
        try {
            audit_log_event(getDB(), $adminId, $action, $description, $context);
        } catch (Throwable $e) {
            error_log("Audit log failed for admin {$adminId}: " . $e->getMessage());
        }
    }
}
?>