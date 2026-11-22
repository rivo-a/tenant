<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/tenant_auth.php';
require_once __DIR__ . '/../../services/ArrearsService.php';

requireTenantAuth();

$tenantId = getLoggedInTenantId();
$pdo = getDB();

$service = new ArrearsService($pdo);
$position = $service->getTenantArrears($tenantId);

if (!$position) {
    http_response_code(404);
    exit('Tenancy not found.');
}

$tenantStmt = $pdo->prepare('SELECT full_name, move_in_date, rent_due_date FROM tenants WHERE id = :id');
$tenantStmt->execute([':id' => $tenantId]);
$tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC) ?: ['full_name' => 'Tenant', 'move_in_date' => null, 'rent_due_date' => null];

$rent = (float)$position['rent'];
$moveInMonth = $tenant['move_in_date'] ? date('Y-m-01', strtotime((string)$tenant['move_in_date'])) : date('Y-m-01');

$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = $moveInMonth;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

/* Months billed inside the statement window, never before move-in. */
$startMonth = max(strtotime($moveInMonth), strtotime(date('Y-m-01', strtotime($from))));
$startMonth = date('Y-m-01', $startMonth);
$endMonth = date('Y-m-01', strtotime($to));

$months = [];
for ($cursor = strtotime($startMonth); $cursor <= strtotime($endMonth); $cursor = strtotime('+1 month', $cursor)) {
    $months[] = date('Y-m', $cursor);
}

$charges = array_map(static fn(string $month): array => [
    'month' => $month,
    'label' => date('M Y', strtotime($month . '-01')),
    'rent' => $rent,
], $months);
$totalCharges = array_sum(array_column($charges, 'rent'));

/*
 * Opening balance: everything billed before the window, less everything
 * already handed over before the window (confirmed plus pending, matching
 * how ArrearsService treats money the tenant has paid).
 */
$monthToSql = static function (string $month): string {
    return $month . '-01';
};

$beforeStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN LOWER(COALESCE(verification_status, 'done')) IN ('confirmed','done','pending') THEN amount ELSE 0 END), 0) AS paid
    FROM payments
    WHERE tenant_id = :tenant_id AND payment_date < :from
");
$beforeStmt->execute([':tenant_id' => $tenantId, ':from' => date('Y-m-01', strtotime($from))]);
$paidBefore = (float)$beforeStmt->fetchColumn();

$monthsBefore = 0;
for ($cursor = strtotime($moveInMonth); $cursor < strtotime($startMonth); $cursor = strtotime('+1 month', $cursor)) {
    $monthsBefore++;
}
$opening = max(0.0, ($monthsBefore * $rent) - $paidBefore);

