<?php
declare(strict_types=1);

/**
 * Add the controlled payment-verification workflow without rewriting history.
 *
 * Run from the project root:
 *   php database/migrations/001_payment_verification.php
 */

$dbFile = dirname(__DIR__) . '/tenant_system.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$changes = [];

try {
    $pdo->beginTransaction();

    $paymentColumns = $pdo->query('PRAGMA table_info(payments)')->fetchAll();
    $paymentColumnNames = array_map(
        static fn(array $column): string => (string)$column['name'],
        $paymentColumns
    );

    $paymentColumnsToAdd = [
        'verification_status' => "ALTER TABLE payments ADD COLUMN verification_status TEXT NOT NULL DEFAULT 'done'",
        'verification_confirmed_at' => 'ALTER TABLE payments ADD COLUMN verification_confirmed_at DATETIME',
        'verification_confirmed_by' => 'ALTER TABLE payments ADD COLUMN verification_confirmed_by INTEGER',
        'verification_done_at' => 'ALTER TABLE payments ADD COLUMN verification_done_at DATETIME',
        'verification_done_by' => 'ALTER TABLE payments ADD COLUMN verification_done_by INTEGER',
    ];

    foreach ($paymentColumnsToAdd as $columnName => $sql) {
        if (in_array($columnName, $paymentColumnNames, true)) {
            $changes[] = "Skipped payments.{$columnName}";
            continue;
        }

        $pdo->exec($sql);
        $changes[] = "Added payments.{$columnName}";
    }

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_payments_verification_status_admin
         ON payments(verification_status, admin_id)'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_payments_tenant_month
         ON payments(tenant_id, payment_month)'
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tenant_access_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            created_by INTEGER NOT NULL,
            last_accessed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE RESTRICT
        )"
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_tenant_access_tokens_tenant
         ON tenant_access_tokens(tenant_id)'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_tenant_access_tokens_expiry
         ON tenant_access_tokens(expires_at, revoked_at)'
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
echo "Payment verification migration complete." . PHP_EOL;
