<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../core/bootstrap.php';

if (!isLoggedIn()) redirect('login.php');

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

/* ================= FILTERS ================= */
$year       = (int)($_GET['year'] ?? date('Y'));
$room_type  = (string)($_GET['room_type'] ?? '');

/* ================= ROOM TYPES (FIXED) ================= */
$roomTypesStmt = $pdo->query("
    SELECT id, name 
    FROM room_types 
    WHERE is_active = 1
    ORDER BY name
");
$roomTypes = $roomTypesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ================= KPI SUMMARY ================= */
$kpis = $pdo->prepare("
    SELECT
        (SELECT SUM(amount) FROM payments p
         JOIN tenants t ON t.id=p.tenant_id
         WHERE t.admin_id=? AND strftime('%Y', p.payment_date)=?) AS total_income,

        (SELECT COUNT(*) FROM payments p
         JOIN tenants t ON t.id=p.tenant_id
         WHERE t.admin_id=? AND strftime('%Y', p.payment_date)=?) AS payments_count,

        (SELECT COUNT(*) FROM tenants WHERE admin_id=? AND status='active') AS active_tenants,

        (SELECT COUNT(*) FROM rooms WHERE admin_id=? AND status='occupied') AS occupied_rooms
");
$kpis->execute([$admin_id, (string)$year, $admin_id, (string)$year, $admin_id, $admin_id]);
$kpi = $kpis->fetch(PDO::FETCH_ASSOC) ?: [
    'total_income'    => 0,
    'payments_count'  => 0,
    'active_tenants'  => 0,
    'occupied_rooms'  => 0,
];

/* ================= MONTHLY SUMMARY ================= */
$monthlyStmt = $pdo->prepare("
    SELECT strftime('%m', p.payment_date) AS month,
           SUM(p.amount) AS total
    FROM payments p
    JOIN tenants t ON t.id=p.tenant_id
    WHERE t.admin_id=? AND strftime('%Y', p.payment_date)=?
    GROUP BY month
    ORDER BY month
");
$monthlyStmt->execute([$admin_id, (string)$year]);
$monthly = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ================= PAYMENT HISTORY ================= */
$whereRoom = $room_type ? "AND rt.id = ?" : "";

$sql = "
    SELECT p.id, p.payment_date, p.amount, p.method,
           COALESCE(p.verification_status, 'done') AS verification_status,
           t.full_name, r.room_number, rt.name AS room_type
    FROM payments p
    JOIN tenants t ON t.id=p.tenant_id
    LEFT JOIN rooms r ON r.id=t.room_id
    LEFT JOIN room_types rt ON rt.id=r.room_type_id
    WHERE t.admin_id=?
      AND strftime('%Y', p.payment_date)=?
      $whereRoom
    ORDER BY p.payment_date DESC
";

$stmt = $pdo->prepare($sql);
$params = [$admin_id, (string)$year];
if ($room_type) $params[] = $room_type;
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ================= OUTSTANDING RENT ================= */
$outstanding = $pdo->prepare("
    SELECT t.full_name, r.room_number,
           COALESCE(r.monthly_rent, rt.default_monthly_rent) AS rent,
           COALESCE(SUM(p.amount),0) AS paid
    FROM tenants t
    JOIN rooms r ON r.id=t.room_id
    JOIN room_types rt ON rt.id=r.room_type_id
    LEFT JOIN payments p
        ON p.tenant_id=t.id
       AND strftime('%Y-%m', p.payment_date)=strftime('%Y-%m','now')
    WHERE t.admin_id=? AND t.status='active'
    GROUP BY t.id
    HAVING paid < rent
");
$outstanding->execute([$admin_id]);
$outstandingRows = $outstanding->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ================= EXPORT CSV ================= */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        logAudit($admin_id, 'REPORT_EXPORTED', "CSV payments report exported for {$year}.");
    } catch (Throwable $ignored) {}

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=payments_'.$year.'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Tenant','Room','Type','Amount','Method','Verification']);

    foreach ($payments as $p) {
        fputcsv($out, [
            humanDate($p['payment_date']),
            (string)($p['full_name'] ?? ''),
            (string)($p['room_number'] ?? ''),
            (string)($p['room_type'] ?? ''),
            (string)($p['amount'] ?? 0),
            (string)($p['method'] ?? ''),
            (string)($p['verification_status'] ?? 'done'),
        ]);
    }
    exit;
}

/* ================= EXPORT PDF ================= */
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    try {
        logAudit($admin_id, 'REPORT_EXPORTED', "PDF payments report exported for {$year}.");
    } catch (Throwable $ignored) {}

    require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';

    $pdf = new TCPDF();
    $pdf->AddPage();
    $html = "<h2>Payments Report {$year}</h2><table border='1' cellpadding='5'>";
    $html .= "<tr><th>Date</th><th>Tenant</th><th>Room</th><th>Amount</th></tr>";

    foreach ($payments as $p) {
        $html .= "<tr>
            <td>".humanDate($p['payment_date'])."</td>
            <td>".htmlspecialchars((string)$p['full_name'])."</td>
            <td>".htmlspecialchars((string)$p['room_number'])."</td>
            <td>UGX ".number_format((float)$p['amount'])."</td>
        </tr>";
    }
    $html .= "</table>";

    $pdf->writeHTML($html);
    $pdf->Output("reports_{$year}.pdf", 'D');
    exit;
}

/* UI helpers */
function money($v): string { return number_format((float)$v, 0); }

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

$active = 'reports';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Reports</title>

  <!-- Fonts (Inter + IBM Plex Sans) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- ✅ NO CDN: compiled Tailwind -->
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
        <h1 class="text-2xl font-bold tracking-tight font-alt">Reports</h1>
        <p class="mt-1 text-sm text-slate-600">
          Filter by year and room type. Export to CSV or PDF.
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <a class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
           href="?year=<?= (int)$year ?>&room_type=<?= e($room_type) ?>&export=csv">
          Export CSV
        </a>
        <a class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
           href="?year=<?= (int)$year ?>&room_type=<?= e($room_type) ?>&export=pdf">
          Export PDF
        </a>
      </div>
    </div>

    <!-- Filters -->
    <form method="get" class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <div>
          <label class="mb-1 block text-xs font-semibold text-slate-600">Year</label>
          <select name="year"
                  class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
            <?php for ($y = (int)date('Y'); $y >= 2020; $y--): ?>
              <option value="<?= $y ?>" <?= $y === (int)$year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-xs font-semibold text-slate-600">Room type</label>
          <select name="room_type"
                  class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
            <option value="">All Room Types</option>
            <?php foreach ($roomTypes as $rt): ?>
              <option value="<?= (int)$rt['id'] ?>" <?= ((string)$room_type === (string)$rt['id']) ? 'selected' : '' ?>>
                <?= e($rt['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="flex items-end gap-2">
          <button class="w-full inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Apply filters
          </button>
        </div>
      </div>
    </form>

    <!-- KPIs -->
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 mb-6">
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold text-slate-500">Income (<?= (int)$year ?>)</div>
        <div class="mt-2 text-2xl font-black font-alt">UGX <?= e(money($kpi['total_income'] ?? 0)) ?></div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold text-slate-500">Payments</div>
        <div class="mt-2 text-2xl font-black"><?= (int)($kpi['payments_count'] ?? 0) ?></div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold text-slate-500">Active Tenants</div>
        <div class="mt-2 text-2xl font-black"><?= (int)($kpi['active_tenants'] ?? 0) ?></div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs font-semibold text-slate-500">Occupied Rooms</div>
        <div class="mt-2 text-2xl font-black"><?= (int)($kpi['occupied_rooms'] ?? 0) ?></div>
      </div>
    </div>

    <!-- Monthly Summary -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
      <div class="px-4 py-4 border-b border-slate-200">
        <h2 class="text-lg font-bold font-alt">Monthly Summary</h2>
        <p class="text-sm text-slate-600">Total payments per month.</p>
      </div>

      <?php if ($monthly): ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="px-4 py-3 text-left font-semibold">Month</th>
                <th class="px-4 py-3 text-left font-semibold">Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($monthly as $m): ?>
                <?php
                  $monthNum = (int)($m['month'] ?? 1);
                  $monthName = date('F', mktime(0, 0, 0, max(1, min(12, $monthNum)), 1));
                ?>
                <tr class="hover:bg-slate-50">
                  <td class="px-4 py-3 font-semibold"><?= e($monthName) ?></td>
                  <td class="px-4 py-3">UGX <?= e(money($m['total'] ?? 0)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="px-4 py-6 text-sm text-slate-700">
          <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4">
            No monthly payment data for <?= (int)$year ?>.
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Outstanding Rent -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
      <div class="px-4 py-4 border-b border-slate-200">
        <h2 class="text-lg font-bold font-alt">Outstanding Rent (This Month)</h2>
        <p class="text-sm text-slate-600">Active tenants who have not fully paid this month.</p>
      </div>

      <?php if ($outstandingRows): ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="px-4 py-3 text-left font-semibold">Tenant</th>
                <th class="px-4 py-3 text-left font-semibold">Room</th>
                <th class="px-4 py-3 text-left font-semibold">Rent</th>
                <th class="px-4 py-3 text-left font-semibold">Paid</th>
                <th class="px-4 py-3 text-left font-semibold">Balance</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($outstandingRows as $o): ?>
                <?php
                  $rent = (float)($o['rent'] ?? 0);
                  $paid = (float)($o['paid'] ?? 0);
                  $bal  = $rent - $paid;

                  $status = ($paid > 0) ? chip('Partial', 'amber') : chip('Unpaid', 'red');
                ?>
                <tr class="hover:bg-slate-50">
                  <td class="px-4 py-3 font-semibold"><?= e($o['full_name'] ?? '') ?></td>
                  <td class="px-4 py-3 text-slate-700"><?= e($o['room_number'] ?? '-') ?></td>
                  <td class="px-4 py-3">UGX <?= e(money($rent)) ?></td>
                  <td class="px-4 py-3">UGX <?= e(money($paid)) ?></td>
                  <td class="px-4 py-3 font-bold text-red-700">UGX <?= e(money($bal)) ?></td>
                  <td class="px-4 py-3"><?= $status ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="px-4 py-6 text-sm text-slate-700">
          <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4">
            No outstanding rent for this month.
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Payment History -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-4 py-4 border-b border-slate-200">
        <h2 class="text-lg font-bold font-alt">Payment History</h2>
        <p class="text-sm text-slate-600">All payments for <?= (int)$year ?><?= $room_type ? ' (filtered by room type)' : '' ?>.</p>
      </div>

      <?php if ($payments): ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="px-4 py-3 text-left font-semibold">Date</th>
                <th class="px-4 py-3 text-left font-semibold">Tenant</th>
                <th class="px-4 py-3 text-left font-semibold">Room</th>
                <th class="px-4 py-3 text-left font-semibold">Type</th>
                <th class="px-4 py-3 text-left font-semibold">Amount</th>
                <th class="px-4 py-3 text-left font-semibold">Method</th>
                <th class="px-4 py-3 text-left font-semibold">Verification</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php foreach ($payments as $p): ?>
                <tr class="hover:bg-slate-50">
                  <td class="px-4 py-3"><?= e(humanDate($p['payment_date'] ?? '')) ?></td>
                  <td class="px-4 py-3 font-semibold"><?= e($p['full_name'] ?? '') ?></td>
                  <td class="px-4 py-3"><?= e($p['room_number'] ?? '-') ?></td>
                  <td class="px-4 py-3"><?= e($p['room_type'] ?? '-') ?></td>
                  <td class="px-4 py-3 font-semibold">UGX <?= e(money($p['amount'] ?? 0)) ?></td>
                  <td class="px-4 py-3"><?= chip((string)($p['method'] ?? 'cash'), 'slate') ?></td>
                  <?php
                    $verificationStatus = strtolower((string)($p['verification_status'] ?? 'done'));
                    $verificationTone = match ($verificationStatus) {
                        'pending' => 'amber',
                        'confirmed' => 'blue',
                        'done' => 'green',
                        default => 'slate',
                    };
                  ?>
                  <td class="px-4 py-3"><?= chip(ucfirst($verificationStatus), $verificationTone) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="px-4 py-6 text-sm text-slate-700">
          <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4">
            No payments found for this selection.
          </div>
        </div>
      <?php endif; ?>
    </div>

  </main>
</body>
</html>
