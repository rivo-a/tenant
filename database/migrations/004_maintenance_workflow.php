<?php
declare(strict_types=1);

/**
 * Maintenance workflow: ownership, urgency, assignment and resolution.
 *
 *   maintenance_requests.admin_id       business scope (backfilled from the tenant)
 *   maintenance_requests.urgency        low | normal | urgent
 *   maintenance_requests.assigned_to    staff account handling it
 *   maintenance_requests.closed_at      when it reached completed or rejected
 *   maintenance_requests.resolution_note what was actually done
 *
 * Statuses are normalised to: submitted, under_review, assigned, in_progress,
 * completed, rejected. Legacy 'acknowledged' maps to under_review and
 * 'resolved' maps to completed; existing rows are migrated in place.
 *
 * Run from the project root:
 *   php database/migrations/004_maintenance_workflow.php
 */

$dbFile = dirname(__DIR__) . '/tenant_system.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$changes = [];

try {
    $pdo->beginTransaction();

    $columns = array_map(
        static fn(array $column): string => (string)$column['name'],
        $pdo->query('PRAGMA table_info(maintenance_requests)')->fetchAll()
    );

    $toAdd = [
        'admin_id' => 'ALTER TABLE maintenance_requests ADD COLUMN admin_id INTEGER DEFAULT NULL',
        'urgency' => "ALTER TABLE maintenance_requests ADD COLUMN urgency TEXT NOT NULL DEFAULT 'normal'",
        'assigned_to' => 'ALTER TABLE maintenance_requests ADD COLUMN assigned_to INTEGER DEFAULT NULL',
        'closed_at' => 'ALTER TABLE maintenance_requests ADD COLUMN closed_at DATETIME DEFAULT NULL',
        'resolution_note' => 'ALTER TABLE maintenance_requests ADD COLUMN resolution_note TEXT DEFAULT NULL',
    ];

    foreach ($toAdd as $name => $sql) {
        if (in_array($name, $columns, true)) {
            $changes[] = "Skipped maintenance_requests.{$name}";
            continue;
        }
        $pdo->exec($sql);
        $changes[] = "Added maintenance_requests.{$name}";
    }

    $backfilled = $pdo->exec("
        UPDATE maintenance_requests
        SET admin_id = (
            SELECT t.admin_id FROM tenants t WHERE t.id = maintenance_requests.tenant_id
        )
        WHERE admin_id IS NULL
    ");
    $changes[] = 'Backfilled admin_id on ' . (int)$backfilled . ' rows';

    $legacy = $pdo->exec("
        UPDATE maintenance_requests SET status = 'under_review' WHERE status = 'acknowledged'
    ");
    $changes[] = "Mapped acknowledged -> under_review ({$legacy})";

    $resolved = $pdo->exec("
        UPDATE maintenance_requests SET status = 'completed' WHERE status = 'resolved'
    ");
    $changes[] = "Mapped resolved -> completed ({$resolved})";

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_maintenance_business ON maintenance_requests(admin_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_maintenance_tenant ON maintenance_requests(tenant_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_maintenance_updates_request ON maintenance_request_updates(request_id, created_at)');

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
echo 'Maintenance workflow migration complete.' . PHP_EOL;
