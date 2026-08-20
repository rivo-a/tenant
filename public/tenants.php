<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../core/bootstrap.php';

/* ================= AUTH ================= */
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}
$admin_id = (int) $_SESSION['admin_id'];

/* ================= INPUTS ================= */
$search  = trim((string)($_GET['search'] ?? ''));
$status  = (string)($_GET['status'] ?? 'active'); // active | exited | all

/* ================= HELPERS ================= */
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function money($v): string { return number_format((float)$v, 0); }

function paymentStatus(array $t): string
{
    if ((float)$t['paid_this_month'] >= (float)$t['effective_rent']) {
        return 'Paid';
    }
    $day = (int)date('d');
    return $day > 5 ? 'Overdue' : 'Due';
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

/* ================= QUERY ================= */
$sql = "
SELECT
    t.id AS tenant_id,
    t.full_name,
    t.phone,
    t.status AS tenant_status,
    t.created_at,
    r.room_number,
    r.monthly_rent AS room_rent_override,
    rt.name AS room_type,
    COALESCE(r.monthly_rent, rt.default_monthly_rent) AS effective_rent,
    MAX(p.payment_date) AS last_payment_date,
    COALESCE(SUM(
        CASE
            WHEN strftime('%Y-%m', p.payment_date) = strftime('%Y-%m', 'now')
            THEN p.amount
            ELSE 0
        END
    ), 0) AS paid_this_month
FROM tenants t
JOIN rooms r ON r.id = t.room_id
JOIN room_types rt ON rt.id = r.room_type_id
LEFT JOIN payments p ON p.tenant_id = t.id
WHERE t.admin_id = :admin_id
";

$params = ['admin_id' => $admin_id];

/* ---- filters ---- */
if ($status !== 'all' && in_array($status, ['active','exited'], true)) {
    $sql .= " AND t.status = :status ";
    $params['status'] = $status;
}

if ($search !== '') {
    $sql .= "
        AND (
            t.full_name LIKE :search
            OR t.phone LIKE :search
            OR r.room_number LIKE :search
        )
    ";
    $params['search'] = "%{$search}%";
}

$sql .= "
GROUP BY t.id
ORDER BY t.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ================= ARREARS (FAST: 1 QUERY FOR ALL TENANTS) ================= */
$arrearsByTenant = [];

if ($tenants) {
    $tenantIds = array_map(fn($x) => (int)$x['tenant_id'], $tenants);
    $tenantIds = array_values(array_unique(array_filter($tenantIds)));

    if ($tenantIds) {
        $placeholders = implode(',', array_fill(0, count($tenantIds), '?'));

        // NOTE: your original logic tried to join rent_schedule with payments this month.
        // We preserve the intent: arrears for "current month" from rent_schedule vs payments this month.
        $arrearsStmt = $pdo->prepare("
            SELECT
                r.tenant_id,
                SUM(r.amount_due - COALESCE(p.amount_paid, 0)) AS arrears
            FROM rent_schedule r
            LEFT JOIN (
                SELECT tenant_id, strftime('%Y-%m', payment_date) AS month, SUM(amount) AS amount_paid
                FROM payments
                GROUP BY tenant_id, month
            ) p
              ON p.tenant_id = r.tenant_id
             AND r.month = p.month
            WHERE r.tenant_id IN ($placeholders)
              AND r.month = strftime('%Y-%m','now')
            GROUP BY r.tenant_id
        ");
        $arrearsStmt->execute($tenantIds);
        $rows = $arrearsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $arrearsByTenant[(int)$row['tenant_id']] = (float)($row['arrears'] ?? 0);
        }
    }
}

function lastPaidLabel(?string $date): string
{
    if (!$date) return '—';
    $ts = strtotime($date);
    if (!$ts) return '—';
    return date('d M Y', $ts);
}

$activeNav = 'tenants';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Tenants</title>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Tailwind (compiled, no CDN) -->
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>

<body class="min-h-screen bg-slate-50 text-slate-900">

  <?php
  $active = $activeNav; // navbar.php expects $active
  $navPath = __DIR__ . '/partials/navbar.php';
  if (is_file($navPath)) require $navPath;
  ?>

  <main class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">

    <!-- Header -->
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold tracking-tight font-alt">Tenants</h1>
        <p class="mt-1 text-sm text-slate-600">
          Search tenants, check payment status, and manage tenant actions.
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <a href="payments.php"
           class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
          Receive Payment
        </a>
        <a href="tenant_payments.php"
           class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
          Tenant Payments
        </a>
      </div>
    </div>

    <!-- Filters -->
    <form method="get" class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <div>
          <label class="mb-1 block text-xs font-semibold text-slate-600">Search</label>
          <input
            type="text"
            name="search"
            value="<?= e($search) ?>"
            placeholder="Name, phone, room..."
            class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400"
          />
        </div>

        <div>
          <label class="mb-1 block text-xs font-semibold text-slate-600">Status</label>
          <select
            name="status"
            class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400"
          >
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="exited" <?= $status === 'exited' ? 'selected' : '' ?>>Exited</option>
            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
          </select>
        </div>

        <div class="flex items-end gap-2">
          <button class="w-full inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            Filter
          </button>

          <?php if ($search !== '' || $status !== 'active'): ?>
            <a href="tenants.php"
               class="inline-flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
              Reset
            </a>
          <?php endif; ?>
        </div>
      </div>
    </form>

    <!-- Table -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-4 py-4 border-b border-slate-200 flex items-center justify-between">
        <div>
          <h2 class="text-lg font-bold font-alt">Tenant List</h2>
          <p class="text-sm text-slate-600">
            Showing <?= (int)count($tenants) ?> tenant(s).
          </p>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-600">
            <tr>
              <th class="px-4 py-3 text-left font-semibold">Tenant</th>
              <th class="px-4 py-3 text-left font-semibold">Room</th>
              <th class="px-4 py-3 text-left font-semibold">Rent</th>
              <th class="px-4 py-3 text-left font-semibold">Status</th>
              <th class="px-4 py-3 text-left font-semibold">Payment</th>
              <th class="px-4 py-3 text-left font-semibold">Last paid</th>
              <th class="px-4 py-3 text-left font-semibold">Actions</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-100">
            <?php if (!$tenants): ?>
              <tr>
                <td colspan="7" class="px-4 py-6">
                  <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                    No tenants found.
                  </div>
                </td>
              </tr>
            <?php endif; ?>

            <?php foreach ($tenants as $t): ?>
              <?php
                $tenantIdRow = (int)$t['tenant_id'];
                $payStatus = paymentStatus($t);

                $arrears = (float)($arrearsByTenant[$tenantIdRow] ?? 0);

                $tenantChip = ($t['tenant_status'] === 'active')
                    ? chip('Active', 'blue')
                    : chip('Exited', 'slate');

                $payChip = match ($payStatus) {
                    'Paid' => chip('Paid', 'green'),
                    'Due' => chip('Due', 'amber'),
                    default => chip('Overdue', 'red'),
                };

                $arrearsLine = ($arrears > 0)
                    ? '<div class="mt-1 text-xs text-red-700">Arrears: UGX '.e(number_format($arrears, 0)).'</div>'
                    : '';
              ?>
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-3">
                  <div class="font-semibold"><?= e($t['full_name'] ?? '') ?></div>
                  <div class="text-xs text-slate-500"><?= e($t['phone'] ?? '') ?></div>
                </td>

                <td class="px-4 py-3">
                  <div class="font-semibold"><?= e($t['room_number'] ?? '') ?></div>
                  <div class="text-xs text-slate-500"><?= e($t['room_type'] ?? '') ?></div>
                </td>

                <td class="px-4 py-3 font-semibold font-alt">
                  UGX <?= e(money($t['effective_rent'] ?? 0)) ?>
                </td>

                <td class="px-4 py-3"><?= $tenantChip ?></td>

                <td class="px-4 py-3">
                  <?= $payChip ?>
                  <?= $arrearsLine ?>
                </td>

                <td class="px-4 py-3 text-slate-700">
                  <?= e(lastPaidLabel($t['last_payment_date'] ?? null)) ?>
                </td>

                <td class="px-4 py-3">
                  <div class="flex flex-wrap gap-2">
                    <a class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                       href="tenant_view.php?id=<?= $tenantIdRow ?>">
                      View
                    </a>

                    <a class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                       href="tenant_edit.php?id=<?= $tenantIdRow ?>">
                      Edit
                    </a>

                    <?php if (($t['tenant_status'] ?? '') === 'active'): ?>
                      <a class="inline-flex items-center rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100"
                         href="tenant_exit.php?id=<?= $tenantIdRow ?>"
                         onclick="return confirm('Exit this tenant?')">
                        Exit
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>

        </table>
      </div>
    </div>

  </main>
</body>
</html>
