<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$pdo = getDB();
$admin_id = current_admin_id();
if ($admin_id <= 0) {
    http_response_code(403);
    exit('Access denied.');
}

$today = date('Y-m-d');
$monthKey = date('Y-m');
$dashboardError = '';
$roomStats = [
    'total_rooms' => 0,
    'free_rooms' => 0,
    'occupied_rooms' => 0,
];
$upcomingRents = [];
$overdueRents = [];

$rentExpression = "COALESCE(
    NULLIF(t.monthly_rent, 0),
    NULLIF(r.monthly_rent, 0),
    rt.default_monthly_rent,
    0
)";
$paidExpression = 'COALESCE(SUM(p.amount), 0)';
$collectibleExpression = "MAX(0, {$rentExpression} - {$paidExpression})";

try {
    $roomStatsStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_rooms,
            SUM(CASE WHEN status = 'free' THEN 1 ELSE 0 END) AS free_rooms,
            SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) AS occupied_rooms
        FROM rooms
        WHERE admin_id = :admin_id
    ");
    $roomStatsStmt->execute([':admin_id' => $admin_id]);
    $roomStats = $roomStatsStmt->fetch(PDO::FETCH_ASSOC) ?: $roomStats;

    $upcomingStmt = $pdo->prepare("
        SELECT
            t.full_name,
            r.room_number,
            t.rent_due_date
        FROM tenants t
        LEFT JOIN rooms r
            ON r.id = t.room_id
           AND r.admin_id = :room_admin_id
        WHERE t.admin_id = :admin_id
          AND LOWER(COALESCE(t.status, '')) = 'active'
          AND t.exit_date IS NULL
          AND date(t.rent_due_date) BETWEEN date(:today) AND date(:today, '+7 day')
        ORDER BY date(t.rent_due_date) ASC, t.full_name ASC
    ");
    $upcomingStmt->execute([
        ':room_admin_id' => $admin_id,
        ':admin_id' => $admin_id,
        ':today' => $today,
    ]);
    $upcomingRents = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $overdueStmt = $pdo->prepare("
        SELECT
            t.id AS tenant_id,
            t.full_name,
            r.room_number,
            rt.name AS room_type,
            t.rent_due_date,
            {$rentExpression} AS monthly_rent,
            {$paidExpression} AS paid_this_month,
            {$collectibleExpression} AS collectible_balance
        FROM tenants t
        LEFT JOIN rooms r
            ON r.id = t.room_id
           AND r.admin_id = :room_admin_id
        LEFT JOIN room_types rt
            ON rt.id = r.room_type_id
        LEFT JOIN payments p
            ON p.tenant_id = t.id
           AND p.admin_id = :payment_admin_id
           AND COALESCE(NULLIF(p.payment_month, ''), strftime('%Y-%m', p.payment_date)) = :month_key
        WHERE t.admin_id = :admin_id
          AND LOWER(COALESCE(t.status, '')) = 'active'
          AND t.exit_date IS NULL
          AND t.rent_due_date IS NOT NULL
          AND t.rent_due_date <> ''
          AND date(t.rent_due_date) < date(:today)
        GROUP BY
            t.id,
            t.full_name,
            t.rent_due_date,
            t.monthly_rent,
            r.room_number,
            r.monthly_rent,
            rt.name,
            rt.default_monthly_rent
        HAVING {$collectibleExpression} > 0
        ORDER BY collectible_balance DESC, date(t.rent_due_date) ASC, t.full_name ASC
    ");
    $overdueStmt->execute([
        ':room_admin_id' => $admin_id,
        ':payment_admin_id' => $admin_id,
        ':admin_id' => $admin_id,
        ':month_key' => $monthKey,
        ':today' => $today,
    ]);
    $overdueRents = $overdueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $exception) {
    // Keep database details out of the UI while providing a recoverable state.
    $dashboardError = 'Unable to load dashboard data.';
    $roomStats = [
        'total_rooms' => 0,
        'free_rooms' => 0,
        'occupied_rooms' => 0,
    ];
    $upcomingRents = [];
    $overdueRents = [];
}

function dashboardMoney(float $amount): string
{
    return 'UGX ' . number_format(max(0, $amount), 0);
}

function dashboardDate(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return '—';
    }

    try {
        return (new DateTimeImmutable($date))->format('d M Y');
    } catch (Throwable $exception) {
        return '—';
    }
}

function dashboardMonthLabel(string $monthKey): string
{
    try {
        return (new DateTimeImmutable($monthKey . '-01'))->format('F Y');
    } catch (Throwable $exception) {
        return 'Current month';
    }
}

