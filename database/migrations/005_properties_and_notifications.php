<?php
declare(strict_types=1);

/**
 * Reporting foundation and tenant notifications.
 *
 *   properties.*            already created in 002; this only ensures the
 *                           optional columns exist for editing
 *   notifications           one row per event a tenant should see
 *
 * Deduplication: `dedupe_key` is UNIQUE, so retrying a request cannot create a
 * second notification for the same event.
 *
 * Run from the project root:
 *   php database/migrations/005_properties_and_notifications.php
 */

$dbFile = dirname(__DIR__) . '/tenant_system.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$changes = [];

try {
    $pdo->beginTransaction();

    // properties: keep address/notes editable, add a sort label
    $propertyColumns = array_map(
        static fn(array $column): string => (string)$column['name'],
        $pdo->query('PRAGMA table_info(properties)')->fetchAll()
    );

    foreach (['address' => 'ALTER TABLE properties ADD COLUMN address TEXT DEFAULT NULL',
              'notes' => 'ALTER TABLE properties ADD COLUMN notes TEXT DEFAULT NULL'] as $name => $sql) {
        if (in_array($name, $propertyColumns, true)) {
            $changes[] = "Skipped properties.{$name}";
            continue;
        }
        $pdo->exec($sql);
        $changes[] = "Added properties.{$name}";
    }

    if ((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='notifications'")->fetchColumn() > 0) {
        $changes[] = 'Skipped notifications table';
    } else {
        $pdo->exec("
            CREATE TABLE notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                admin_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                title TEXT NOT NULL,
                body TEXT DEFAULT NULL,
                target_type TEXT DEFAULT NULL,
                target_id INTEGER DEFAULT NULL,
                dedupe_key TEXT NOT NULL UNIQUE,
                read_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
            )
        ");
        $changes[] = 'Created notifications table';
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_tenant ON notifications(tenant_id, read_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_business ON notifications(admin_id, created_at)');

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
echo 'Properties and notifications migration complete.' . PHP_EOL;
