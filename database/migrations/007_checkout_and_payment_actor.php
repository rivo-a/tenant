<?php
declare(strict_types=1);

/**
 * Tenant checkout records and payment actor.
 *
 *   tenant_exits          permanent record of each exit: date, balance at that
 *                         date, why, whether arrears were allowed, who did it
 *   payments.created_by   who recorded the payment, so an employee cannot
 *                         approve their own entry
 *
 * Both are additive. No existing row is rewritten; historical payments keep
 * created_by NULL, which is treated as "unknown recorder".
 *
 * Run from the project root:
 *   php database/migrations/007_checkout_and_payment_actor.php
 */

$dbFile = dirname(__DIR__) . '/tenant_system.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$changes = [];

try {
    $pdo->beginTransaction();

    if ((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='tenant_exits'")->fetchColumn() > 0) {
        $changes[] = 'Skipped tenant_exits table';
    } else {
        $pdo->exec("
            CREATE TABLE tenant_exits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                admin_id INTEGER NOT NULL,
                exit_date TEXT NOT NULL,
                rent_at_exit REAL NOT NULL DEFAULT 0,
                billed_total REAL NOT NULL DEFAULT 0,
                paid_total REAL NOT NULL DEFAULT 0,
                final_balance REAL NOT NULL DEFAULT 0,
                reason TEXT NOT NULL,
                allowed_uncleared INTEGER NOT NULL DEFAULT 0,
                recorded_by INTEGER DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
            )
        ");
        $changes[] = 'Created tenant_exits table';
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tenant_exits_tenant ON tenant_exits(tenant_id, exit_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tenant_exits_business ON tenant_exits(admin_id)');

    $paymentColumns = array_map(
        static fn(array $column): string => (string)$column['name'],
        $pdo->query('PRAGMA table_info(payments)')->fetchAll()
    );

    if (in_array('created_by', $paymentColumns, true)) {
        $changes[] = 'Skipped payments.created_by';
    } else {
        $pdo->exec('ALTER TABLE payments ADD COLUMN created_by INTEGER DEFAULT NULL');
        $changes[] = 'Added payments.created_by (historical rows left NULL = unknown)';
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payments_created_by ON payments(created_by)');

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
echo 'Checkout and payment actor migration complete.' . PHP_EOL;
