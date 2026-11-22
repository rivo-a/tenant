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

$status = strtolower(trim((string)($_GET['status'] ?? '')));
if (!in_array($status, ['', 'pending', 'confirmed', 'done', 'reversed'], true)) {
    $status = '';
}
$search = trim((string)($_GET['q'] ?? ''));
$month = trim((string)($_GET['month'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = ['p.tenant_id = :tenant_id'];
$params = [':tenant_id' => $tenantId];

if ($status !== '') {
    $where[] = "LOWER(COALESCE(p.verification_status, 'done')) = :status";
    $params[':status'] = $status;
}
if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $where[] = 'p.payment_month = :month';
    $params[':month'] = $month;
}
if ($search !== '') {
    $where[] = '(p.receipt_no LIKE :search OR p.payment_month LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$stmt = $pdo->prepare("
    SELECT p.id, p.receipt_no, p.amount, p.payment_date, p.payment_month, p.method,
           p.verification_status, p.reversal_reason
    FROM payments p
    {$whereSql}
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$position = (new ArrearsService($pdo))->getTenantArrears($tenantId);

$money = static fn($value): string => 'UGX ' . number_format((float)$value, 0);

$statusBadge = static function (string $value): array {
    return match (strtolower($value)) {
        'confirmed', 'done' => ['Confirmed', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'],
        'pending' => ['Pending verification', 'bg-amber-50 text-amber-700 ring-amber-600/20'],
        'reversed' => ['Reversed', 'bg-red-50 text-red-700 ring-red-600/20'],
        default => [ucfirst(strtolower($value)), 'bg-slate-50 text-slate-600 ring-slate-500/10'],
    };
};

$link = static function (array $overrides) use ($status, $search, $month): string {
    return 'payments.php?' . http_build_query(array_filter(array_merge([
        'status' => $status, 'q' => $search, 'month' => $month,
    ], $overrides), static fn($v) => $v !== '' && $v !== null));
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My payments — Tenant Portal</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/tenant-system/public/assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<?php require __DIR__ . '/partials/portal_navbar.php'; ?>

<main class="mx-auto max-w-4xl px-4 py-6 sm:px-6">

    <header class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold tracking-tight sm:text-2xl">My payments</h1>
            <p class="mt-1 text-sm text-slate-600">Every payment your landlord recorded, with its receipt number.</p>
        </div>
        <?php if ($position): ?>
            <a href="statement.php" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Rent statement
            </a>
        <?php endif; ?>
    </header>

    <?php if ($position): ?>
        <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-sm">
            <span class="text-slate-600">Balance due <strong class="ml-1 tabular-nums text-slate-900"><?= e($money($position['balance'])) ?></strong></span>
            <span class="text-slate-600">Confirmed paid <strong class="ml-1 tabular-nums text-slate-900"><?= e($money($position['verified_paid'])) ?></strong></span>
            <?php if ((float)$position['pending_paid'] > 0): ?>
                <span class="text-amber-700">Awaiting confirmation <strong class="ml-1 tabular-nums"><?= e($money($position['pending_paid'])) ?></strong></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="get" class="mb-4 grid gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:grid-cols-4">
        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            Status
            <select name="status" class="min-h-11 rounded-xl border border-slate-300 bg-white px-2 py-2 text-sm font-normal">
                <option value="">Any</option>
                <?php foreach (['pending' => 'Pending', 'confirmed' => 'Confirmed', 'done' => 'Confirmed', 'reversed' => 'Reversed'] as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            Rent period
            <input type="month" name="month" value="<?= e($month) ?>" class="min-h-11 rounded-xl border border-slate-300 px-2 py-2 text-sm font-normal">
        </label>
        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            Search
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Receipt number" class="min-h-11 rounded-xl border border-slate-300 px-2 py-2 text-sm font-normal">
        </label>
        <div class="flex items-end gap-2">
            <button class="min-h-11 flex-1 rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filter</button>
            <a href="payments.php" class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
        </div>
    </form>

    <?php if (!$payments): ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
            <h2 class="text-base font-semibold">No payments to show</h2>
            <p class="mx-auto mt-1 max-w-sm text-sm text-slate-600">
                When your landlord records a payment it appears here with a receipt you can open or print.
            </p>
        </div>
    <?php else: ?>
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <ul role="list" class="divide-y divide-slate-100">
                <?php foreach ($payments as $payment): ?>
                    <?php [$label, $tone] = $statusBadge((string)$payment['verification_status']); ?>
                    <li class="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold tabular-nums"><?= e($money($payment['amount'])) ?></p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                <?= e($payment['payment_month'] ?: '—') ?>
                                · paid <?= e($payment['payment_date'] ? date('d M Y', strtotime((string)$payment['payment_date'])) : '—') ?>
                                <?php if (!empty($payment['method'])): ?> · <?= e(ucfirst((string)$payment['method'])) ?><?php endif; ?>
                                <?php if (!empty($payment['receipt_no'])): ?> · <?= e((string)$payment['receipt_no']) ?><?php endif; ?>
                            </p>
                            <?php if (strtolower((string)$payment['verification_status']) === 'reversed' && !empty($payment['reversal_reason'])): ?>
                                <p class="mt-1 text-xs text-red-700">Reversed: <?= e((string)$payment['reversal_reason']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ring-1 ring-inset <?= $tone ?>"><?= e($label) ?></span>
                            <a href="receipt.php?payment=<?= (int)$payment['id'] ?>" class="inline-flex min-h-9 items-center rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200">
                                Receipt
                            </a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <?php if ($pages > 1): ?>
            <div class="mt-3 flex items-center justify-between text-sm">
                <span class="text-xs text-slate-500">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> payments</span>
                <div class="flex gap-2">
                    <?php if ($page > 1): ?>
                        <a href="<?= e($link(['page' => $page - 1])) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $pages): ?>
                        <a href="<?= e($link(['page' => $page + 1])) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
