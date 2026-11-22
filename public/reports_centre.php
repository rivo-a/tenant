<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/charts.php';
require_once __DIR__ . '/../services/ReportService.php';

require_login();
require_capability('reports.view');

$pdo = getDB();
$businessId = current_business_id();
$reports = new ReportService($pdo);

if ($businessId <= 0) {
    http_response_code(403);
    exit('Access denied.');
}

$views = ['overview', 'arrears', 'properties', 'ledger', 'staff'];
$view = (string)($_GET['view'] ?? 'overview');
if (!in_array($view, $views, true)) {
    $view = 'overview';
}

$period = $reports->resolvePeriod(
    isset($_GET['from']) ? (string)$_GET['from'] : null,
    isset($_GET['to']) ? (string)$_GET['to'] : null
);

$propertyId = max(0, (int)($_GET['property'] ?? 0));
$properties = $reports->properties($businessId);

$ledgerFilters = [
    'from' => $period['from'],
    'to' => $period['to'],
    'property' => $propertyId,
    'status' => (string)($_GET['status'] ?? ''),
    'method' => (string)($_GET['method'] ?? ''),
    'search' => trim((string)($_GET['search'] ?? '')),
];

$ledgerPage = max(1, (int)($_GET['page'] ?? 1));

/* Everything the current view needs, computed once. */
$overview = $view === 'overview' ? $reports->overview($businessId, $period, $propertyId) : [];
$series = $view === 'overview' ? $reports->monthlySeries($businessId, 6, $propertyId) : [];
$arrears = in_array($view, ['overview', 'arrears'], true) ? $reports->arrears($businessId, $propertyId) : [];
$perf = $view === 'properties' ? $reports->propertyPerformance($businessId, $period) : [];
$ledger = $view === 'ledger' ? $reports->ledger($businessId, $ledgerFilters, $ledgerPage, 25) : [];
$staffActivity = $view === 'staff' ? $reports->staffActivity($businessId, $period) : [];

$currentQuery = array_filter([
    'view' => $view,
    'from' => $period['from'],
    'to' => $period['to'],
    'property' => $propertyId ?: '',
    'status' => $ledgerFilters['status'],
    'method' => $ledgerFilters['method'],
    'search' => $ledgerFilters['search'],
]);

$money = static fn($value): string => 'UGX ' . number_format((float)$value, 0);
$percent = static fn(float $rate): string => number_format($rate * 100, 1) . '%';

$linkTo = static function (array $overrides) use ($currentQuery): string {
    return 'reports_centre.php?' . http_build_query(array_filter(array_merge($currentQuery, $overrides), static fn($v) => $v !== '' && $v !== null));
};

$exportType = match ($view) {
    'arrears' => 'arrears',
    'properties' => 'properties',
    'ledger' => 'ledger',
    default => 'summary',
};

