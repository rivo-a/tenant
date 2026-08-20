<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_login();

$adminId  = (int)($_SESSION['admin_id'] ?? 0);
$tenantId = (int)($_GET['tenant_id'] ?? 0);
$q        = trim((string)($_GET['q'] ?? ''));
$month    = trim((string)($_GET['month'] ?? ''));

/* ================= TENANT LIST (FOR SELECTING) ================= */

$sql = "
    SELECT
        t.id,
        t.full_name,
        t.phone,
        r.room_number
    FROM tenants t
    LEFT JOIN rooms r ON t.room_id = r.id
    WHERE t.admin_id = ?
";
$params = [$adminId];

if ($q !== '') {
    $sql .= " AND (
        t.full_name LIKE ?
        OR t.phone LIKE ?
        OR r.room_number LIKE ?
    )";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

$sql .= " ORDER BY t.full_name LIMIT 20";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tenantList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ================= LOAD SELECTED TENANT ================= */

$tenant = null;
$rent   = 0.0;
$months = [];
$transactions = [];

if ($tenantId > 0) {

    $stmt = $pdo->prepare("
        SELECT
            t.full_name,
            t.phone,
            COALESCE(r.monthly_rent, rt.default_monthly_rent, 0) AS rent
        FROM tenants t
        LEFT JOIN rooms r ON t.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE t.id = ?
          AND t.admin_id = ?
        LIMIT 1
    ");
    $stmt->execute([$tenantId, $adminId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($tenant) {
        $rent = (float)$tenant['rent'];

        $stmt = $pdo->prepare("
            SELECT
                payment_month,
                SUM(amount) AS total_paid
            FROM payments
            WHERE tenant_id = ?
              AND admin_id  = ?
            GROUP BY payment_month
            ORDER BY payment_month DESC
        ");
        $stmt->execute([$tenantId, $adminId]);
        $months = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $stmt = $pdo->prepare("
                SELECT
                    amount,
                    payment_date,
                    created_at
                FROM payments
                WHERE tenant_id = ?
                  AND admin_id  = ?
                  AND payment_month = ?
                ORDER BY payment_date
            ");
            $stmt->execute([$tenantId, $adminId, $month]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
}

/* ================= HELPERS ================= */

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

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

$active = 'tenant_payments';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Tenant Payment History</title>

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
        <h1 class="text-2xl font-bold tracking-tight font-alt">Tenant Payment History</h1>
        <p class="mt-1 text-sm text-slate-600">
          Search tenants, then open a tenant to see monthly balances and transactions.
        </p>
      </div>

      <div class="flex items-center gap-2">
        <a href="payments.php"
           class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
          Receive Payment
        </a>
        <a href="dashboard.php"
           class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
          Dashboard
        </a>
      </div>
    </div>

    <!-- Search + Tenant list -->
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm mb-6">
      <form method="get" class="flex flex-col gap-2 sm:flex-row sm:items-center">
        <input
          name="q"
          value="<?= e($q) ?>"
          placeholder="Search name / phone / room..."
          class="w-full sm:max-w-md rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400"
        />
        <button class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
          Search
        </button>

        <?php if ($q !== ''): ?>
          <a href="tenant_payments.php"
             class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
            Clear
          </a>
        <?php endif; ?>
      </form>

      <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200">
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="px-4 py-3 text-left font-semibold">Tenant</th>
                <th class="px-4 py-3 text-left font-semibold">Phone</th>
                <th class="px-4 py-3 text-left font-semibold">Room</th>
                <th class="px-4 py-3 text-left font-semibold">Action</th>
              </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
              <?php if (!$tenantList): ?>
                <tr>
                  <td colspan="4" class="px-4 py-6">
                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                      No tenants found.
                    </div>
                  </td>
                </tr>
              <?php endif; ?>

              <?php foreach ($tenantList as $t): ?>
                <tr class="hover:bg-slate-50" id="tenant-row-<?= (int)$t['id'] ?>">
                  <td class="px-4 py-3 font-semibold"><?= e($t['full_name'] ?? '') ?></td>
                  <td class="px-4 py-3 text-slate-700"><?= e($t['phone'] ?? '') ?></td>
                  <td class="px-4 py-3 text-slate-700"><?= e($t['room_number'] ?? '—') ?></td>
                  <td class="px-4 py-3">
                    <a
                      href="?tenant_id=<?= (int)$t['id'] ?>&q=<?= urlencode($q) ?>#tenant-row-<?= (int)$t['id'] ?>"
                      class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                    >
                      View Payments
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>

          </table>
        </div>
      </div>
    </div>

    <!-- Selected tenant summary -->
    <?php if ($tenant): ?>
      <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
        <div class="px-4 py-4 border-b border-slate-200 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-lg font-bold font-alt">
              <?= e($tenant['full_name'] ?? '') ?>
            </h2>
            <div class="mt-1 text-sm text-slate-600">
              Phone: <?= e($tenant['phone'] ?? '') ?>
              <span class="mx-2 text-slate-300">•</span>
              Rent: <span class="font-semibold text-slate-900">UGX <?= e(money($rent)) ?></span> / month
            </div>
          </div>

          <?php if ($month && preg_match('/^\d{4}-\d{2}$/', $month)): ?>
            <div class="flex items-center gap-2">
              <?= chip("Month: $month", 'blue') ?>
              <a href="?tenant_id=<?= (int)$tenantId ?>&q=<?= urlencode($q) ?>"
                 class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                Clear month
              </a>
            </div>
          <?php endif; ?>
        </div>

        <!-- Month rows -->
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="px-4 py-3 text-left font-semibold">Month</th>
                <th class="px-4 py-3 text-left font-semibold">Paid</th>
                <th class="px-4 py-3 text-left font-semibold">Balance</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php if (!$months): ?>
                <tr>
                  <td colspan="4" class="px-4 py-6">
                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                      No payments recorded for this tenant yet.
                    </div>
                  </td>
                </tr>
              <?php endif; ?>

              <?php foreach ($months as $m): ?>
                <?php
                  $paid = (float)($m['total_paid'] ?? 0);
                  $balance = max(0, $rent - $paid);

                  if ($balance <= 0.00001) {
                      $status = chip('PAID', 'green');
                  } elseif ($paid > 0) {
                      $status = chip('PARTIAL', 'amber');
                  } else {
                      $status = chip('UNPAID', 'red');
                  }

                  $mth = (string)($m['payment_month'] ?? '');
                  $isActiveMonth = ($mth !== '' && $mth === $month);
                ?>
                <tr class="<?= $isActiveMonth ? 'bg-sky-50/60' : 'hover:bg-slate-50' ?>">
                  <td class="px-4 py-3 font-semibold">
                    <a class="underline decoration-slate-300 hover:decoration-slate-500"
                       href="?tenant_id=<?= (int)$tenantId ?>&month=<?= e($mth) ?>&q=<?= urlencode($q) ?>">
                      <?= e($mth) ?>
                    </a>
                  </td>
                  <td class="px-4 py-3">UGX <?= e(money($paid)) ?></td>
                  <td class="px-4 py-3 font-bold <?= $balance > 0 ? 'text-red-700' : 'text-emerald-700' ?>">
                    UGX <?= e(money($balance)) ?>
                  </td>
                  <td class="px-4 py-3"><?= $status ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Transactions -->
      <?php if ($transactions): ?>
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="px-4 py-4 border-b border-slate-200">
            <h3 class="text-lg font-bold font-alt">Transactions for <?= e($month) ?></h3>
            <p class="text-sm text-slate-600">Individual payments recorded in this month.</p>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50 text-slate-600">
                <tr>
                  <th class="px-4 py-3 text-left font-semibold">Date</th>
                  <th class="px-4 py-3 text-left font-semibold">Amount</th>
                  <th class="px-4 py-3 text-left font-semibold">Recorded At</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <?php foreach ($transactions as $tx): ?>
                  <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3"><?= e($tx['payment_date'] ?? '') ?></td>
                    <td class="px-4 py-3 font-semibold">UGX <?= e(money($tx['amount'] ?? 0)) ?></td>
                    <td class="px-4 py-3 text-slate-700"><?= e($tx['created_at'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  </main>
  <script>
(function () {
  const id = <?= (int)$tenantId ?>;
  if (!id) return;

  const el = document.getElementById('tenant-row-' + id);
  if (!el) return;

  // Smooth scroll + leave space for sticky navbar
  const y = el.getBoundingClientRect().top + window.pageYOffset - 110;
  window.scrollTo({ top: y, behavior: 'smooth' });
})();
</script>

</body>
</html>
