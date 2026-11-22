<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/tenant_auth.php';

requireTenantAuth();

$tenantId = getLoggedInTenantId();
$pdo = getDB();

$paymentId = max(0, (int)($_GET['payment'] ?? 0));

/*
 * Scoped by tenant_id as well as id: another tenant's receipt returns 404,
 * and there is no guessable public token to leak.
 */
$stmt = $pdo->prepare("
    SELECT p.id, p.receipt_no, p.amount, p.payment_date, p.payment_month, p.method,
           p.verification_status, p.verification_confirmed_at, p.verification_done_at,
           p.reversed_at, p.reversal_reason, p.created_at, p.note,
           t.full_name AS tenant_name, t.phone AS tenant_phone,
           r.room_number, pr.name AS property_name,
           verifier.full_name AS verified_by
    FROM payments p
    JOIN tenants t ON t.id = p.tenant_id
    LEFT JOIN rooms r ON r.id = t.room_id
    LEFT JOIN properties pr ON pr.id = r.property_id
    LEFT JOIN admins verifier ON verifier.id = COALESCE(p.verification_done_by, p.verification_confirmed_by)
    WHERE p.id = :id AND p.tenant_id = :tenant_id
    LIMIT 1
");
$stmt->execute([':id' => $paymentId, ':tenant_id' => $tenantId]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$receipt) {
    http_response_code(404);
    exit('Receipt not found.');
}

$status = strtolower((string)$receipt['verification_status']);
$statusLabel = match ($status) {
    'confirmed', 'done' => 'Confirmed',
    'pending' => 'Pending verification',
    'reversed' => 'Reversed',
    default => ucfirst($status),
};

$money = static fn($value): string => 'UGX ' . number_format((float)$value, 0);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?= e((string)$receipt['receipt_no']) ?> — Tenant Portal</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/tenant-system/public/assets/css/tailwind.css">
    <style>@media print { .no-print { display: none !important; } body { background: #fff; } }</style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<main class="mx-auto max-w-xl px-4 py-6 sm:px-6">

    <div class="mb-3 flex items-center justify-between no-print">
        <a href="payments.php" class="text-sm font-semibold text-slate-600 hover:text-slate-900">← All payments</a>
        <button type="button" id="printBtn" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            Print receipt
        </button>
    </div>

    <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <header class="flex items-start justify-between gap-4 border-b border-slate-200 pb-4">
            <div>
                <p class="text-lg font-extrabold tracking-tight">Urbahan</p>
                <p class="text-xs text-slate-500">Payment receipt</p>
            </div>
            <div class="text-right">
                <p class="font-mono text-sm font-semibold"><?= e((string)($receipt['receipt_no'] ?? '—')) ?></p>
                <p class="text-xs text-slate-500"><?= e(date('d M Y', strtotime((string)$receipt['created_at']))) ?></p>
            </div>
        </header>

        <div class="mt-4 flex items-baseline justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-500">Amount received</p>
                <p class="mt-1 text-2xl font-bold tabular-nums"><?= e($money($receipt['amount'])) ?></p>
            </div>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset <?= match ($status) {
                'confirmed', 'done' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
                'pending' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
                'reversed' => 'bg-red-50 text-red-700 ring-red-600/20',
                default => 'bg-slate-50 text-slate-600 ring-slate-500/10',
            } ?>"><?= e($statusLabel) ?></span>
        </div>

        <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-slate-100 pt-4 text-sm">
            <div>
                <dt class="text-xs text-slate-500">Tenant</dt>
                <dd class="mt-0.5 font-medium"><?= e($receipt['tenant_name']) ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Property</dt>
                <dd class="mt-0.5 font-medium"><?= e($receipt['property_name'] ?? 'Unassigned') ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Room</dt>
                <dd class="mt-0.5 font-medium"><?= e($receipt['room_number'] ?? '—') ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Rent period</dt>
                <dd class="mt-0.5 font-medium"><?= e($receipt['payment_month'] ?: '—') ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Date paid</dt>
                <dd class="mt-0.5 font-medium"><?= e($receipt['payment_date'] ? date('d M Y', strtotime((string)$receipt['payment_date'])) : '—') ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Method</dt>
                <dd class="mt-0.5 font-medium"><?= e(ucfirst((string)($receipt['method'] ?? '—'))) ?></dd>
            </div>
            <?php if (!empty($receipt['verified_by'])): ?>
                <div>
                    <dt class="text-xs text-slate-500">Confirmed by</dt>
                    <dd class="mt-0.5 font-medium"><?= e($receipt['verified_by']) ?></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Confirmed on</dt>
                    <dd class="mt-0.5 font-medium"><?= e(date('d M Y', strtotime((string)($receipt['verification_done_at'] ?? $receipt['verification_confirmed_at'])))) ?></dd>
                </div>
            <?php endif; ?>
        </dl>

        <?php if ($status === 'reversed'): ?>
            <p class="mt-4 rounded-xl bg-red-50 px-3 py-2 text-xs font-medium text-red-800">
                This payment was reversed<?= !empty($receipt['reversal_reason']) ? ': ' . e((string)$receipt['reversal_reason']) : '' ?>.
                It is kept for your records and is no longer counted as rent paid.
            </p>
        <?php elseif ($status === 'pending'): ?>
            <p class="mt-4 rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                Your landlord has recorded this payment but not yet confirmed it. It is not counted as paid until confirmed.
            </p>
        <?php endif; ?>

        <p class="mt-5 border-t border-slate-100 pt-3 text-xs text-slate-500">
            Keep this receipt reference (<?= e((string)($receipt['receipt_no'] ?? '—')) ?>) for any query about this payment.
        </p>
    </article>
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
