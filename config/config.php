<?php
declare(strict_types=1);
date_default_timezone_set('Africa/Kampala');

if (!function_exists('app_env')) {
    function app_env(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }
}

/*
 * BASE_URL stays a path: every page links against it, so changing it to a full
 * URL would rewrite every href. The public address (scheme + host + this path)
 * lives in APP_URL and is what canonical HTTPS redirects use.
 */
define('BASE_URL', '/tenant-system/public');
define('APP_URL', rtrim(app_env('APP_BASE_URL', ''), '/'));

/*
|--------------------------------------------------------------------------
| Database Configuration (SQLite)
|--------------------------------------------------------------------------
| This file MUST be loaded with require_once
| DO NOT start sessions here
*/

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dbFile = __DIR__ . '/../database/tenant_system.db';

        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON;');
    }

    return $pdo;
}
?>