function daysOverdue(?string $dueDate, string $today): int
{
    $dueDate = trim((string)$dueDate);
    if ($dueDate === '') {
        return 0;
    }

    try {
        $due = new DateTimeImmutable($dueDate);
        $current = new DateTimeImmutable($today);
        if ($due >= $current) {
            return 0;
        }
        return max(0, (int)$due->diff($current)->days);
    } catch (Throwable $exception) {
        return 0;
    }
}

function collectionChip(string $label, string $tone): string
{
    $map = [
        'red' => 'bg-red-50 text-red-700 ring-red-200',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',
    ];
    $classes = $map[$tone] ?? $map['slate'];

    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ' . $classes . '">' . e($label) . '</span>';
}

function dashboardCollectionData(array $tenant, string $today): array
{
    $paid = max(0, (float)($tenant['paid_this_month'] ?? 0));
    $rent = max(0, (float)($tenant['monthly_rent'] ?? 0));
    $balance = max(0, (float)($tenant['collectible_balance'] ?? 0));
    $isPartPaid = $paid > 0 && $balance > 0;
    $tenantId = (int)($tenant['tenant_id'] ?? 0);

    return [
        'tenantId' => $tenantId,
        'tenantName' => (string)($tenant['full_name'] ?? 'Tenant'),
        'roomNumber' => (string)($tenant['room_number'] ?? '—'),
        'roomType' => (string)($tenant['room_type'] ?? ''),
        'dueDate' => $tenant['rent_due_date'] ?? null,
        'daysOverdue' => daysOverdue($tenant['rent_due_date'] ?? null, $today),
        'paid' => $paid,
        'rent' => $rent,
        'balance' => $balance,
        'statusLabel' => $isPartPaid ? 'Part paid' : 'Payment due',
        'statusTone' => $isPartPaid ? 'amber' : 'red',
        'paymentUrl' => 'payments.php?' . http_build_query(['tenant_id' => $tenantId]),
    ];
}

$monthLabel = dashboardMonthLabel($monthKey);
$totalOverdueTenants = count($overdueRents);
$totalCollectibleArrears = 0.0;
foreach ($overdueRents as $overdueTenant) {
    $totalCollectibleArrears += max(0, (float)($overdueTenant['collectible_balance'] ?? 0));
}

