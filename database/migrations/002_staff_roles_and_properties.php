<?php
declare(strict_types=1);

/**
 * Staff tiers and named properties.
 *
 * Additive only:
 *   admins.is_active    suspend a staff account without deleting history
 *   admins.landlord_id  the business a staff member belongs to (NULL = this
 *                       account is itself the landlord/owner of its data)
 *   properties          a landlord may run several named properties
 *   rooms.property_id   room belongs to one property (NULL = unassigned)
 *
 * No existing role value is rewritten. Legacy role 'caretaker' keeps meaning
 * "landlord" and is aliased at read time by core/auth.php, so this migration
 * can be reverted by a code revert with no data loss.
 *
 * Run from the project root:
 *   php database/migrations/002_staff_roles_and_properties.php
 */

$dbFile = dirname(__DIR__) . '/tenant_system.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$changes = [];

try {
    $pdo->beginTransaction();

    $adminColumns = array_map(
        static fn(array $column): string => (string)$column['name'],
        $pdo->query('PRAGMA table_info(admins)')->fetchAll()
    );

    $adminColumnsToAdd = [
        'is_active' => 'ALTER TABLE admins ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1',
        'landlord_id' => 'ALTER TABLE admins ADD COLUMN landlord_id INTEGER DEFAULT NULL',
    ];

    foreach ($adminColumnsToAdd as $columnName => $sql) {
        if (in_array($columnName, $adminColumns, true)) {
            $changes[] = "Skipped admins.{$columnName}";
            continue;
        }

        $pdo->exec($sql);
        $changes[] = "Added admins.{$columnName}";
    }

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_admins_landlord ON admins(landlord_id)'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_admins_active ON admins(is_active)'
    );

    if ((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'properties'")->fetchColumn() > 0) {
        $changes[] = 'Skipped properties table';
    } else {
        $pdo->exec(
            "CREATE TABLE properties (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                address TEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
            )"
        );
        $changes[] = 'Created properties table';
    }

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_properties_admin ON properties(admin_id)'
    );

    $roomColumns = array_map(
        static fn(array $column): string => (string)$column['name'],
        $pdo->query('PRAGMA table_info(rooms)')->fetchAll()
    );

    if (in_array('property_id', $roomColumns, true)) {
        $changes[] = 'Skipped rooms.property_id';
    } else {
        $pdo->exec('ALTER TABLE rooms ADD COLUMN property_id INTEGER DEFAULT NULL');
        $changes[] = 'Added rooms.property_id';
    }

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_rooms_property ON rooms(property_id)'
    );

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($changes as $change) {
    echo $change . PHP_EOL;
}
echo 'Staff roles and properties migration complete.' . PHP_EOL;