$activeNav = 'reports';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reports · Urbahan</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<?php
$navPath = __DIR__ . '/partials/navbar.php';
if (is_file($navPath)) {
    require $navPath;
}
?>
<main class="mx-auto max-w-6xl px-4 py-6 sm:px-6">

    <header class="mb-4">
        <h1 class="text-2xl font-bold tracking-tight">Reports</h1>
        <p class="mt-1 text-sm text-slate-600">
            Collected money counts confirmed payments only. Pending and reversed amounts are shown separately and never added to income.
        </p>
    </header>

    <form method="get" class="mb-5 grid gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
        <input type="hidden" name="view" value="<?= e($view) ?>">

        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            From
            <input type="date" name="from" value="<?= e($period['from']) ?>" class="min-h-11 rounded-xl border border-slate-300 px-2 py-2 text-sm font-normal">
        </label>

        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            To
            <input type="date" name="to" value="<?= e($period['to']) ?>" class="min-h-11 rounded-xl border border-slate-300 px-2 py-2 text-sm font-normal">
        </label>

        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            Property
            <select name="property" class="min-h-11 rounded-xl border border-slate-300 bg-white px-2 py-2 text-sm font-normal">
                <option value="">All properties</option>
                <?php foreach ($properties as $property): ?>
                    <option value="<?= (int)$property['id'] ?>" <?= $propertyId === $property['id'] ? 'selected' : '' ?>><?= e($property['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="flex items-end gap-2 sm:col-span-2">
            <button class="min-h-11 flex-1 rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Apply</button>
            <a href="<?= e($linkTo(['from' => date('Y-m-01'), 'to' => date('Y-m-d'), 'page' => ''])) ?>" class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">This month</a>
            <a href="<?= e($linkTo(['from' => date('Y-m-01', strtotime('first day of last month')), 'to' => date('Y-m-t', strtotime('first day of last month')), 'page' => ''])) ?>" class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Last month</a>
        </div>
    </form>

    <nav class="mb-5 flex flex-wrap gap-2" aria-label="Report sections">
        <?php foreach (['overview' => 'Overview', 'arrears' => 'Arrears', 'properties' => 'Properties', 'ledger' => 'Payment ledger', 'staff' => 'Staff activity'] as $key => $label): ?>
            <a href="<?= e($linkTo(['view' => $key, 'page' => ''])) ?>"
               class="inline-flex min-h-11 items-center rounded-xl border px-3 py-2 text-sm font-semibold transition <?= $view === $key ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' ?>"
               <?= $view === $key ? 'aria-current="page"' : '' ?>>
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>

        <a href="report_export.php?<?= e(http_build_query(array_merge($currentQuery, ['type' => $exportType]))) ?>"
           class="ml-auto inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            Export CSV
        </a>
    </nav>

    <p class="mb-4 text-xs text-slate-500"><?= e($period['label']) ?> · <?= e($period['days']) ?> days<?= $propertyId > 0 ? ' · one property' : '' ?></p>

<?php if ($view === 'overview'): ?>

    <section class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Headline figures">
        <?php
        $cards = [
            ['Expected for period', $money($overview['expected']), 'rent billed across active tenancies'],
            ['Confirmed collected', $money($overview['collected']), 'verified payments in the period'],
            ['Outstanding now', $money($overview['outstanding']), $overview['overdue_tenants'] . ' tenants past due'],
            ['Collection rate', $percent((float)$overview['collection_rate']), 'collected against expected'],
        ];
        foreach ($cards as [$label, $value, $hint]): ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500"><?= e($label) ?></p>
                <p class="mt-1 text-2xl font-bold tabular-nums"><?= e($value) ?></p>
                <p class="mt-1 text-xs text-slate-500"><?= e($hint) ?></p>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="mb-5 grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Overdue</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-red-700"><?= e($money($overview['overdue'])) ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pending verification</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-amber-700"><?= e($money($overview['pending'])) ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Occupancy</p>
            <p class="mt-1 text-2xl font-bold tabular-nums"><?= e($percent((float)$overview['occupancy_rate'])) ?></p>
            <p class="mt-1 text-xs text-slate-500"><?= (int)$overview['rooms_occupied'] ?> of <?= (int)$overview['rooms_total'] ?> rooms let</p>
        </div>
    </section>

    <section class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Expected against collected</h2>
        <?= chart_grouped_bars(
            array_map(static fn(array $row): array => [
                'label' => $row['label'],
                'values' => [$row['expected'], $row['collected']],
            ], $series),
            ['Expected', 'Collected'],
            ['#94a3b8', '#0f172a'],
            'Expected and collected rent per month'
        ) ?>
    </section>

    <section class="mb-5 grid gap-3 lg:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Collected trend</h2>
            <?= chart_line(
                array_map(static fn(array $row): array => ['label' => $row['label'], 'value' => $row['collected']], $series),
                '#0f766e',
                'Confirmed collections per month'
            ) ?>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Rooms let</h2>
            <?= chart_stacked_bar([
                ['label' => 'Occupied', 'value' => (float)$overview['rooms_occupied'], 'color' => '#0f172a'],
                ['label' => 'Vacant', 'value' => (float)$overview['rooms_vacant'], 'color' => '#cbd5e1'],
            ], 'Occupied against vacant rooms') ?>
            <p class="mt-3 text-xs text-slate-500"><?= (int)$overview['rooms_vacant'] ?> rooms are not currently let.</p>
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Overdue ageing</h2>
            <a href="<?= e($linkTo(['view' => 'arrears'])) ?>" class="text-xs font-semibold text-slate-600 hover:text-slate-900">Open arrears</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2 font-semibold">Overdue by</th>
                        <th class="px-4 py-2 font-semibold text-right">Tenants</th>
                        <th class="px-4 py-2 font-semibold text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($arrears['buckets'] as $bucket): ?>
                        <tr>
                            <td class="px-4 py-2"><?= e($bucket['label']) ?></td>
                            <td class="px-4 py-2 text-right tabular-nums"><?= (int)$bucket['tenants'] ?></td>
                            <td class="px-4 py-2 text-right font-semibold tabular-nums"><?= e($money($bucket['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($view === 'arrears'): ?>

    <section class="mb-5 grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total outstanding</p>
            <p class="mt-1 text-2xl font-bold tabular-nums"><?= e($money($arrears['totals']['outstanding'])) ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tenants owing</p>
            <p class="mt-1 text-2xl font-bold tabular-nums"><?= (int)$arrears['totals']['tenants'] ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Oldest debt</p>
            <p class="mt-1 text-2xl font-bold tabular-nums"><?= (int)$arrears['totals']['oldest_days'] ?> days</p>
        </div>
    </section>

    <section class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Outstanding by overdue period</h2>
        <?= chart_grouped_bars(
            array_map(static fn(array $b): array => ['label' => $b['label'], 'values' => [$b['amount']]], $arrears['buckets']),
            ['Outstanding'],
            ['#b91c1c'],
            'Outstanding rent by days overdue'
        ) ?>
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Tenants in arrears</h2>
        </div>
        <?php if (!$arrears['rows']): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-600">No tenant owes rent right now.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Tenant</th>
                            <th class="px-4 py-2 font-semibold">Property / room</th>
                            <th class="px-4 py-2 font-semibold">Owed since</th>
                            <th class="px-4 py-2 font-semibold text-right">Expected</th>
                            <th class="px-4 py-2 font-semibold text-right">Paid</th>
                            <th class="px-4 py-2 font-semibold text-right">Balance</th>
                            <th class="px-4 py-2 font-semibold text-right">Days</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($arrears['rows'] as $row): ?>
                            <tr>
                                <td class="px-4 py-2">
                                    <span class="font-medium"><?= e($row['full_name']) ?></span>
                                    <?php if ($row['phone'] !== ''): ?>
                                        <span class="block text-xs text-slate-500"><?= e($row['phone']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2 text-slate-600">
                                    <?= e($row['property_name'] !== '' ? $row['property_name'] : 'Unassigned') ?>
                                    <span class="block text-xs text-slate-500"><?= e($row['room_number'] !== '' ? 'Room ' . $row['room_number'] : 'No room') ?></span>
                                </td>
                                <td class="px-4 py-2 text-slate-600"><?= e($row['owed_since_label']) ?></td>
                                <td class="px-4 py-2 text-right tabular-nums"><?= e($money($row['expected'])) ?></td>
                                <td class="px-4 py-2 text-right tabular-nums"><?= e($money($row['verified_paid'])) ?></td>
                                <td class="px-4 py-2 text-right font-semibold tabular-nums"><?= e($money($row['balance'])) ?></td>
                                <td class="px-4 py-2 text-right tabular-nums <?= (int)$row['days_overdue'] > 30 ? 'font-semibold text-red-700' : '' ?>"><?= (int)$row['days_overdue'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($view === 'properties'): ?>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Property performance</h2>
            <a href="properties.php" class="text-xs font-semibold text-slate-600 hover:text-slate-900">Manage properties</a>
        </div>
        <?php if (!$perf): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-600">
                No properties yet. Create one and place rooms in it to compare performance.
            </p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Property</th>
                            <th class="px-4 py-2 font-semibold text-right">Rooms</th>
                            <th class="px-4 py-2 font-semibold text-right">Let</th>
                            <th class="px-4 py-2 font-semibold text-right">Occupancy</th>
                            <th class="px-4 py-2 font-semibold text-right">Expected</th>
                            <th class="px-4 py-2 font-semibold text-right">Collected</th>
                            <th class="px-4 py-2 font-semibold text-right">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($perf as $row): ?>
                            <tr>
                                <td class="px-4 py-2">
                                    <span class="font-medium"><?= e($row['name']) ?></span>
                                    <span class="block text-xs text-slate-500"><?= e($row['address']) ?></span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums"><?= (int)$row['rooms_total'] ?></td>
                                <td class="px-4 py-2 text-right tabular-nums"><?= (int)$row['rooms_occupied'] ?></td>
                                <td class="px-4 py-2 text-right tabular-nums"><?= e($percent((float)$row['occupancy_rate'])) ?></td>
                                <td class="px-4 py-2 text-right tabular-nums"><?= e($money($row['expected'])) ?></td>
                                <td class="px-4 py-2 text-right tabular-nums"><?= e($money($row['collected'])) ?></td>
                                <td class="px-4 py-2 text-right font-semibold tabular-nums"><?= e($money($row['outstanding'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($view === 'ledger'): ?>

    <form method="get" class="mb-4 grid gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:grid-cols-4">
        <input type="hidden" name="view" value="ledger">
        <input type="hidden" name="from" value="<?= e($period['from']) ?>">
        <input type="hidden" name="to" value="<?= e($period['to']) ?>">
        <input type="hidden" name="property" value="<?= $propertyId ?: '' ?>">

        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            Status
            <select name="status" class="min-h-11 rounded-xl border border-slate-300 bg-white px-2 py-2 text-sm font-normal">
                <option value="">Any</option>
                <?php foreach (['pending' => 'Pending', 'confirmed' => 'Confirmed', 'done' => 'Done', 'reversed' => 'Reversed'] as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $ledgerFilters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            Method
            <select name="method" class="min-h-11 rounded-xl border border-slate-300 bg-white px-2 py-2 text-sm font-normal">
                <option value="">Any</option>
                <?php foreach (['cash' => 'Cash', 'mobile' => 'Mobile money', 'bank' => 'Bank'] as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $ledgerFilters['method'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            Search
            <input type="search" name="search" value="<?= e($ledgerFilters['search']) ?>" placeholder="Tenant, room or receipt" class="min-h-11 rounded-xl border border-slate-300 px-2 py-2 text-sm font-normal">
        </label>
        <div class="flex items-end">
            <button class="min-h-11 w-full rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filter ledger</button>
        </div>
    </form>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Payment ledger</h2>
            <span class="text-xs text-slate-500"><?= (int)$ledger['total'] ?> payments</span>
        </div>

        <?php if (!$ledger['rows']): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-600">No payments match these filters.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Receipt</th>
                            <th class="px-4 py-2 font-semibold">Tenant</th>
                            <th class="px-4 py-2 font-semibold">Property / room</th>
                            <th class="px-4 py-2 font-semibold">Period</th>
                            <th class="px-4 py-2 font-semibold">Paid on</th>
                            <th class="px-4 py-2 font-semibold">Method</th>
                            <th class="px-4 py-2 font-semibold">Status</th>
                            <th class="px-4 py-2 font-semibold text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($ledger['rows'] as $row): ?>
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs text-slate-600"><?= e($row['receipt_no'] ?? '—') ?></td>
                                <td class="px-4 py-2 font-medium"><?= e($row['tenant_name']) ?></td>
                                <td class="px-4 py-2 text-slate-600">
                                    <?= e($row['property_name'] ?? 'Unassigned') ?>
                                    <span class="block text-xs text-slate-500"><?= e($row['room_number'] ?? '—') ?></span>
                                </td>
                                <td class="px-4 py-2 text-slate-600"><?= e($row['payment_month'] ?: '—') ?></td>
                                <td class="px-4 py-2 text-slate-600"><?= e($row['payment_date'] ? date('d M Y', strtotime((string)$row['payment_date'])) : '—') ?></td>
                                <td class="px-4 py-2 text-slate-600"><?= e(ucfirst((string)($row['method'] ?? '—'))) ?></td>
                                <td class="px-4 py-2">
                                    <?php
                                    $status = strtolower((string)$row['verification_status']);
                                    $tone = match ($status) {
                                        'confirmed', 'done' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
                                        'pending' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
                                        'reversed' => 'bg-red-50 text-red-700 ring-red-600/20',
                                        default => 'bg-slate-50 text-slate-600 ring-slate-500/10',
                                    };
                                    ?>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset <?= $tone ?>"><?= e(ucfirst($status)) ?></span>
                                </td>
                                <td class="px-4 py-2 text-right font-semibold tabular-nums"><?= e($money($row['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($ledger['pages'] > 1): ?>
                <div class="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm">
                    <span class="text-xs text-slate-500">Page <?= (int)$ledger['page'] ?> of <?= (int)$ledger['pages'] ?></span>
                    <div class="flex gap-2">
                        <?php if ($ledger['page'] > 1): ?>
                            <a href="<?= e($linkTo(['page' => $ledger['page'] - 1])) ?>" class="rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Previous</a>
                        <?php endif; ?>
                        <?php if ($ledger['page'] < $ledger['pages']): ?>
                            <a href="<?= e($linkTo(['page' => $ledger['page'] + 1])) ?>" class="rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

<?php elseif ($view === 'staff'): ?>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Staff activity in this period</h2>
            <p class="mt-1 text-xs text-slate-500">Built from the audit log only. Actions that were never logged are not shown.</p>
        </div>
        <?php if (!$staffActivity['rows']): ?>
            <p class="px-4 py-8 text-center text-sm text-slate-600">No staff activity logged in this period.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Staff member</th>
                            <th class="px-4 py-2 font-semibold">Actions</th>
                            <th class="px-4 py-2 font-semibold text-right">Events</th>
                            <th class="px-4 py-2 font-semibold">Last seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($staffActivity['rows'] as $row): ?>
                            <tr>
                                <td class="px-4 py-2 font-medium"><?= e($row['actor_name'] ?? 'Unknown') ?></td>
                                <td class="px-4 py-2 text-xs text-slate-600"><?= e(str_replace(',', ' · ', (string)($row['actions'] ?? ''))) ?></td>
                                <td class="px-4 py-2 text-right tabular-nums"><?= (int)$row['events'] ?></td>
                                <td class="px-4 py-2 text-slate-600"><?= e($row['last_seen'] ? date('d M Y, H:i', strtotime((string)$row['last_seen'])) : '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php endif; ?>

</main>
</body>
</html>
