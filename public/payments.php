<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/PaymentService.php';
require_once __DIR__ . '/tenant_filter.php';

require_login();

/**
 * Fetch room types from DB so filters always match this administrator's data.
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
$success = is_scalar($_SESSION['success'] ?? null) ? trim((string)$_SESSION['success']) : '';
unset($_SESSION['success']);
$error = '';
$paymentFormData = [];

$postString = static function (string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_payment'])) {
    $paymentFormData = [
        'tenant_id' => 0,
        'amount' => $postString('amount'),
        'payment_date' => $postString('payment_date', date('Y-m-d')),
        'method' => strtolower($postString('method', 'cash')),
        'note' => $postString('note'),
    ];

    try {
        $csrfValue = $_POST['csrf'] ?? '';
        verify_csrf(is_scalar($csrfValue) ? (string)$csrfValue : '');

        $tenantValue = $_POST['tenant_id'] ?? 0;
        $tenantId = is_scalar($tenantValue) ? filter_var($tenantValue, FILTER_VALIDATE_INT) : false;
        $paymentFormData['tenant_id'] = $tenantId === false ? 0 : (int)$tenantId;

        $amountRaw = $paymentFormData['amount'];
        $amount = is_numeric($amountRaw) ? (float)$amountRaw : 0.0;
        $method = $paymentFormData['method'];
        $note = $paymentFormData['note'];
        $date = $paymentFormData['payment_date'];

        if ($paymentFormData['tenant_id'] <= 0) {
            throw new RuntimeException('Invalid tenant.');
        }
        if ($amount <= 0 || !is_finite($amount)) {
            throw new RuntimeException('Amount must be positive.');
        }
        if (!in_array($method, ['cash', 'mobile', 'bank'], true)) {
            throw new RuntimeException('Please select a valid payment method.');
        }
        if (strlen($note) > 160) {
            throw new RuntimeException('Payment note must be 160 characters or fewer.');
        }

        $paymentDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $hasDateErrors = $dateErrors !== false && (
            $dateErrors['warning_count'] > 0 ||
            $dateErrors['error_count'] > 0
        );
        if (
            $paymentDateObject === false ||
            $hasDateErrors ||
            $paymentDateObject->format('Y-m-d') !== $date
        ) {
            throw new RuntimeException('Please enter a valid payment date.');
        }

        // Repeat ownership and active eligibility before entering the service.
        // The service repeats this guard for stale-page and race-safe protection.
        $check = $pdo->prepare("
            SELECT id
            FROM tenants
            WHERE id = :id
              AND admin_id = :admin
              AND LOWER(COALESCE(status, '')) = 'active'
              AND exit_date IS NULL
            LIMIT 1
        ");
        $check->execute([
            ':id' => $paymentFormData['tenant_id'],
            ':admin' => $admin_id,
        ]);
        if (!$check->fetch()) {
            throw new RuntimeException('Tenant is no longer active or is not owned by your account.');
        }

        $result = $paymentService->recordPayment(
            $paymentFormData['tenant_id'],
            round($amount, 2),
            $paymentDateObject->format('Y-m'),
            $paymentDateObject->format('Y-m-d'),
            $admin_id,
            $method,
            $note
        );

        $successMessage = (($result['status'] ?? '') === 'paid')
            ? 'Payment recorded. Month fully paid.'
            : 'Partial payment recorded.';

        if (function_exists('logAudit')) {
          function getTenantFullName(PDO $pdo, int $tenantId): ?string
{
    $stmt = $pdo->prepare("
        SELECT full_name
        FROM tenants
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute(['id' => $tenantId]);

    $name = $stmt->fetchColumn();

    return $name !== false ? (string) $name : null;
}
            try {
              $name = getTenantFullName($pdo,$paymentFormData['tenant_id']);
                logAudit(
                    $admin_id,
                    'PAYMENT_RECEIVED',
                    "Tenant {$name} paid " . number_format($amount, 2) . " on {$date} via {$method}."
                );
            } catch (Throwable $ignored) {
                // Audit failure must not undo a committed payment.
            }
        }

        $_SESSION['success'] = $successMessage;
        $redirectQuery = [];
        foreach (['q', 'room_type', 'payment_status', 'page', 'sort_by', 'sort_order'] as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key]) && (string)$_GET[$key] !== '') {
                $redirectQuery[$key] = (string)$_GET[$key];
            }
        }
        redirect('payments.php' . ($redirectQuery ? '?' . http_build_query($redirectQuery) : ''));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$getString = static function (string $key, string $default = ''): string {
    $value = $_GET[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
};

$search = $getString('q');
$room_type = $getString('room_type');
$tenantIdValue = $getString('tenant_id', '0');
$tenantId = filter_var($tenantIdValue, FILTER_VALIDATE_INT);
$tenantId = $tenantId === false ? 0 : max(0, (int)$tenantId);
// Receive Payment intentionally has one roster: active tenants only.
$tenant_status = 'active';
$payment_status = $getString('payment_status');
$sort_by = $getString('sort_by', 'full_name');
$sort_order = strtoupper($getString('sort_order', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
$page = max(1, (int)$getString('page', '1'));
$perPage = 20;

$params = [
    'search' => $search,
    'room_type' => $room_type,
    'tenant_status' => $tenant_status,
    'payment_status' => $payment_status,
    'tenant_id' => $tenantId,
    'limit' => $perPage + 1,
    'offset' => ($page - 1) * $perPage,
    'sort_by' => $sort_by,
    'sort_order' => $sort_order,
];

$tenants = getFilteredTenants($pdo, $admin_id, $params);
$hasNext = count($tenants) > $perPage;
if ($hasNext) {
    $tenants = array_slice($tenants, 0, $perPage);
}

$roomTypes = roomTypesForAdmin($pdo, $admin_id);
$paymentStatusesList = paymentStatuses();
$paymentActionQuery = array_filter([
    'q' => $search,
    'room_type' => $room_type,
    'tenant_status' => 'active',
    'payment_status' => $payment_status,
    'tenant_id' => $tenantId > 0 ? $tenantId : '',
    'page' => $page,
    'sort_by' => $sort_by,
    'sort_order' => $sort_order,
], static fn($value): bool => $value !== '' && $value !== null);

$sortAria = static function (string $column) use ($sort_by, $sort_order): string {
    if ($sort_by !== $column) {
        return 'none';
    }
    return $sort_order === 'DESC' ? 'descending' : 'ascending';
};

$active = 'payments';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Payments • Dashboard</title>
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>

<body class="min-h-screen bg-slate-50 text-slate-900">
  <?php require __DIR__ . '/partials/navbar.php'; ?>

  <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="font-alt text-2xl font-bold tracking-tight">Payments Dashboard</h1>
        <p class="mt-1 text-sm text-slate-600">
          Search active tenants, review balances, and record rent payments.
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <a href="payments_history.php"
           class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
          Payments History
        </a>
        <a href="reports.php"
           class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
          Reports
        </a>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
        <?= e($success) ?>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <form id="paymentFilters" method="get" class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <?php if ($tenantId > 0): ?>
        <input type="hidden" name="tenant_id" value="<?= (int)$tenantId ?>">
      <?php endif; ?>
      <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
        <div>
          <label for="search" class="mb-1 block text-xs font-semibold text-slate-600">Search</label>
          <input
            id="search"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Search tenant..."
            autocomplete="off"
            class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
          >
        </div>

        <div>
          <label for="room_type" class="mb-1 block text-xs font-semibold text-slate-600">Room type</label>
          <select id="room_type" name="room_type"
                  class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
            <option value="">All room types</option>
            <?php foreach ($roomTypes as $roomTypeOption): ?>
              <option value="<?= e($roomTypeOption) ?>" <?= $roomTypeOption === $room_type ? 'selected' : '' ?>>
                <?= e($roomTypeOption) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label for="tenant_status" class="mb-1 block text-xs font-semibold text-slate-600">Tenant roster</label>
          <select id="tenant_status" name="tenant_status" aria-describedby="tenant-status-help"
                  class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
            <option value="active" selected>Active tenants only</option>
          </select>
          <p id="tenant-status-help" class="mt-1 text-xs text-slate-500">Exited tenants remain in payment history.</p>
        </div>

        <div>
          <label for="payment_status" class="mb-1 block text-xs font-semibold text-slate-600">Payment status</label>
          <select id="payment_status" name="payment_status"
                  class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
            <option value="">All payment statuses</option>
            <?php foreach ($paymentStatusesList as $statusOption): ?>
              <option value="<?= e($statusOption) ?>" <?= $statusOption === $payment_status ? 'selected' : '' ?>>
                <?= e(ucfirst($statusOption)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">
          Active roster · <span class="font-semibold">20</span> tenants per page · Search updates as you type.
        </p>
        <button type="submit" id="applyPaymentFilters"
                class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
          Apply filters
        </button>
      </div>
    </form>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="payment-roster-heading">
      <div class="flex flex-col gap-1 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 id="payment-roster-heading" class="font-alt text-lg font-bold">Active tenant payment roster</h2>
          <p class="text-sm text-slate-600">Exited tenants are excluded from payment actions.</p>
        </div>
        <div id="paymentLoadingStatus" class="text-xs text-slate-500" role="status" aria-live="polite"></div>
      </div>

      <div class="overflow-x-auto">
        <table id="tenant-table" class="min-w-[1120px] w-full text-sm">
          <caption class="sr-only">Active tenants and their payment balances</caption>
          <thead class="bg-slate-50 text-slate-600">
            <tr>
              <th scope="col" data-sort-header="full_name" aria-sort="<?= e($sortAria('full_name')) ?>" class="px-4 py-3 text-left font-semibold">
                <button type="button" data-sort="full_name" class="inline-flex min-h-11 items-center rounded-lg text-left hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                  Tenant <span aria-hidden="true" class="ml-1 text-slate-400">↕</span>
                </button>
              </th>
              <th scope="col" data-sort-header="room_number" aria-sort="<?= e($sortAria('room_number')) ?>" class="px-4 py-3 text-left font-semibold">
                <button type="button" data-sort="room_number" class="inline-flex min-h-11 items-center rounded-lg text-left hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                  Room <span aria-hidden="true" class="ml-1 text-slate-400">↕</span>
                </button>
              </th>
              <th scope="col" data-sort-header="rent_due_date" aria-sort="<?= e($sortAria('rent_due_date')) ?>" class="px-4 py-3 text-left font-semibold">
                <button type="button" data-sort="rent_due_date" class="inline-flex min-h-11 items-center rounded-lg text-left hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                  Due date <span aria-hidden="true" class="ml-1 text-slate-400">↕</span>
                </button>
              </th>
              <th scope="col" data-sort-header="monthly_rent_effective" aria-sort="<?= e($sortAria('monthly_rent_effective')) ?>" class="px-4 py-3 text-left font-semibold">
                <button type="button" data-sort="monthly_rent_effective" class="inline-flex min-h-11 items-center rounded-lg text-left hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                  Current rent <span aria-hidden="true" class="ml-1 text-slate-400">↕</span>
                </button>
              </th>
              <th scope="col" data-sort-header="outstanding_balance" aria-sort="<?= e($sortAria('outstanding_balance')) ?>" class="px-4 py-3 text-left font-semibold">
                <button type="button" data-sort="outstanding_balance" class="inline-flex min-h-11 items-center rounded-lg text-left hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                  Outstanding <span aria-hidden="true" class="ml-1 text-slate-400">↕</span>
                </button>
              </th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Receive payment</th>
            </tr>
          </thead>
          <tbody id="tenant-table-body" class="divide-y divide-slate-100" aria-live="polite">
            <?php require __DIR__ . '/partials/payment_tenant_rows.php'; ?>
          </tbody>
        </table>
      </div>

      <div class="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-600">Page <span id="currentPageLabel" class="font-semibold"><?= (int)$page ?></span></p>
        <div class="flex items-center gap-2">
          <button id="previousPage" type="button" <?= $page <= 1 ? 'disabled' : '' ?>
                  class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:cursor-not-allowed disabled:opacity-50">
            Previous
          </button>
          <button id="nextPage" type="button" <?= !$hasNext ? 'disabled' : '' ?>
                  class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:cursor-not-allowed disabled:opacity-50">
            Next
          </button>
        </div>
      </div>
    </section>
  </main>

  <script>
    (() => {
      const filterForm = document.getElementById('paymentFilters');
      const searchInput = document.getElementById('search');
      const roomTypeInput = document.getElementById('room_type');
      const paymentStatusInput = document.getElementById('payment_status');
      const tenantTableBody = document.getElementById('tenant-table-body');
      const previousPageButton = document.getElementById('previousPage');
      const nextPageButton = document.getElementById('nextPage');
      const currentPageLabel = document.getElementById('currentPageLabel');
      const loadingStatus = document.getElementById('paymentLoadingStatus');

      let currentPage = <?= (int)$page ?>;
      let sortBy = <?= json_encode($sort_by, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      let sortOrder = <?= json_encode($sort_order) ?>;
      const selectedTenantId = <?= (int)$tenantId ?>;
      let hasNext = <?= $hasNext ? 'true' : 'false' ?>;
      let requestController = null;
      let requestSequence = 0;
      let searchTimer = null;

      const buildParams = (page) => new URLSearchParams({
        q: searchInput.value,
        room_type: roomTypeInput.value,
        tenant_status: 'active',
        payment_status: paymentStatusInput.value,
        tenant_id: selectedTenantId > 0 ? String(selectedTenantId) : '',
        page: String(page),
        sort_by: sortBy,
        sort_order: sortOrder
      });

      const updateAddress = (params) => {
        const url = new URL(window.location.href);
        url.search = params.toString();
        window.history.replaceState({}, '', url);
      };

      const updateControls = () => {
        currentPageLabel.textContent = String(currentPage);
        previousPageButton.disabled = currentPage <= 1;
        nextPageButton.disabled = !hasNext;

        document.querySelectorAll('[data-sort-header]').forEach((header) => {
          const column = header.getAttribute('data-sort-header');
          header.setAttribute('aria-sort', column === sortBy
            ? (sortOrder === 'DESC' ? 'descending' : 'ascending')
            : 'none');
        });
      };

      const showRequestError = () => {
        tenantTableBody.innerHTML = '<tr><td colspan="6" class="px-4 py-6"><div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-4 text-sm text-red-800">Unable to refresh the tenant roster. Please try again.</div></td></tr>';
      };

      const loadTenants = async (page = 1, updateUrl = true) => {
        const targetPage = Math.max(1, page);
        const params = buildParams(targetPage);
        const sequence = ++requestSequence;

        if (requestController) {
          requestController.abort();
        }
        requestController = new AbortController();
        tenantTableBody.setAttribute('aria-busy', 'true');
        loadingStatus.textContent = 'Refreshing…';

        try {
          const response = await fetch('payments_ajax.php?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: requestController.signal
          });

          if (!response.ok) {
            throw new Error('Payment roster request failed.');
          }

          const html = await response.text();
          if (sequence !== requestSequence) {
            return;
          }

          tenantTableBody.innerHTML = html;
          currentPage = Number(response.headers.get('X-Page')) || targetPage;
          hasNext = response.headers.get('X-Has-Next') === '1';
          updateControls();
          if (updateUrl) {
            updateAddress(params);
          }
        } catch (error) {
          if (error && error.name === 'AbortError') {
            return;
          }
          showRequestError();
        } finally {
          if (sequence === requestSequence) {
            tenantTableBody.removeAttribute('aria-busy');
            loadingStatus.textContent = '';
          }
        }
      };

      const sortColumn = (column) => {
        sortOrder = sortBy === column && sortOrder === 'ASC' ? 'DESC' : 'ASC';
        sortBy = column;
        loadTenants(currentPage);
      };

      filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        loadTenants(1);
      });

      roomTypeInput.addEventListener('change', () => loadTenants(1));
      paymentStatusInput.addEventListener('change', () => loadTenants(1));
      searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => loadTenants(1), 250);
      });

      document.querySelectorAll('[data-sort]').forEach((button) => {
        button.addEventListener('click', () => sortColumn(button.getAttribute('data-sort')));
      });

      previousPageButton.addEventListener('click', () => {
        if (currentPage > 1) loadTenants(currentPage - 1);
      });
      nextPageButton.addEventListener('click', () => {
        if (hasNext) loadTenants(currentPage + 1);
      });

      tenantTableBody.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-payment-form]');
        if (!form) return;

        const button = form.querySelector('[data-payment-submit]');
        if (button) {
          button.disabled = true;
          button.textContent = button.dataset.submittingLabel || 'Saving…';
        }
      });

      window.loadTenants = loadTenants;
      window.sortColumn = sortColumn;
      updateControls();
    })();
  </script>
</body>
</html>