$paymentsStmt = $pdo->prepare("
    SELECT id, receipt_no, amount, payment_date, payment_month, method, verification_status
    FROM payments
    WHERE tenant_id = :tenant_id
      AND payment_date >= :from
      AND payment_date <= :to
    ORDER BY payment_date ASC, id ASC
");
$paymentsStmt->execute([
    ':tenant_id' => $tenantId,
    ':from' => date('Y-m-01', strtotime($from)),
    ':to' => $to,
]);
$payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$confirmedInPeriod = 0.0;
$pendingInPeriod = 0.0;
$reversedInPeriod = 0.0;
foreach ($payments as $payment) {
    $status = strtolower((string)$payment['verification_status']);
    if ($status === 'pending') {
        $pendingInPeriod += (float)$payment['amount'];
    } elseif ($status === 'reversed') {
        $reversedInPeriod += (float)$payment['amount'];
    } else {
        $confirmedInPeriod += (float)$payment['amount'];
    }
}

$closing = $opening + $totalCharges - $confirmedInPeriod - $pendingInPeriod;

$money = static fn($value): string => 'UGX ' . number_format((float)$value, 0);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rent statement — Tenant Portal</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/tenant-system/public/assets/css/tailwind.css">
    <style>@media print { .no-print { display: none !important; } body { background: #fff; } }</style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<?php require __DIR__ . '/partials/portal_navbar.php'; ?>

<main class="mx-auto max-w-3xl px-4 py-6 sm:px-6">

    <div class="mb-3 flex items-center justify-between no-print">
        <a href="payments.php" class="text-sm font-semibold text-slate-600 hover:text-slate-900">← All payments</a>
        <button type="button" id="printBtn" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            Print statement
        </button>
    </div>

    <header class="mb-4">
        <h1 class="text-xl font-bold tracking-tight sm:text-2xl">Rent statement</h1>
        <p class="mt-1 text-sm text-slate-600">
            <?= e($tenant['full_name']) ?> · <?= e(date('d M Y', strtotime($from))) ?> to <?= e(date('d M Y', strtotime($to))) ?>
        </p>
    </header>

    <form method="get" class="mb-4 grid gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm no-print sm:grid-cols-3">
        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            From
            <input type="date" name="from" value="<?= e($from) ?>" class="min-h-11 rounded-xl border border-slate-300 px-2 py-2 text-sm font-normal">
        </label>
        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            To
            <input type="date" name="to" value="<?= e($to) ?>" class="min-h-11 rounded-xl border border-slate-300 px-2 py-2 text-sm font-normal">
        </label>
        <div class="flex items-end">
            <button class="min-h-11 w-full rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Apply</button>
        </div>
    </form>

    <section class="mb-4 grid gap-3 sm:grid-cols-2" aria-label="Statement summary">
        <?php foreach ([
            ['Opening balance', $money($opening), ''],
            ['Rent charged', $money($totalCharges), count($charges) . ' months'],
            ['Confirmed paid', $money($confirmedInPeriod), ''],
            ['Closing balance', $money(max(0.0, $closing)), $pendingInPeriod > 0 ? $money($pendingInPeriod) . ' awaiting confirmation' : ''],
        ] as [$label, $value, $hint]): ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500"><?= e($label) ?></p>
                <p class="mt-1 text-xl font-bold tabular-nums"><?= e($value) ?></p>
                <?php if ($hint !== ''): ?><p class="mt-1 text-xs text-slate-500"><?= e($hint) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="mb-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <h2 class="border-b border-slate-200 px-4 py-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Rent charged</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2 font-semibold">Month</th>
                        <th class="px-4 py-2 font-semibold text-right">Rent</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($charges as $charge): ?>
                        <tr>
                            <td class="px-4 py-2"><?= e($charge['label']) ?></td>
                            <td class="px-4 py-2 text-right tabular-nums"><?= e($money($charge['rent'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-slate-50">
                    <tr>
                        <td class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Total charged</td>
                        <td class="px-4 py-2 text-right font-bold tabular-nums"><?= e($money($totalCharges)) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <h2 class="border-b border-slate-200 px-4 py-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Payments received</h2>
        <?php if (!$payments): ?>
            <p class="px-4 py-6 text-center text-sm text-slate-600">No payments were received in this period.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Receipt</th>
                            <th class="px-4 py-2 font-semibold">Paid on</th>
                            <th class="px-4 py-2 font-semibold">Period</th>
                            <th class="px-4 py-2 font-semibold">Status</th>
                            <th class="px-4 py-2 font-semibold text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($payments as $payment): ?>
                            <?php $status = strtolower((string)$payment['verification_status']); ?>
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs text-slate-600">
                                    <a href="receipt.php?payment=<?= (int)$payment['id'] ?>" class="underline decoration-slate-300 underline-offset-2 hover:decoration-slate-900">
                                        <?= e((string)($payment['receipt_no'] ?? '—')) ?>
                                    </a>
                                </td>
                                <td class="px-4 py-2 text-slate-600"><?= e(date('d M Y', strtotime((string)$payment['payment_date']))) ?></td>
                                <td class="px-4 py-2 text-slate-600"><?= e($payment['payment_month'] ?: '—') ?></td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset <?= match ($status) {
                                        'confirmed', 'done' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
                                        'pending' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
                                        'reversed' => 'bg-red-50 text-red-700 ring-red-600/20',
                                        default => 'bg-slate-50 text-slate-600 ring-slate-500/10',
                                    } ?>">
                                        <?= e($status === 'pending' ? 'Pending' : ($status === 'reversed' ? 'Reversed' : 'Confirmed')) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right font-semibold tabular-nums"><?= e($money($payment['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <p class="mt-3 text-xs text-slate-500">
        Your current balance is <?= e($money($position['balance'])) ?>.
        Pending payments are shown separately and only count once your landlord confirms them.
    </p>

</main>

<script nonce="<?= e(csp_nonce()) ?>">
    (function () {
        var button = document.getElementById('printBtn');
        if (button) {
            button.addEventListener('click', function () { window.print(); });
        }
    })();
</script>
</body>
</html>
