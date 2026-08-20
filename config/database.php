<?php
// config/database.php

try {
    $db_file = __DIR__ . '/../database/tenant_system.db';
    $pdo = new PDO("sqlite:" . $db_file);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // Enforce foreign key constraints (SQLite requires this)
    $pdo->exec('PRAGMA foreign_keys = ON;');
    
    return $pdo;

} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}


?>