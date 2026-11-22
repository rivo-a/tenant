<?php
/**
 * SQLite-compatible migration
 * Room-Type Pricing Templates (Single-Apartment Mode)
 * Run ONCE, then delete
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<pre>";

$pdo = require __DIR__ . '/../config/database.php';

try {
    $pdo->beginTransaction();
    $pdo->exec("PRAGMA foreign_keys = ON");

    // ===============================
    // 1. CREATE room_types TABLE
    // ===============================
    echo "Creating room_types table...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS room_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            default_monthly_rent REAL NOT NULL,
            description TEXT,
            is_active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");

    echo "room_types table created.\n\n";

    // ===============================
    // 2. ADD room_type_id TO rooms
    // ===============================
    echo "Updating rooms table...\n";

    $columns = $pdo->query("PRAGMA table_info(rooms)")
                   ->fetchAll(PDO::FETCH_COLUMN, 1);

    if (!in_array('room_type_id', $columns)) {
        $pdo->exec("ALTER TABLE rooms ADD COLUMN room_type_id INTEGER");
        echo "room_type_id column added.\n";
    } else {
        echo "room_type_id column already exists.\n";
    }

    echo "\n";

    // ===============================
    // 3. CREATE DEFAULT TEMPLATE (ONCE)
    // ===============================
    echo "Creating default pricing template...\n";

    $stmt = $pdo->query("
        SELECT id FROM room_types WHERE name = 'Default Template' LIMIT 1
    ");

    $templateId = $stmt->fetchColumn();

    if (!$templateId) {
        $pdo->exec("
            INSERT INTO room_types (name, default_monthly_rent, description)
            VALUES ('Default Template', 0, 'System-generated default pricing template')
        ");
        $templateId = $pdo->lastInsertId();
        echo "Default template created.\n";
    } else {
        echo "Default template already exists.\n";
    }

    echo "\n";

    // ===============================
    // 4. LINK ALL ROOMS TO TEMPLATE
    // ===============================
    echo "Linking rooms to default template...\n";

    $stmt = $pdo->prepare("
        UPDATE rooms
        SET room_type_id = :template_id
        WHERE room_type_id IS NULL
    ");
    $stmt->execute(['template_id' => $templateId]);

    echo "Rooms linked successfully.\n\n";

    // ===============================
    // 5. FINALIZE
    // ===============================
    $pdo->commit();

    echo "SUCCESS ✅\n";
    echo "Room-type pricing templates are now ACTIVE.\n";
    echo "You may now set proper default rent values.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "FAILED ❌\n";
    echo $e->getMessage();
}

echo "</pre>";
?>