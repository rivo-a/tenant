<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
requireCaretaker();

$pdo = getDB();
$adminId = current_admin_id();
$timezone = new DateTimeZone('Africa/Kampala');
$today = new DateTimeImmutable('today', $timezone);
$currentMonth = $today->format('Y-m');

$readScalar = static function (array $source, string $key, string $default = ''): string {
    $value = $source[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
};
$search = $readScalar($_GET, 'search');
$status = strtolower($readScalar($_GET, 'status', 'active'));
if (!in_array($status, ['active', 'exited', 'all'], true)) {
    $status = 'active';
}

function money($value): string
{
    return number_format((float)$value, 0, '.', ',');
}

function paymentStatus(array $tenant, DateTimeImmutable $today): string
{
    $rent = (float)($tenant['effective_rent'] ?? 0);
    $paid = (float)($tenant['paid_this_month'] ?? 0);

    if ($rent <= 0) {
        return 'Rent missing';
    }

    if ($paid >= $rent) {
        return 'Paid';
    }

    $dueDateValue = trim((string)($tenant['rent_due_date'] ?? ''));
    $dueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDateValue, $today->getTimezone());
    $dateErrors = DateTimeImmutable::getLastErrors();
    $hasDateErrors = $dateErrors !== false && (
        $dateErrors['warning_count'] > 0 ||
        $dateErrors['error_count'] > 0
    );

    return $dueDate !== false && !$hasDateErrors && $dueDate < $today
        ? 'Overdue'
        : 'Due';
}

function chip(string $label, string $tone): string
{
    $map = [
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'red' => 'bg-red-50 text-red-700 ring-red-200',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-200',
    ];
    $classes = $map[$tone] ?? $map['slate'];

    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ' . $classes . '">' . e($label) . '</span>';
}

function lastPaidLabel(?string $date, DateTimeZone $timezone): string
{
    if (!$date) {
        return '—';
    }

    try {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))
            ->setTimezone($timezone)
            ->format('d M Y');
    } catch (Throwable $ignored) {
        return '—';
    }
}

$rentExpression = "COALESCE(
    NULLIF(t.monthly_rent, 0),
    NULLIF(r.monthly_rent, 0),
    rt.default_monthly_rent,
    0
)";

$sql = "
    SELECT
        t.id AS tenant_id,
        t.full_name,
        t.phone,
        LOWER(COALESCE(t.status, 'active')) AS tenant_status,
        t.exit_date,
        t.move_in_date,
        t.rent_due_date,
        t.created_at,
        r.room_number,
        rt.name AS room_type,
        {$rentExpression} AS effective_rent,
        MAX(p.payment_date) AS last_payment_date,
        COALESCE(SUM(
            CASE
                WHEN p.payment_month = :payment_month THEN p.amount
                ELSE 0
            END
        ), 0) AS paid_this_month,
        MAX(
            0,
            {$rentExpression} - COALESCE(SUM(
                CASE
                    WHEN p.payment_month = :balance_month THEN p.amount
                    ELSE 0
                END
            ), 0)
        ) AS outstanding_balance
    FROM tenants t
    LEFT JOIN rooms r ON r.id = t.room_id
    LEFT JOIN room_types rt ON rt.id = r.room_type_id
    LEFT JOIN payments p
        ON p.tenant_id = t.id
       AND p.admin_id = :payment_admin_id
    WHERE t.admin_id = :admin_id
";

$params = [
    ':payment_month' => $currentMonth,
    ':balance_month' => $currentMonth,
    ':payment_admin_id' => $adminId,
    ':admin_id' => $adminId,
];

if ($status !== 'all') {
    $sql .= ' AND LOWER(COALESCE(t.status, \'active\')) = :tenant_status';
    $params[':tenant_status'] = $status;
}

if ($search !== '') {
    $sql .= "
        AND (
            t.full_name LIKE :search
            OR t.phone LIKE :search
            OR r.room_number LIKE :search
        )
    ";
    $params[':search'] = '%' . $search . '%';
}

$sql .= "
    GROUP BY
        t.id,
        t.full_name,
        t.phone,
        t.status,
        t.exit_date,
        t.move_in_date,
        t.rent_due_date,
        t.created_at,
        r.room_number,
        rt.name,
        t.monthly_rent,
        r.monthly_rent,
        rt.default_monthly_rent
    ORDER BY t.created_at DESC, t.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$success = is_scalar($_SESSION['success'] ?? null) ? trim((string)$_SESSION['success']) : '';
