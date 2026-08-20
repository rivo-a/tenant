<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
requireRole(['caretaker', 'super_admin']);

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function money($v): string { return number_format((float)$v, 0); }

function current_admin_id(): int
{
    if (isset($_SESSION['admin']['id'])) return (int)$_SESSION['admin']['id'];
    return (int)($_SESSION['admin_id'] ?? 0);
}

function chip(string $label, string $tone): string
{
    $map = [
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'red'   => 'bg-red-50 text-red-700 ring-red-200',
        'blue'  => 'bg-sky-50 text-sky-700 ring-sky-200',
    ];
    $cls = $map[$tone] ?? $map['slate'];
    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset '.$cls.'">'.e($label).'</span>';
}

$pdo = getDB();
$admin_id = current_admin_id();
if ($admin_id <= 0) {
    http_response_code(403);
    exit('Access denied: admin session not found.');
}

/**
 * Overdue = rent_due_date < today AND tenant is active
 */
$month = date('Y-m');
$today = date('Y-m-d');

$sql = "
    SELECT
        t.id,
        t.full_name,
        t.phone,
        t.status,
        t.rent_due_date,

        r.room_number,
        rt.name AS room_type,

        COALESCE(
            NULLIF(t.monthly_rent, 0),
            NULLIF(r.monthly_rent, 0),
            rt.default_monthly_rent,
            0
        ) AS monthly_rent_effective,

        COALESCE(SUM(p.amount), 0) AS paid_this_month,

        MAX(
            0,
            COALESCE(
                NULLIF(t.monthly_rent, 0),
                NULLIF(r.monthly_rent, 0),
                rt.default_monthly_rent,
                0
            ) - COALESCE(SUM(p.amount), 0)
        ) AS outstanding_balance

    FROM tenants t
    LEFT JOIN rooms r ON r.id = t.room_id
    LEFT JOIN room_types rt ON rt.id = r.room_type_id
    LEFT JOIN payments p
        ON p.tenant_id = t.id
        AND strftime('%Y-%m', p.payment_date) = :month

    WHERE t.admin_id = :admin_id
      AND LOWER(t.status) = 'active'
      AND t.rent_due_date IS NOT NULL
      AND t.rent_due_date <> ''
      AND date(t.rent_due_date) < date(:today)

    GROUP BY
        t.id, t.full_name, t.phone, t.status, t.rent_due_date, t.monthly_rent,
        r.room_number, r.monthly_rent,
        rt.name, rt.default_monthly_rent

    ORDER BY date(t.rent_due_date) ASC, t.full_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':admin_id' => $admin_id,
    ':month'    => $month,
    ':today'    => $today,
]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* Nav */
$active = 'caretaker';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Caretaker • Overdue Tenants</title>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Tailwind (compiled, no CDN) -->
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>

<body class="min-h-screen bg-slate-50 text-slate-900">
  <?php
  $navPath = __DIR__ . '/partials/navbar.php';
  if (is_file($navPath)) require $navPath;
  ?>

  <main class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">

    <!-- Header -->
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold tracking-tight font-alt">Caretaker • Overdue Tenants</h1>
        <p class="mt-1 text-sm text-slate-600">
          Showing tenants whose due date is before <span class="font-semibold"><?= e($today) ?></span>.
          Month filter: <?= chip($month, 'blue') ?>
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <a href="payments.php"
           class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
          Payments
        </a>
        <a href="payments_history.php"
           class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
          History
        </a>
      </div>
    </div>

    <!-- Table card -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-4 py-4 border-b border-slate-200 flex items-center justify-between">
        <div>
          <h2 class="text-lg font-bold font-alt">Overdue List</h2>
          <p class="text-sm text-slate-600">
            Total: <span class="font-semibold"><?= (int)count($rows) ?></span>
          </p>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-600">
            <tr>
              <th class="px-4 py-3 text-left font-semibold">Tenant</th>
              <th class="px-4 py-3 text-left font-semibold">Phone</th>
              <th class="px-4 py-3 text-left font-semibold">Room</th>
              <th class="px-4 py-3 text-left font-semibold">Type</th>
              <th class="px-4 py-3 text-left font-semibold">Due date</th>
              <th class="px-4 py-3 text-left font-semibold">Rent</th>
              <th class="px-4 py-3 text-left font-semibold">Paid (<?= e($month) ?>)</th>
              <th class="px-4 py-3 text-left font-semibold">Outstanding</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-100">
            <?php if (!$rows): ?>
              <tr>
                <td colspan="8" class="px-4 py-6">
                  <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                    No overdue tenants found.
                  </div>
                </td>
              </tr>
            <?php endif; ?>

            <?php foreach ($rows as $t): ?>
              <?php
                $out = (float)($t['outstanding_balance'] ?? 0);
                $paid = (float)($t['paid_this_month'] ?? 0);
                $rent = (float)($t['monthly_rent_effective'] ?? 0);

                $due = (string)($t['rent_due_date'] ?? '');
                $dueChip = $due ? chip($due, 'red') : chip('—', 'slate');

                $outChip = ($out > 0)
                    ? '<span class="font-bold text-red-700">UGX '.e(money($out)).'</span>'
                    : '<span class="font-semibold text-emerald-700">UGX 0</span>';
              ?>
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-3">
                  <div class="font-semibold"><?= e($t['full_name'] ?? '') ?></div>
                  <div class="text-xs text-slate-500">ID: <?= (int)$t['id'] ?></div>
                </td>
                <td class="px-4 py-3 text-slate-700"><?= e($t['phone'] ?? '') ?></td>
                <td class="px-4 py-3 font-semibold"><?= e($t['room_number'] ?? '-') ?></td>
                <td class="px-4 py-3 text-slate-700"><?= e($t['room_type'] ?? '-') ?></td>
                <td class="px-4 py-3"><?= $dueChip ?></td>
                <td class="px-4 py-3 font-semibold font-alt">UGX <?= e(money($rent)) ?></td>
                <td class="px-4 py-3">UGX <?= e(money($paid)) ?></td>
                <td class="px-4 py-3"><?= $outChip ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>

        </table>
      </div>
    </div>

  </main>
</body>
</html>
