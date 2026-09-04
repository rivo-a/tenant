<?php
declare(strict_types=1);
define('BASE_URL', '/tenant-system/public');
date_default_timezone_set ('Africa/Kampala');
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