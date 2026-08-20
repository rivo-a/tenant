<?php
$pdo = require __DIR__ . '/../config/database.php';

try {
    echo "Starting tenants migration...\n";

    // Get existing columns
    $stmt = $pdo->query("PRAGMA table_info(tenants)");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['name'];
    }

    // created_at
    if (!in_array('created_at', $columns, true)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN created_at DATETIME");
        $pdo->exec("UPDATE tenants SET created_at = CURRENT_TIMESTAMP WHERE created_at IS NULL");
        echo "✔ created_at added and filled\n";
    } else {
        echo "• created_at already exists (skipped)\n";
    }

    // updated_at
    if (!in_array('updated_at', $columns, true)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN updated_at DATETIME");
        echo "✔ updated_at added\n";
    } else {
        echo "• updated_at already exists (skipped)\n";
    }

    echo "Migration completed successfully.\n";

} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}
?>