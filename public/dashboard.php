<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// If your bootstrap already has e(), remove this function to avoid redeclare.
if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if ($admin_id <= 0) {
    http_response_code(403);
    exit('Access denied.');
}

/*
|--------------------------------------------------------------------------|
| Room Statistics
|--------------------------------------------------------------------------|
*/
$roomStatsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_rooms,
        SUM(CASE WHEN status = 'free' THEN 1 ELSE 0 END) AS free_rooms,
        SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) AS occupied_rooms
    FROM rooms
    WHERE admin_id = ?
");
$roomStatsStmt->execute([$admin_id]);
$roomStats = $roomStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_rooms' => 0,
    'free_rooms' => 0,
    'occupied_rooms' => 0,
];

/*
|--------------------------------------------------------------------------|
| Upcoming Rent (Next 7 Days)
|--------------------------------------------------------------------------|
*/
$upcomingStmt = $pdo->prepare("
    SELECT 
        t.full_name,
        r.room_number,
        t.rent_due_date
    FROM tenants t
    INNER JOIN rooms r ON t.room_id = r.id
    WHERE r.admin_id = ?
      AND t.exit_date IS NULL
      AND t.rent_due_date BETWEEN date('now') AND date('now', '+7 day')
    ORDER BY t.rent_due_date ASC
");
$upcomingStmt->execute([$admin_id]);
$upcomingRents = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/*
|--------------------------------------------------------------------------|
| Overdue Rent with Arrears
|--------------------------------------------------------------------------|
*/
$overdueStmt = $pdo->prepare("
    SELECT 
        t.id AS tenant_id,
        t.full_name,
        r.room_number,
        t.rent_due_date,
        CASE 
            WHEN t.monthly_rent > 0 THEN t.monthly_rent
            ELSE rt.default_monthly_rent
        END AS monthly_rent,
        IFNULL(SUM(p.amount),0) AS paid_this_month,
        (CASE 
            WHEN t.monthly_rent > 0 THEN t.monthly_rent
            ELSE rt.default_monthly_rent
        END - IFNULL(SUM(p.amount),0)) AS arrears
    FROM tenants t
    INNER JOIN rooms r ON t.room_id = r.id
    INNER JOIN room_types rt ON r.room_type_id = rt.id
    LEFT JOIN payments p 
        ON t.id = p.tenant_id 
        AND strftime('%Y-%m', p.payment_date) = strftime('%Y-%m', 'now')
    WHERE r.admin_id = ?
      AND t.exit_date IS NULL
      AND t.rent_due_date < date('now')
    GROUP BY t.id, t.full_name, r.room_number, t.rent_due_date, t.monthly_rent, rt.default_monthly_rent
    ORDER BY t.rent_due_date ASC
");
$overdueStmt->execute([$admin_id]);
$overdueRents = $overdueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* Dashboard totals */
$totalOverdueTenants = count($overdueRents);
$totalArrears = 0.0;
foreach ($overdueRents as $o) {
    $totalArrears += (float)($o['arrears'] ?? 0);
}

function money($v): string {
    return number_format((float)$v, 0);
}

function chip(string $label, string $tone): string
{
    $map = [
        'slate'  => 'bg-slate-100 text-slate-700 ring-slate-200',
        'red'    => 'bg-red-50 text-red-700 ring-red-200',
        'green'  => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'amber'  => 'bg-amber-50 text-amber-700 ring-amber-200',
        'blue'   => 'bg-sky-50 text-sky-700 ring-sky-200',
    ];
    $cls = $map[$tone] ?? $map['slate'];
    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ' . $cls . '">' . e($label) . '</span>';
}

$active = 'dashboard'; // not in nav list but harmless
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Dashboard</title>
   <!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- ✅ NO CDN: your compiled Tailwind -->
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>

<body class="min-h-screen bg-slate-50 text-slate-900">

  <?php
  // Adjust this path if your navbar partial is elsewhere
  $navPath = __DIR__ . '/partials/navbar.php';
  if (is_file($navPath)) require $navPath;
  ?>

  <main class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">

    <!-- Header -->
    <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">
          Welcome, <?= e($_SESSION['admin_name'] ?? 'Admin') ?>
        </h1>
        <p class="mt-1 text-sm text-slate-600">
          Overview of rooms, rent due, and arrears.
        </p>
      </div>

      <div class="flex flex-wrap gap-2">
        <a href="payments.php"
           class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
          Receive Payment
        </a>
        <a href="tenants.php"
           class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
          Manage Tenants
        </a>
      </div>
    </div>

    <!-- KPI cards -->
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5 mb-6">
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold text-slate-500">Total Rooms</div>
        <div class="mt-2 text-2xl font-black"><?= (int)($roomStats['total_rooms'] ?? 0) ?></div>
      </div>

      <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
        <div class="text-xs font-semibold text-emerald-700">Free Rooms</div>
        <div class="mt-2 text-2xl font-black text-emerald-900"><?= (int)($roomStats['free_rooms'] ?? 0) ?></div>
      </div>

      <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4">
        <div class="text-xs font-semibold text-sky-700">Occupied Rooms</div>
        <div class="mt-2 text-2xl font-black text-sky-900"><?= (int)($roomStats['occupied_rooms'] ?? 0) ?></div>
      </div>

      <div class="rounded-2xl border border-red-200 bg-red-50 p-4">
        <div class="text-xs font-semibold text-red-700">Overdue Tenants</div>
        <div class="mt-2 text-2xl font-black text-red-900"><?= (int)$totalOverdueTenants ?></div>
      </div>

      <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
        <div class="text-xs font-semibold text-amber-800">Total Arrears</div>
        <div class="mt-2 text-2xl font-black text-amber-950"><?= e(money($totalArrears)) ?></div>
      </div>
    </div>

    <!-- Overdue Rent -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
      <div class="px-4 py-4 border-b border-slate-200 flex items-center justify-between gap-2">
        <div>
          <h2 class="text-lg font-bold">Overdue Rent</h2>
          <p class="text-sm text-slate-600">Tenants past due date with current month arrears.</p>
        </div>
        <a href="caretaker_overdue.php"
           class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
          View Caretaker
        </a>
      </div>

      <?php if ($overdueRents): ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="px-4 py-3 text-left font-semibold">Tenant</th>
                <th class="px-4 py-3 text-left font-semibold">Room</th>
                <th class="px-4 py-3 text-left font-semibold">Due Date</th>
                <th class="px-4 py-3 text-left font-semibold">Monthly Rent</th>
                <th class="px-4 py-3 text-left font-semibold">Paid</th>
                <th class="px-4 py-3 text-left font-semibold">Arrears</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($overdueRents as $o): ?>
                <?php
                  $arrears = (float)($o['arrears'] ?? 0);
                  $paid    = (float)($o['paid_this_month'] ?? 0);

                  if ($arrears <= 0.00001) {
                      $status = chip('Paid', 'green');
                  } elseif ($paid > 0) {
                      $status = chip('Partial', 'amber');
                  } else {
                      $status = chip('Overdue', 'red');
                  }
                ?>
                <tr class="hover:bg-slate-50">
                  <td class="px-4 py-3 font-semibold"><?= e($o['full_name'] ?? '') ?></td>
                  <td class="px-4 py-3 text-slate-700"><?= e($o['room_number'] ?? '-') ?></td>
                  <td class="px-4 py-3 text-red-700 font-semibold"><?= e($o['rent_due_date'] ?? '') ?></td>
                  <td class="px-4 py-3 text-slate-700"><?= e(money($o['monthly_rent'] ?? 0)) ?></td>
                  <td class="px-4 py-3 text-slate-700"><?= e(money($paid)) ?></td>
                  <td class="px-4 py-3 font-bold text-red-700 font-sans"><?= e(money($arrears)) ?></td>
                  <td class="px-4 py-3"><?= $status ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="px-4 py-6 text-sm text-slate-700">
          <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4">
            No overdue rent.
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Upcoming Rent -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-4 py-4 border-b border-slate-200">
        <h2 class="text-lg font-bold">Upcoming Rent (Next 7 Days)</h2>
        <p class="text-sm text-slate-600">Tenants with rent due soon.</p>
      </div>

      <?php if ($upcomingRents): ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="px-4 py-3 text-left font-semibold">Tenant</th>
                <th class="px-4 py-3 text-left font-semibold">Room</th>
                <th class="px-4 py-3 text-left font-semibold">Due Date</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($upcomingRents as $t): ?>
                <tr class="hover:bg-slate-50">
                  <td class="px-4 py-3 font-semibold"><?= e($t['full_name'] ?? '') ?></td>
                  <td class="px-4 py-3 text-slate-700"><?= e($t['room_number'] ?? '-') ?></td>
                  <td class="px-4 py-3"><?= chip((string)($t['rent_due_date'] ?? ''), 'blue') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="px-4 py-6 text-sm text-slate-700">
          <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4">
            No rent due in the next 7 days.
          </div>
        </div>
      <?php endif; ?>
    </div>

  </main>
</body>
</html>
