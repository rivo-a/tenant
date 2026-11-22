<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/tenant_auth.php';
require_once __DIR__ . '/../../services/ArrearsService.php';
require_once __DIR__ . '/../../services/NotificationService.php';

/*
 * Balance shown here comes from ArrearsService, the same engine the landlord's
 * reports and dashboard use. A tenant must never see a figure the landlord's
 * ledger disagrees with.
 */
$tenantId = getLoggedInTenantId();
$pdo = getDB();

$arrearsService = new ArrearsService($pdo);
$position = $arrearsService->getTenantArrears($tenantId);

$stmt = $pdo->prepare("
    SELECT t.full_name, t.phone, t.email, t.move_in_date, t.rent_due_date,
           t.status AS tenant_status,
           r.room_number, rt.name AS room_type
    FROM tenants t
    LEFT JOIN rooms r ON t.room_id = r.id
    LEFT JOIN room_types rt ON r.room_type_id = rt.id
    WHERE t.id = :tenant_id
    LIMIT 1
");
$stmt->execute([':tenant_id' => $tenantId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant || !$position) {
    destroyTenantSession();
    header('Location: /tenant-system/public/portal/login.php?error=invalid');
    exit;
}

$rent = (float)($position['rent'] ?? 0);
$balance = (float)($position['balance'] ?? 0);
$verifiedPaid = (float)($position['verified_paid'] ?? 0);
$pendingPaid = (float)($position['pending_paid'] ?? 0);
$monthsDue = (int)($position['months_due'] ?? 0);
$isOverdue = (bool)($position['is_overdue'] ?? false);
$daysOverdue = (int)($position['days_overdue'] ?? 0);

if ($balance <= 0.00001) {
    $statusLabel = 'Paid';
    $statusTone = 'bg-emerald-50 text-emerald-700 ring-emerald-600/20';
} elseif ($isOverdue) {
    $statusLabel = 'Overdue';
    $statusTone = 'bg-red-50 text-red-700 ring-red-600/20';
} elseif ($pendingPaid > 0) {
    $statusLabel = 'Pending verification';
    $statusTone = 'bg-amber-50 text-amber-700 ring-amber-600/20';
} else {
    $statusLabel = 'Due';
    $statusTone = 'bg-sky-50 text-sky-700 ring-sky-600/20';
}

$recentStmt = $pdo->prepare("
    SELECT amount, payment_month, payment_date, method, verification_status, receipt_no, created_at
    FROM payments
    WHERE tenant_id = :tenant_id
    ORDER BY payment_date DESC, id DESC
    LIMIT 5
");
$recentStmt->execute([':tenant_id' => $tenantId]);
$recentPayments = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

$notifier = new NotificationService($pdo);
$alerts = $notifier->feed($tenantId, 3);
$unreadAlerts = $notifier->unreadCount($tenantId);

function getStatusBadge(string $status): string
{
    return match (strtolower($status)) {
        'done', 'confirmed' => '<span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Confirmed</span>',
        'pending' => '<span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending verification</span>',
        'reversed' => '<span class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20">Reversed</span>',
        default => '<span class="inline-flex items-center rounded-full bg-slate-50 px-2 py-1 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-500/10">' . e(strtolower($status)) . '</span>',
    };
}

function portalMoney(float $value): string
{
    return 'UGX ' . number_format($value, 0);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My rent — Tenant Portal</title>
    <link rel="stylesheet" href="/tenant-system/public/assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

    <?php require __DIR__ . '/partials/portal_navbar.php'; ?>

    <main class="mx-auto max-w-5xl px-4 py-6 sm:px-6">

        <header class="mb-6">
            <h1 class="text-xl font-bold tracking-tight sm:text-2xl">
                <?= e($tenant['full_name']) ?>
            </h1>
            <p class="mt-1 text-sm text-slate-600">
                Room <?= e($tenant['room_number'] ?? 'unassigned') ?><?php if (!empty($tenant['room_type'])): ?> · <?= e($tenant['room_type']) ?><?php endif; ?>
            </p>
        </header>

        <section class="mb-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="Rent position">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Balance due</p>
                    <p class="mt-1 text-3xl font-bold tabular-nums"><?= e(portalMoney($balance)) ?></p>
                </div>
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset <?= $statusTone ?>">
                    <?= e($statusLabel) ?>
                </span>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-500">Monthly rent</dt>
                    <dd class="mt-0.5 font-semibold tabular-nums"><?= e(portalMoney($rent)) ?></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Next due</dt>
                    <dd class="mt-0.5 font-semibold"><?= e($tenant['rent_due_date'] ? date('d M Y', strtotime((string)$tenant['rent_due_date'])) : 'Not set') ?></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Months billed</dt>
                    <dd class="mt-0.5 font-semibold tabular-nums"><?= $monthsDue ?></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Confirmed paid</dt>
                    <dd class="mt-0.5 font-semibold tabular-nums"><?= e(portalMoney($verifiedPaid)) ?></dd>
                </div>
            </dl>

            <?php if ($pendingPaid > 0): ?>
                <p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                    <?= e(portalMoney($pendingPaid)) ?> is recorded and waiting for the landlord to confirm it.
                    It is not yet counted as paid.
                </p>
            <?php endif; ?>

            <?php if ($isOverdue && $daysOverdue > 0): ?>
                <p class="mt-3 rounded-xl bg-red-50 px-3 py-2 text-xs font-medium text-red-800">
                    Rent is <?= $daysOverdue ?> day<?= $daysOverdue === 1 ? '' : 's' ?> past the due date.
                </p>
            <?php endif; ?>
        </section>

        <section class="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="recent-title">
            <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-3">
                <h2 id="recent-title" class="text-sm font-semibold uppercase tracking-wide text-slate-500">Recent payments</h2>
            </div>

            <?php if (!$recentPayments): ?>
                <div class="px-5 py-8 text-center text-sm text-slate-600">
                    No payments recorded yet. Once the landlord records a payment it will appear here.
                </div>
            <?php else: ?>
                <ul role="list" class="divide-y divide-slate-100">
                    <?php foreach ($recentPayments as $payment): ?>
                        <li class="flex items-start justify-between gap-4 px-5 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold tabular-nums"><?= e(portalMoney((float)$payment['amount'])) ?></p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    <?= e($payment['payment_month'] ?: '—') ?>
                                    · <?= e(date('d M Y', strtotime((string)($payment['payment_date'] ?? $payment['created_at'])))) ?>
                                    <?php if (!empty($payment['method'])): ?>
                                        · <?= e(ucfirst((string)$payment['method'])) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($payment['receipt_no'])): ?>
                                        · <?= e((string)$payment['receipt_no']) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?= getStatusBadge((string)$payment['verification_status']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="mb-5 rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="alerts-title">
            <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-3">
                <h2 id="alerts-title" class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                    Alerts<?php if ($unreadAlerts > 0): ?> <span class="ml-1 rounded-full bg-sky-600 px-1.5 py-0.5 text-[11px] font-bold text-white"><?= $unreadAlerts ?></span><?php endif; ?>
                </h2>
                <a href="notifications.php" class="text-xs font-semibold text-slate-600 hover:text-slate-900">All alerts</a>
            </div>

            <?php if (!$alerts): ?>
                <p class="px-5 py-6 text-center text-sm text-slate-600">No updates from your landlord yet.</p>
            <?php else: ?>
                <ul role="list" class="divide-y divide-slate-100">
                    <?php foreach ($alerts as $alert): ?>
                        <li class="px-5 py-3">
                            <p class="text-sm font-medium <?= empty($alert['read_at']) ? 'text-slate-900' : 'text-slate-700' ?>">
                                <?= empty($alert['read_at']) ? '<span class="mr-1.5 inline-block h-2 w-2 rounded-full bg-sky-500" aria-label="Unread"></span>' : '' ?>
                                <?= e($alert['title']) ?>
                            </p>
                            <?php if (!empty($alert['body'])): ?>
                                <p class="mt-0.5 text-xs text-slate-600"><?= e($alert['body']) ?></p>
                            <?php endif; ?>
                            <p class="mt-1 text-xs text-slate-500"><?= e(date('d M Y, H:i', strtotime((string)$alert['created_at']))) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="tenancy-title">
            <h2 id="tenancy-title" class="text-sm font-semibold uppercase tracking-wide text-slate-500">Tenancy</h2>
            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-500">Phone</dt>
                    <dd class="mt-0.5 font-medium"><?= e($tenant['phone'] ?: 'Not set') ?></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Moved in</dt>
                    <dd class="mt-0.5 font-medium"><?= e($tenant['move_in_date'] ? date('d M Y', strtotime((string)$tenant['move_in_date'])) : '—') ?></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Status</dt>
                    <dd class="mt-0.5 font-medium"><?= e(ucfirst((string)($tenant['tenant_status'] ?: 'active'))) ?></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Total billed</dt>
                    <dd class="mt-0.5 font-medium tabular-nums"><?= e(portalMoney((float)($position['expected'] ?? 0))) ?></dd>
                </div>
            </dl>
        </section>

    </main>
</body>
</html>