$upcomingStartLabel = dashboardDate($today);
$upcomingEndLabel = dashboardDate((new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d'));
$active = 'dashboard';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Dashboard</title>
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">
  <?php require __DIR__ . '/partials/navbar.php'; ?>

  <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <header class="mb-5 flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm sm:px-6 sm:py-5 lg:flex-row lg:items-center lg:justify-between">
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold text-slate-500">
          <span>Operations overview</span>
          <span aria-hidden="true" class="text-slate-300">•</span>
          <span><?= e($monthLabel) ?></span>
        </div>
        <h1 class="mt-2 font-sans text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">
          Welcome back, <?= e($_SESSION['admin_name'] ?? 'Admin') ?>
        </h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-600">
          Follow up on overdue rent and keep today’s room operations moving.
        </p>
      </div>

      <div class="flex w-full flex-wrap gap-2 sm:w-auto sm:shrink-0">
        <a href="payments.php"
           class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 sm:flex-none">
          Collect payments
        </a>
        <a href="tenants.php"
           class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 sm:flex-none">
          Manage tenants
        </a>
      </div>
    </header>

    <?php if ($dashboardError): ?>
      <div class="mb-5 flex flex-col gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-4 text-sm text-red-900 sm:flex-row sm:items-center sm:justify-between" role="alert">
        <p><?= e($dashboardError) ?> Please try again.</p>
        <a href="dashboard.php"
           class="inline-flex min-h-11 items-center justify-center rounded-xl border border-red-300 bg-white px-3 py-2 font-semibold text-red-800 hover:bg-red-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700">
          Retry dashboard
        </a>
      </div>
    <?php endif; ?>

    <section class="mb-6 overflow-hidden rounded-2xl border border-slate-200 border-l-4 border-l-red-600 bg-white shadow-sm" aria-labelledby="collection-summary-heading">
      <div class="flex flex-col gap-1 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 id="collection-summary-heading" class="font-alt text-xl font-bold">Collect overdue rent</h2>
        </div>
        <p class="text-sm text-slate-600 sm:text-right">Active tenants · <?= e($monthLabel) ?></p>
      </div>

      <dl class="grid grid-cols-1 divide-y divide-slate-200 md:grid-cols-[minmax(0,1.35fr)_minmax(15rem,0.65fr)] md:divide-x md:divide-y-0">
        <div class="px-5 py-5 sm:px-6 sm:py-6">
          <dt class="text-sm font-semibold text-slate-600">Collectible arrears</dt>
          <dd class="mt-2 font-mono text-3xl font-bold tracking-tight text-slate-950 tabular-nums sm:text-4xl">
            <?= e(dashboardMoney($totalCollectibleArrears)) ?>
          </dd>
          <p class="mt-2 max-w-xl text-sm text-slate-600">
            Only positive balances for active tenants with a due date before today are included.
          </p>
        </div>

        <div class="flex items-center justify-between gap-4 px-5 py-5 sm:px-6 sm:py-6 md:block">
          <div>
            <dt class="text-sm font-semibold text-slate-600">Overdue tenants</dt>
            <dd class="mt-2 font-mono text-3xl font-bold tracking-tight text-red-700 tabular-nums">
              <?= (int)$totalOverdueTenants ?>
            </dd>
          </div>
          <a href="payments.php"
             class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 md:mt-5">
            Collect payments
          </a>
        </div>
      </dl>
    </section>

    <section class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="overdue-heading">
      <div class="flex flex-col gap-3 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
        <div>
          <h2 id="overdue-heading" class="font-alt text-lg font-bold">Overdue collection queue</h2>
          <p class="mt-1 text-sm text-slate-600">
            Active tenants with a collectible balance for <?= e($monthLabel) ?>, highest balance first.
          </p>
        </div>
        <a href="payments.php"
           class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
          Collect payments
        </a>
      </div>

      <?php if ($overdueRents): ?>
        <p class="hidden border-b border-slate-100 px-4 py-3 text-xs text-slate-500 sm:px-5 md:block">
          Compare all collection fields in the desktop table.
        </p>

        <div class="divide-y divide-slate-100 md:hidden">
          <?php foreach ($overdueRents as $tenant): ?>
            <?php $collection = dashboardCollectionData($tenant, $today); ?>
            <article class="p-4 sm:p-5">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <h3 class="truncate font-semibold text-slate-950"><?= e($collection['tenantName']) ?></h3>
                  <p class="mt-1 truncate text-xs text-slate-500">
                    Room <?= e($collection['roomNumber']) ?><?= $collection['roomType'] !== '' ? ' · ' . e($collection['roomType']) : '' ?>
                  </p>
                </div>
                <?= collectionChip($collection['statusLabel'], $collection['statusTone']) ?>
              </div>

              <div class="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3">
                <div>
                  <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Due date</p>
                  <p class="mt-1 font-semibold text-red-700"><?= e(dashboardDate($collection['dueDate'])) ?></p>
                  <p class="mt-1 text-xs text-red-600"><?= (int)$collection['daysOverdue'] ?> days overdue</p>
                </div>
                <div>
                  <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Collectible</p>
                  <p class="mt-1 font-mono font-bold tabular-nums text-red-700"><?= e(dashboardMoney($collection['balance'])) ?></p>
                  <p class="mt-1 text-xs text-slate-500">of <?= e(dashboardMoney($collection['rent'])) ?> rent</p>
                </div>
              </div>

              <div class="mt-3 flex items-center justify-between gap-3">
                <p class="text-xs text-slate-500">
                  Paid this month <span class="font-mono font-semibold tabular-nums text-slate-700"><?= e(dashboardMoney($collection['paid'])) ?></span>
                </p>
                <a href="<?= e($collection['paymentUrl']) ?>"
                   aria-label="Collect payment for <?= e($collection['tenantName']) ?>"
                   class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                  Collect payment
                </a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <div class="hidden overflow-x-auto md:block">
          <table class="min-w-[900px] w-full text-sm">
            <caption class="sr-only">Overdue collection queue for active tenants in <?= e($monthLabel) ?></caption>
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th scope="col" class="px-4 py-3 text-left font-semibold">Tenant</th>
                <th scope="col" class="px-4 py-3 text-left font-semibold">Due date</th>
                <th scope="col" class="px-4 py-3 text-left font-semibold">Monthly rent</th>
                <th scope="col" class="px-4 py-3 text-left font-semibold">Paid this month</th>
                <th scope="col" class="px-4 py-3 text-left font-semibold">Collectible balance</th>
                <th scope="col" class="px-4 py-3 text-left font-semibold">Status</th>
                <th scope="col" class="px-4 py-3 text-left font-semibold">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($overdueRents as $tenant): ?>
                <?php
                  $collection = dashboardCollectionData($tenant, $today);
                  $tenantId = $collection['tenantId'];
                  $paid = $collection['paid'];
                  $rent = $collection['rent'];
                  $balance = $collection['balance'];
                  $statusLabel = $collection['statusLabel'];
                  $statusTone = $collection['statusTone'];
                  $tenantName = $collection['tenantName'];
                  $paymentUrl = $collection['paymentUrl'];
                ?>
                <tr class="align-top">
                  <td class="px-4 py-4">
                    <div class="font-semibold text-slate-900"><?= e($tenantName) ?></div>
                    <div class="mt-1 text-xs text-slate-500">
                      Room <?= e($tenant['room_number'] ?? '—') ?><?= !empty($tenant['room_type']) ? ' · ' . e($tenant['room_type']) : '' ?>
                    </div>
                  </td>
                  <td class="px-4 py-4">
                    <div class="font-semibold text-red-700"><?= e(dashboardDate($tenant['rent_due_date'] ?? null)) ?></div>
                    <div class="mt-1 text-xs text-red-600">
                      <?= (int)daysOverdue($tenant['rent_due_date'] ?? null, $today) ?> days overdue
                    </div>
                  </td>
                  <td class="whitespace-nowrap px-4 py-4 font-mono tabular-nums text-slate-700">
                    <?= e(dashboardMoney($rent)) ?>
                  </td>
                  <td class="whitespace-nowrap px-4 py-4 font-mono tabular-nums text-slate-700">
                    <?= e(dashboardMoney($paid)) ?>
                  </td>
                  <td class="whitespace-nowrap px-4 py-4 font-mono font-bold tabular-nums text-red-700">
                    <?= e(dashboardMoney($balance)) ?>
                  </td>
                  <td class="px-4 py-4"><?= collectionChip($statusLabel, $statusTone) ?></td>
                  <td class="px-4 py-4">
                    <a href="<?= e($paymentUrl) ?>"
                       aria-label="Collect payment for <?= e($tenantName) ?>"
                       class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                      Collect payment
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="px-4 py-7 sm:px-5">
          <p class="text-sm text-slate-700">No overdue balances for active tenants.</p>
          <a href="payments.php"
             class="mt-3 inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
            Collect payments
          </a>
        </div>
      <?php endif; ?>
    </section>

    <section class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="inventory-heading">
      <div class="flex flex-col gap-2 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
        <div>
          <h2 id="inventory-heading" class="font-alt text-lg font-bold">Room inventory</h2>
          <p class="mt-1 text-sm text-slate-600">Capacity context for this property.</p>
        </div>
        <a href="rooms.php"
           class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
          Manage rooms
        </a>
      </div>

      <dl class="grid grid-cols-1 divide-y divide-slate-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
        <div class="px-5 py-4">
          <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total rooms</dt>
          <dd class="mt-1 font-mono text-2xl font-bold tabular-nums text-slate-900"><?= (int)($roomStats['total_rooms'] ?? 0) ?></dd>
        </div>
        <div class="px-5 py-4">
          <dt class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Free rooms</dt>
          <dd class="mt-1 font-mono text-2xl font-bold tabular-nums text-emerald-700"><?= (int)($roomStats['free_rooms'] ?? 0) ?></dd>
        </div>
        <div class="px-5 py-4">
          <dt class="text-xs font-semibold uppercase tracking-wide text-sky-700">Occupied rooms</dt>
          <dd class="mt-1 font-mono text-2xl font-bold tabular-nums text-sky-700"><?= (int)($roomStats['occupied_rooms'] ?? 0) ?></dd>
        </div>
      </dl>
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="upcoming-heading">
      <div class="flex flex-col gap-2 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
        <div>
          <h2 id="upcoming-heading" class="font-alt text-lg font-bold">Upcoming rent</h2>
          <p class="mt-1 text-sm text-slate-600">Due between <?= e($upcomingStartLabel) ?> and <?= e($upcomingEndLabel) ?>.</p>
        </div>
        <a href="payments.php"
           class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
          Collect payments
        </a>
      </div>

      <?php if ($upcomingRents): ?>
        <div class="overflow-x-auto">
          <table class="min-w-[520px] w-full text-sm">
            <caption class="sr-only">Upcoming rent due between <?= e($upcomingStartLabel) ?> and <?= e($upcomingEndLabel) ?></caption>
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th scope="col" class="px-4 py-3 text-left font-semibold">Tenant</th>
                <th scope="col" class="px-4 py-3 text-left font-semibold">Room</th>
                <th scope="col" class="px-4 py-3 text-left font-semibold">Due date</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($upcomingRents as $tenant): ?>
                <tr>
                  <td class="px-4 py-3 font-semibold text-slate-900"><?= e($tenant['full_name'] ?? '') ?></td>
                  <td class="px-4 py-3 text-slate-700"><?= e($tenant['room_number'] ?? '—') ?></td>
                  <td class="px-4 py-3 font-mono tabular-nums text-sky-700"><?= e(dashboardDate($tenant['rent_due_date'] ?? null)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="px-4 py-6 sm:px-5">
          <p class="text-sm text-slate-700">No rent is due between <?= e($upcomingStartLabel) ?> and <?= e($upcomingEndLabel) ?>.</p>
        </div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
