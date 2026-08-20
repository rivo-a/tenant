<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/PaymentService.php';
require_once __DIR__ . '/tenant_filter.php';

require_login();

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function current_admin_id(): int
{
    if (isset($_SESSION['admin']['id'])) return (int)$_SESSION['admin']['id'];
    return (int)($_SESSION['admin_id'] ?? 0);
}

/**
 * Fetch room types from DB (so filters always match real data)
 */
function roomTypesForAdmin(PDO $pdo, int $admin_id): array
{
    $stmt = $pdo->prepare("
        SELECT name
        FROM room_types
        WHERE (admin_id IS NULL OR admin_id = :admin_id)
          AND (is_active IS NULL OR is_active = 1)
        ORDER BY name ASC
    ");
    $stmt->execute([':admin_id' => $admin_id]);

    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    return array_values(array_unique(array_filter(array_map('strval', $rows))));
}

function tenantStatuses(): array
{
    return ['active', 'inactive', 'exited'];
}

function paymentStatuses(): array
{
    return ['paid', 'partial', 'unpaid'];
}

$pdo = getDB();
$admin_id = current_admin_id();
if ($admin_id <= 0) {
    http_response_code(403);
    exit('Access denied: admin session not found.');
}

$paymentService = new PaymentService($pdo);

/* ---------------- HANDLE PAYMENT ---------------- */
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_payment'])) {
    try {
        verify_csrf($_POST['csrf'] ?? '');

        $tenant_id = (int)($_POST['tenant_id'] ?? 0);
        $amount    = (float)($_POST['amount'] ?? 0);
        $method    = trim((string)($_POST['method'] ?? 'cash'));
        $note      = trim((string)($_POST['note'] ?? ''));
        $date      = (string)($_POST['payment_date'] ?? date('Y-m-d'));

        if ($tenant_id <= 0) throw new RuntimeException('Invalid tenant.');
        if ($amount <= 0) throw new RuntimeException('Amount must be positive.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Invalid date format.');

        $check = $pdo->prepare("SELECT id FROM tenants WHERE id = :id AND admin_id = :admin LIMIT 1");
        $check->execute([':id' => $tenant_id, ':admin' => $admin_id]);
        if (!$check->fetch()) {
            throw new RuntimeException('Tenant not found or not owned by your account.');
        }

        $month = substr($date, 0, 7);

        $result = $paymentService->recordPayment(
            $tenant_id,
            $amount,
            $month,
            $date,
            $admin_id
        );

        $success = (($result['status'] ?? '') === 'paid')
            ? 'Payment recorded. Month fully paid.'
            : 'Partial payment recorded.';

        if ($note !== '' && method_exists($paymentService, 'addNoteToLastPayment')) {
            try { $paymentService->addNoteToLastPayment($tenant_id, $month, $note, $admin_id); } catch (Throwable $ignored) {}
        }

        if (function_exists('logAudit')) {
            try { logAudit($pdo, $admin_id, 'PAYMENT_RECEIVED', "Tenant #{$tenant_id} paid {$amount} on {$date} ({$month})."); } catch (Throwable $ignored) {}
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* ---------------- GET FILTER PARAMETERS ---------------- */
$search         = trim((string)($_GET['q'] ?? ''));
$room_type      = trim((string)($_GET['room_type'] ?? ''));
$tenant_status  = trim((string)($_GET['tenant_status'] ?? ''));
$payment_status = trim((string)($_GET['payment_status'] ?? ''));
$sort_by        = trim((string)($_GET['sort_by'] ?? 'full_name'));
$sort_order     = strtoupper((string)($_GET['sort_order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
$page           = max(1, (int)($_GET['page'] ?? 1));

$perPage = 20;
$offset  = ($page - 1) * $perPage;

/* ---------------- FETCH TENANTS ---------------- */
$params = [
    'search'         => $search,
    'room_type'      => $room_type,
    'tenant_status'  => $tenant_status,
    'payment_status' => $payment_status,
    'limit'          => $perPage,
    'offset'         => $offset,
    'sort_by'        => $sort_by,
    'sort_order'     => $sort_order,
];

$tenants = getFilteredTenants($pdo, $admin_id, $params);

$roomTypes = roomTypesForAdmin($pdo, $admin_id);
$tenantStatusesList = tenantStatuses();
$paymentStatusesList = paymentStatuses();

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
$active = 'payments';

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Payments • Dashboard</title>

  <!-- ✅ NO CDN: use compiled Tailwind -->
   <link rel="stylesheet" href="assets/css/tailwind.css">


<body class="min-h-screen bg-slate-50 text-slate-900">

  <?php
  // ✅ Your navbar partial (put the correct path you use in your system)
  // If your partial is somewhere else, just adjust this require path.
  $navPath = __DIR__ . '/partials/navbar.php';
  if (is_file($navPath)) require $navPath;
  ?>

  <main class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">

    <!-- Header -->
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">Payments Dashboard</h1>
        <p class="mt-1 text-sm text-slate-600">
          Search tenants, view outstanding balances, and record payments.
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <a href="payments_history.php"
           class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
          Payments History
        </a>

        <a href="reports.php"
           class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
          Reports
        </a>
      </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
      <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        <?= e($success) ?>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <!-- Filters card -->
    <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
        <div>
          <label class="mb-1 block text-xs font-semibold text-slate-600">Search</label>
          <input
            id="search"
            value="<?= e($search) ?>"
            placeholder="Search tenant..."
            oninput="loadTenants(1)"
            class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400"
          />
        </div>

        <div>
          <label class="mb-1 block text-xs font-semibold text-slate-600">Room type</label>
          <select id="room_type" onchange="loadTenants(1)"
                  class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
            <option value="">All Room Types</option>
            <?php foreach ($roomTypes as $rt): ?>
              <option value="<?= e($rt) ?>" <?= $rt === $room_type ? 'selected' : '' ?>>
                <?= e($rt) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-xs font-semibold text-slate-600">Tenant status</label>
          <select id="tenant_status" onchange="loadTenants(1)"
                  class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
            <option value="">All Tenants</option>
            <?php foreach ($tenantStatusesList as $ts): ?>
              <option value="<?= e($ts) ?>" <?= $ts === $tenant_status ? 'selected' : '' ?>>
                <?= e($ts) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-xs font-semibold text-slate-600">Payment status</label>
          <select id="payment_status" onchange="loadTenants(1)"
                  class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
            <option value="">All Payments</option>
            <?php foreach ($paymentStatusesList as $ps): ?>
              <option value="<?= e($ps) ?>" <?= $ps === $payment_status ? 'selected' : '' ?>>
                <?= e($ps) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
        <div class="text-xs text-slate-500">
          Showing up to <span class="font-semibold"><?= (int)$perPage ?></span> tenants per page
        </div>
        <button type="button"
                onclick="loadTenants(1)"
                class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
          Apply
        </button>
      </div>
    </div>

    <!-- Table card -->
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm" id="tenant-table">
          <thead class="bg-slate-50 text-slate-600">
            <tr>
              <th class="px-4 py-3 text-left font-semibold cursor-pointer select-none" onclick="sortColumn('full_name')">
                Tenant
              </th>
              <th class="px-4 py-3 text-left font-semibold cursor-pointer select-none" onclick="sortColumn('room_number')">
                Room
              </th>
              <th class="px-4 py-3 text-left font-semibold cursor-pointer select-none" onclick="sortColumn('rent_due_date')">
                Due date
              </th>
              <th class="px-4 py-3 text-left font-semibold cursor-pointer select-none" onclick="sortColumn('monthly_rent_effective')">
                Current rent
              </th>
              <th class="px-4 py-3 text-left font-semibold cursor-pointer select-none" onclick="sortColumn('outstanding_balance')">
                Outstanding
              </th>
              <th class="px-4 py-3 text-left font-semibold">
                Receive payment
              </th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-100">
          <?php if (!$tenants): ?>
            <tr>
              <td colspan="6" class="px-4 py-6">
                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                  No tenants found for your account (admin_id=<?= (int)$admin_id ?>) or current filters.
                </div>
              </td>
            </tr>
          <?php endif; ?>

          <?php foreach ($tenants as $t): ?>
            <?php
              $dueRaw = (string)($t['rent_due_date'] ?? '');
              $isOverdue = ($dueRaw !== '' && strtotime($dueRaw) !== false && strtotime($dueRaw) < strtotime(date('Y-m-d')));

              $out = (float)($t['outstanding_balance'] ?? 0);
              $rent = (float)($t['monthly_rent_effective'] ?? 0);

              // Simple status chip based on outstanding (feel free to replace with real status field if you have it)
              if ($out <= 0.00001) {
                  $payChip = chip('Paid', 'green');
              } elseif ($out < $rent && $rent > 0) {
                  $payChip = chip('Partial', 'amber');
              } else {
                  $payChip = chip('Unpaid', 'red');
              }
            ?>
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-slate-900"><?= e($t['full_name'] ?? '') ?></div>
                <div class="mt-1 text-xs text-slate-500">
                  <?= $payChip ?>
                </div>
              </td>

              <td class="px-4 py-3 text-slate-700">
                <?= e($t['room_number'] ?? '-') ?>
              </td>

              <td class="px-4 py-3">
                <div class="<?= $isOverdue ? 'text-red-700 font-semibold' : 'text-slate-700' ?>">
                  <?= e(humanDate($dueRaw)) ?>
                </div>
                <?php if ($isOverdue): ?>
                  <div class="mt-1 text-xs text-red-600">Overdue</div>
                <?php endif; ?>
              </td>

              <td class="px-4 py-3 text-slate-700">
                <?= number_format($rent, 0) ?>
              </td>

              <td class="px-4 py-3">
                <div class="font-semibold <?= $out > 0 ? 'text-slate-900' : 'text-emerald-700' ?>">
                  <?= number_format($out, 0) ?>
                </div>
              </td>

              <td class="px-4 py-3">
                <form method="post" class="flex flex-col gap-2 md:flex-row md:items-center">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="tenant_id" value="<?= (int)($t['id'] ?? 0) ?>">

                  <input type="number" name="amount" required placeholder="Amount"
                         class="w-full md:w-32 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">

                  <input type="date" name="payment_date" value="<?= e(date('Y-m-d')) ?>"
                         class="w-full md:w-44 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">

                  <select name="method"
                          class="w-full md:w-32 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400">
                    <option value="cash">Cash</option>
                    <option value="mobile">Mobile</option>
                    <option value="bank">Bank</option>
                  </select>

                  <button name="receive_payment"
                          class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Pay
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination footer -->
      <div class="flex items-center justify-between gap-2 border-t border-slate-200 px-4 py-3">
        <button
          type="button"
          onclick="loadTenants(currentPage - 1)"
          <?= $page <= 1 ? 'disabled' : '' ?>
          class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 disabled:opacity-50 disabled:hover:bg-white"
        >
          Prev
        </button>

        <div class="text-sm text-slate-600">
          Page <span class="font-semibold"><?= (int)$page ?></span>
        </div>

        <button
          type="button"
          onclick="loadTenants(currentPage + 1)"
          class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
        >
          Next
        </button>
      </div>
    </div>

  </main>

<script>
let currentPage = <?= (int)$page ?>;
let sortBy = '<?= e($sort_by) ?>';
let sortOrder = '<?= e($sort_order) ?>';

function loadTenants(page = 1) {
  if (page < 1) page = 1;
  currentPage = page;

  const params = new URLSearchParams({
    q: document.getElementById('search').value,
    room_type: document.getElementById('room_type').value,
    tenant_status: document.getElementById('tenant_status').value,
    payment_status: document.getElementById('payment_status').value,
    page: currentPage,
    sort_by: sortBy,
    sort_order: sortOrder
  });

  fetch('payments_ajax.php?' + params.toString(), {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.text())
  .then(html => {
    // payments_ajax.php must return <table> or <tbody> consistent HTML.
    document.getElementById('tenant-table').innerHTML = html;
  })
  .catch(() => {
    // silently ignore
  });
}

function sortColumn(col) {
  sortOrder = (sortBy === col && sortOrder === 'ASC') ? 'DESC' : 'ASC';
  sortBy = col;
  loadTenants(currentPage);
}
</script>

</body>
</html>
