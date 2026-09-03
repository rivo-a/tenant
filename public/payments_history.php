<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../core/bootstrap.php';

/* ================= AUTH ================= */
if (!isLoggedIn()) {
    redirect('login.php');
}

$admin_id = (int)($_SESSION['admin_id'] ?? 0);

/* ================= INPUT ================= */
$search    = trim((string)($_GET['search'] ?? ''));
$roomType  = (string)($_GET['room_type'] ?? '');
$tenantId  = (string)($_GET['tenant_id'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 50;
$offset    = ($page - 1) * $perPage;

/* ================= HELPERS ================= */
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function money($v): string { return number_format((float)$v, 0); }

function dueIsOverdue(?string $due): bool {
    if (!$due) return false;
    try {
        $today = new DateTime('today');
        $dueDt = new DateTime($due);
        return $dueDt < $today;
    } catch (Throwable $e) {
        return false;
    }
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

/* ================= LOOKUPS ================= */
$roomTypes = $pdo->query("SELECT id, name FROM room_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$tenantsStmt = $pdo->prepare("
    SELECT id, full_name, status
    FROM tenants
    WHERE admin_id = ?
    ORDER BY full_name
");
$tenantsStmt->execute([$admin_id]);
$tenants = $tenantsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ================= FILTER SQL ================= */
$where  = ['t.admin_id = :admin', 'p.admin_id = :payment_admin'];
$params = [
    'admin' => $admin_id,
    'payment_admin' => $admin_id,
    'payment_admin_status' => $admin_id,
];

if ($search !== '') {
    $where[] = '(t.full_name LIKE :q OR r.room_number LIKE :q)';
    $params['q'] = "%$search%";
}
if ($roomType !== '') {
    $where[] = 'r.room_type_id = :rt';
    $params['rt'] = $roomType;
}
if ($tenantId !== '') {
    $where[] = 't.id = :tid';
    $params['tid'] = $tenantId;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

/* ================= COUNT ================= */
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    $whereSQL
");
$countParams = $params;
unset($countParams['payment_admin_status']);
$countStmt->execute($countParams);

$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* clamp current page */
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

/* ================= DATA ================= */
$dataStmt = $pdo->prepare("
    SELECT
        p.payment_date,
        p.payment_month,
        p.amount,
        p.method,
        p.note,
        t.full_name,
        t.status AS tenant_status,
        t.exit_date,
        t.rent_due_date,
        r.room_number,
        rt.name AS room_type,
        COALESCE(
            NULLIF(t.monthly_rent, 0),
            NULLIF(r.monthly_rent, 0),
            rt.default_monthly_rent,
            0
        ) AS effective_rent,
        (
            SELECT
                CASE
                    WHEN SUM(p2.amount) >= COALESCE(
                        NULLIF(t.monthly_rent, 0),
                        NULLIF(r.monthly_rent, 0),
                        rt.default_monthly_rent,
                        0
                    )
                    THEN 'PAID'
                    ELSE 'PARTIAL'
                END
            FROM payments p2
            WHERE p2.tenant_id = p.tenant_id
              AND p2.admin_id = :payment_admin_status
              AND p2.payment_month = p.payment_month
        ) AS month_status
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    LEFT JOIN room_types rt ON r.room_type_id = rt.id
    $whereSQL
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT $perPage OFFSET $offset
");
$dataStmt->execute($params);
$payments = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* pagination labels */
$from = $totalRows ? ($offset + 1) : 0;
$to   = min($offset + $perPage, $totalRows);

$active = 'history'; // matches navbar.php key
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Payments History</title>

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
        <h1 class="text-2xl font-bold tracking-tight font-alt">Payments History</h1>
        <p class="mt-1 text-sm text-slate-600">
          Browse payments, filter by tenant or room type, and check monthly status.
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <a href="payments.php"
           class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
          Receive Payment
        </a>
        <a href="reports.php"
           class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
          Reports
        </a>
      </div>
    </div>

    <!-- Filters -->
    <form method="get" class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="grid grid-cols-1 gap-3 lg:grid-cols-4">
        <div class="lg:col-span-1">
          <label class="mb-1 block text-xs font-semibold text-slate-600">Search</label>
          <input name="search" value="<?= e($search) ?>" placeholder="Tenant or room..."
                 class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
        </div>

        <div>
          <label class="mb-1 block text-xs font-semibold text-slate-600">Room type</label>
          <select name="room_type"
                  class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
            <option value="">All Types</option>
            <?php foreach ($roomTypes as $rt): ?>
              <option value="<?= e($rt['id']) ?>" <?= ((string)$roomType === (string)$rt['id']) ? 'selected' : '' ?>>
                <?= e($rt['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-xs font-semibold text-slate-600">Tenant</label>
          <select name="tenant_id"
                  class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
            <option value="">All Tenants</option>
            <?php foreach ($tenants as $t): ?>
              <option value="<?= e($t['id']) ?>" <?= ((string)$tenantId === (string)$t['id']) ? 'selected' : '' ?>>
                <?= e($t['full_name']) ?><?= strtolower((string)($t['status'] ?? '')) === 'exited' ? ' (Exited)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="flex items-end gap-2">
          <button class="w-full inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Filter
          </button>
          <?php if ($search !== '' || $roomType !== '' || $tenantId !== '' || $page !== 1): ?>
            <a href="payments_history.php"
               class="inline-flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
              Reset
            </a>
          <?php endif; ?>
        </div>
      </div>
    </form>

    <!-- Table card -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-4 py-4 border-b border-slate-200 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-lg font-bold font-alt">Payment Records</h2>
          <p class="text-sm text-slate-600">
            Showing <?= (int)$from ?>–<?= (int)$to ?> of <?= (int)$totalRows ?> record(s).
          </p>
        </div>
        <div class="text-xs text-slate-500">
          Per page: <span class="font-semibold"><?= (int)$perPage ?></span>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-600">
            <tr>
              <th class="px-4 py-3 text-left font-semibold">Date</th>
              <th class="px-4 py-3 text-left font-semibold">Month</th>
              <th class="px-4 py-3 text-left font-semibold">Tenant</th>
              <th class="px-4 py-3 text-left font-semibold">Tenant status</th>
              <th class="px-4 py-3 text-left font-semibold">Room</th>
              <th class="px-4 py-3 text-left font-semibold">Type</th>
              <th class="px-4 py-3 text-left font-semibold">Amount</th>
              <th class="px-4 py-3 text-left font-semibold">Method</th>
              <th class="px-4 py-3 text-left font-semibold">Note</th>
              <th class="px-4 py-3 text-left font-semibold">Month status</th>
              <th class="px-4 py-3 text-left font-semibold">Due date</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-100">
            <?php if (!$payments): ?>
              <tr>
                <td colspan="9" class="px-4 py-6">
                  <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                    No payments found for this filter.
                  </div>
                </td>
              </tr>
            <?php endif; ?>

            <?php foreach ($payments as $p): ?>
              <?php
                $status = strtoupper((string)($p['month_status'] ?? 'PARTIAL'));
                $statusChip = ($status === 'PAID') ? chip('PAID', 'green') : chip('PARTIAL', 'amber');
                $tenantStatus = strtolower((string)($p['tenant_status'] ?? 'active'));
                $tenantStatusChip = $tenantStatus === 'exited'
                    ? chip('Exited', 'slate')
                    : chip('Active', 'blue');
                $method = strtolower(trim((string)($p['method'] ?? '')));
                $methodLabel = $method !== '' ? ucfirst($method) : '—';
                $note = trim((string)($p['note'] ?? ''));

                $due = (string)($p['rent_due_date'] ?? '');
                $dueChip = $due
                    ? (dueIsOverdue($due) ? chip($due, 'red') : chip($due, 'slate'))
                    : chip('—', 'slate');
              ?>
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-3"><?= e($p['payment_date'] ?? '') ?></td>
                <td class="px-4 py-3 font-semibold"><?= e($p['payment_month'] ?? '') ?></td>
                <td class="px-4 py-3 font-semibold">
                  <?= e($p['full_name'] ?? '') ?>
                  <?php if ($tenantStatus === 'exited' && !empty($p['exit_date'])): ?>
                    <div class="mt-1 text-xs font-normal text-slate-500">Exited <?= e($p['exit_date']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3"><?= $tenantStatusChip ?></td>
                <td class="px-4 py-3"><?= e($p['room_number'] ?? '-') ?></td>
                <td class="px-4 py-3"><?= e($p['room_type'] ?? '-') ?></td>
                <td class="px-4 py-3 font-semibold font-alt">UGX <?= e(money($p['amount'] ?? 0)) ?></td>
                <td class="px-4 py-3"><?= e($methodLabel) ?></td>
                <td class="max-w-xs px-4 py-3 text-slate-700"><?= e($note !== '' ? $note : '—') ?></td>
                <td class="px-4 py-3"><?= $statusChip ?></td>
                <td class="px-4 py-3"><?= $dueChip ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>

        </table>
      </div>

      <!-- Pagination -->
      <div class="px-4 py-4 border-t border-slate-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="text-sm text-slate-600">
          Page <span class="font-semibold"><?= (int)$page ?></span> of <span class="font-semibold"><?= (int)$totalPages ?></span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <?php
            $base = $_GET;
            $base['page'] = 1;
          ?>
          <a class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 <?= $page <= 1 ? 'pointer-events-none opacity-50' : '' ?>"
             href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">
            First
          </a>

          <a class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 <?= $page <= 1 ? 'pointer-events-none opacity-50' : '' ?>"
             href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>">
            Prev
          </a>

          <?php
            // show a compact window of pages
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
          ?>
            <a class="inline-flex items-center rounded-xl px-3 py-2 text-sm font-semibold
                      <?= $i === $page ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
              <?= (int)$i ?>
            </a>
          <?php endfor; ?>

          <a class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : '' ?>"
             href="?<?= http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])) ?>">
            Next
          </a>

          <a class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : '' ?>"
             href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>">
            Last
          </a>
        </div>
      </div>

    </div>

  </main>
</body>
</html>
