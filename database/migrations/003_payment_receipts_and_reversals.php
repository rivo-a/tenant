<?php
declare(strict_types=1);

/**
 * Receipt numbers and reversals for the reporting release.
 *
 *   payments.receipt_no       stable human reference, one per payment row
 *   payments.reversed_at/_by  who reversed it and when
 *   payments.reversal_reason  short free text
 *
 * A reversed payment keeps its row and its amount. It is excluded from every
 * collected-income total by verification_status = 'reversed', so history stays
 * complete and no figure is silently rewritten.
 *
 * Backfill is deterministic: R-<year of payment_date>-<id padded to 6>.
 * Existing rows are only touched to add a reference; no amount, date or status
 * of a historical payment is changed.
 *
 * Run from the project root:
 *   php database/migrations/003_payment_receipts_and_reversals.php
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
        $pdo->query('PRAGMA table_info(payments)')->fetchAll()
    );

    $toAdd = [
        'receipt_no' => 'ALTER TABLE payments ADD COLUMN receipt_no TEXT DEFAULT NULL',
        'reversed_at' => 'ALTER TABLE payments ADD COLUMN reversed_at DATETIME DEFAULT NULL',
        'reversed_by' => 'ALTER TABLE payments ADD COLUMN reversed_by INTEGER DEFAULT NULL',
        'reversal_reason' => 'ALTER TABLE payments ADD COLUMN reversal_reason TEXT DEFAULT NULL',
    ];

    foreach ($toAdd as $name => $sql) {
        if (in_array($name, $columns, true)) {
            $changes[] = "Skipped payments.{$name}";
            continue;
        }

        $pdo->exec($sql);
        $changes[] = "Added payments.{$name}";
    }

    // Deterministic backfill for rows that have no reference yet.
    $missing = $pdo->query(
        "SELECT id, COALESCE(NULLIF(payment_date, ''), substr(created_at, 1, 10)) AS d
         FROM payments
         WHERE receipt_no IS NULL OR receipt_no = ''"
    )->fetchAll();

    $update = $pdo->prepare('UPDATE payments SET receipt_no = :receipt WHERE id = :id');
    foreach ($missing as $row) {
        $year = substr((string)($row['d'] ?? ''), 0, 4);
        if (!preg_match('/^\d{4}$/', $year)) {
            $year = date('Y');
        }

        $receipt = 'R-' . $year . '-' . str_pad((string)(int)$row['id'], 6, '0', STR_PAD_LEFT);
        $update->execute([':receipt' => $receipt, ':id' => (int)$row['id']]);
    }
    $changes[] = 'Backfilled ' . count($missing) . ' receipt numbers';

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_payments_receipt ON payments(receipt_no)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payments_status_date ON payments(verification_status, payment_date)');
    $changes[] = 'Indexes ensured';

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
echo 'Payment receipts and reversals migration complete.' . PHP_EOL;
