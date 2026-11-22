<?php
declare(strict_types=1);

/**
 * Historical rent charges.
 *
 * Until now arrears billed `months × current rent`, so raising or lowering a
 * rent retroactively rewrote every past month. Each billing month now gets a
 * charge row that records what was actually charged at the time.
 *
 * Backfill assumption, stated plainly: months before this migration are
 * charged at the rent that is in force today (tenant override, then room,
 * then room-type default). That is the best evidence the database holds; it
 * cannot recreate a rent that was never recorded. Months from now on record
 * the rent in force when they are billed, so no future change rewrites them.
 *
 * Run from the project root:
 *   php database/migrations/006_rent_charges.php
 */

$dbFile = dirname(__DIR__) . '/tenant_system.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$changes = [];

try {
    $pdo->beginTransaction();

    if ((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='rent_charges'")->fetchColumn() > 0) {
        $changes[] = 'Skipped rent_charges table';
    } else {
        $pdo->exec("
            CREATE TABLE rent_charges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                admin_id INTEGER NOT NULL,
                month TEXT NOT NULL,
                amount REAL NOT NULL DEFAULT 0,
                source TEXT NOT NULL DEFAULT 'backfill',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
            )
        ");
        $changes[] = 'Created rent_charges table';
    }

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_rent_charges_tenant_month ON rent_charges(tenant_id, month)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rent_charges_business ON rent_charges(admin_id, month)');

    $existing = (int)$pdo->query('SELECT COUNT(*) FROM rent_charges')->fetchColumn();

    if ($existing > 0) {
        $changes[] = "Skipped backfill, {$existing} charges already present";
    } else {
        $tenants = $pdo->query("
            SELECT t.id, t.admin_id, t.move_in_date,
                   COALESCE(NULLIF(t.monthly_rent, 0), NULLIF(r.monthly_rent, 0), NULLIF(rt.default_monthly_rent, 0), 0) AS rent
            FROM tenants t
            LEFT JOIN rooms r ON r.id = t.room_id
            LEFT JOIN room_types rt ON rt.id = r.room_type_id
            WHERE t.move_in_date IS NOT NULL AND TRIM(t.move_in_date) <> ''
        ")->fetchAll();

        $insert = $pdo->prepare('
            INSERT OR IGNORE INTO rent_charges (tenant_id, admin_id, month, amount, source)
            VALUES (:tenant_id, :admin_id, :month, :amount, :source)
        ');

        $todayMonth = date('Y-m');
        $count = 0;

        foreach ($tenants as $tenant) {
            $rent = (float)$tenant['rent'];
            if ($rent <= 0) {
                continue;
            }

            $cursor = strtotime(date('Y-m-01', strtotime((string)$tenant['move_in_date'])));
            $end = strtotime(date('Y-m-01', strtotime($todayMonth . '-01')));
            $guard = 0;

            while ($cursor <= $end && $guard < 600) {
                $insert->execute([
                    ':tenant_id' => (int)$tenant['id'],
                    ':admin_id' => (int)$tenant['admin_id'],
                    ':month' => date('Y-m', $cursor),
                    ':amount' => $rent,
                    ':source' => 'backfill',
                ]);
                $count += $insert->rowCount();
                $cursor = strtotime('+1 month', $cursor);
                $guard++;
            }
        }

        $changes[] = "Backfilled {$count} monthly rent charges";
    }

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
echo 'Rent charges migration complete.' . PHP_EOL;