unset($_SESSION['success']);
$active = 'tenants';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tenants</title>
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <?php require __DIR__ . '/partials/navbar.php'; ?>

  <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <header class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="font-alt text-2xl font-bold tracking-tight">Tenants</h1>
        <p class="mt-1 text-sm text-slate-600">Search tenants, check payment status, and manage tenant actions.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <a href="tenant_control.php" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">Onboard tenant</a>
        <a href="payments.php" class="inline-flex min-h-11 items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">Receive payment</a>
        <a href="tenant_payments.php" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">Tenant payments</a>
      </div>
    </header>

    <?php if ($success): ?>
      <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status"><?= e($success) ?></div>
    <?php endif; ?>

    <form method="get" class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <div>
          <label for="search" class="mb-1 block text-xs font-semibold text-slate-600">Search</label>
          <input id="search" type="search" name="search" value="<?= e($search) ?>" placeholder="Name, phone, room..." class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
        </div>
        <div>
          <label for="status" class="mb-1 block text-xs font-semibold text-slate-600">Status</label>
          <select id="status" name="status" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="exited" <?= $status === 'exited' ? 'selected' : '' ?>>Exited</option>
            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
          </select>
        </div>
        <div class="flex items-end gap-2">
          <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">Apply filters</button>
          <?php if ($search !== '' || $status !== 'active'): ?>
            <a href="tenants.php" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">Reset</a>
          <?php endif; ?>
        </div>
      </div>
    </form>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="tenant-list-title">
      <div class="border-b border-slate-200 px-4 py-4">
        <h2 id="tenant-list-title" class="font-alt text-lg font-bold">Tenant list</h2>
        <p class="mt-1 text-sm text-slate-600">Showing <?= (int)count($tenants) ?> tenant(s) for <?= e($currentMonth) ?>.</p>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-[960px] w-full text-sm">
          <caption class="sr-only">Tenant rent and management actions</caption>
          <thead class="bg-slate-50 text-slate-600">
            <tr>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Tenant</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Room</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Rent</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Status</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Payment</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Last paid</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if (!$tenants): ?>
              <tr><td colspan="7" class="px-4 py-8"><div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-center text-sm text-slate-700">No tenants found.</div></td></tr>
            <?php endif; ?>

            <?php foreach ($tenants as $tenant): ?>
              <?php
                $tenantId = (int)$tenant['tenant_id'];
                $payStatus = paymentStatus($tenant, $today);
                $tenantStatus = strtolower((string)($tenant['tenant_status'] ?? 'active'));
                $outstanding = max(0.0, (float)($tenant['outstanding_balance'] ?? 0));
                $tenantChip = $tenantStatus === 'active' ? chip('Active', 'blue') : chip('Exited', 'slate');
                $payChip = match ($payStatus) {
                    'Paid' => chip('Paid', 'green'),
                    'Due' => chip('Due', 'amber'),
                    'Rent missing' => chip('Rent missing', 'red'),
                    default => chip('Overdue', 'red'),
                };
              ?>
              <tr class="align-top hover:bg-slate-50">
                <td class="px-4 py-4"><div class="font-semibold text-slate-900"><?= e($tenant['full_name'] ?? '') ?></div><div class="mt-1 text-xs text-slate-500"><?= e($tenant['phone'] ?? 'No phone') ?></div></td>
                <td class="px-4 py-4"><div class="font-semibold text-slate-900"><?= e($tenant['room_number'] ?? '—') ?></div><div class="mt-1 text-xs text-slate-500"><?= e($tenant['room_type'] ?? 'No room type') ?></div></td>
                <td class="whitespace-nowrap px-4 py-4 font-alt font-semibold tabular-nums">UGX <?= e(money($tenant['effective_rent'] ?? 0)) ?></td>
                <td class="px-4 py-4"><?= $tenantChip ?></td>
                <td class="px-4 py-4"><div><?= $payChip ?></div><?php if ($outstanding > 0): ?><div class="mt-1 text-xs text-red-700">Balance: UGX <?= e(money($outstanding)) ?></div><?php endif; ?></td>
                <td class="whitespace-nowrap px-4 py-4 text-slate-700"><?= e(lastPaidLabel($tenant['last_payment_date'] ?? null, $timezone)) ?></td>
                <td class="px-4 py-4">
                  <div class="flex flex-wrap gap-2">
                    <?php if ($tenantStatus === 'active'): ?>
                      <a class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900" href="remind.php?tenant_id=<?= $tenantId ?>">Remind</a>
                    <?php endif; ?>
                    <a class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900" href="tenant_edit.php?id=<?= $tenantId ?>">Edit</a>
                    <?php if ($tenantStatus === 'active'): ?>
                      <a class="inline-flex min-h-11 items-center rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700" href="tenant_exit.php?id=<?= $tenantId ?>" onclick="return confirm('Exit this tenant?')">Exit</a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>
