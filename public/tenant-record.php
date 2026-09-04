<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/TenantPortalService.php';

header('Cache-Control: no-store, private');

$accessValue = $_GET['access'] ?? '';
$accessToken = is_scalar($accessValue) ? trim((string)$accessValue) : '';
$portalService = new TenantPortalService(getDB());
$record = null;

try {
    $record = $portalService->resolveAccess($accessToken);
} catch (Throwable $ignored) {
    // Public access failures must not disclose database or token details.
    $record = null;
}

$money = static fn(float $amount): string => 'UGX ' . number_format($amount, 0, '.', ',');
$monthLabel = static function (string $month): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
    return $date ? $date->format('F Y') : $month;
};
$dateLabel = static function (?string $date): string {
    if (!$date) {
        return '—';
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed ? $parsed->format('d M Y') : '—';
};
$statusLabel = static function (string $status): string {
    return match (strtolower($status)) {
        'paid' => 'Paid',
        'partial' => 'Partial',
        'unpaid' => 'Unpaid',
        'active' => 'Active',
        'exited' => 'Exited',
        default => ucfirst($status),
    };
};
$statusClasses = static function (string $status): string {
    return match (strtolower($status)) {
        'paid', 'active' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'partial' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'unpaid' => 'bg-red-50 text-red-700 ring-red-200',
        'exited' => 'bg-slate-100 text-slate-700 ring-slate-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
};

$activeRecord = is_array($record);
$tenant = $activeRecord ? ($record['tenant'] ?? []) : [];
$currentMonth = $activeRecord ? ($record['current_month'] ?? []) : [];
$expiringSoon = false;
$expiresLabel = '';

if ($activeRecord && !empty($record['access_expires_at'])) {
    try {
        $expiry = new DateTimeImmutable((string)$record['access_expires_at'], new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('now', new DateTimeZone('Africa/Kampala'));
        $expiryLocal = $expiry->setTimezone(new DateTimeZone('Africa/Kampala'));
        $expiringSoon = $expiryLocal <= $now->modify('+48 hours');
        $expiresLabel = $expiryLocal->format('d M Y, H:i') . ' EAT';
    } catch (Throwable $ignored) {
        $activeRecord = false;
    }
}

if (!$activeRecord) {
    $pageTitle = 'Tenant record access';
} else {
    $pageTitle = 'Tenancy record';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <title><?= e($pageTitle) ?> · Urbahan</title>
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex min-h-14 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
      <div class="flex items-center gap-3">
        <span class="font-alt text-lg font-bold tracking-tight text-slate-900">Urbahan</span>
        <span class="hidden h-5 w-px bg-slate-200 sm:block" aria-hidden="true"></span>
        <span class="text-sm font-semibold text-slate-600">Tenant record</span>
      </div>
      <span class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-slate-600">
        <span class="text-slate-500" aria-hidden="true">●</span>
        Read-only
      </span>
    </div>
  </header>

  <?php if (!$activeRecord): ?>
    <main class="mx-auto flex min-h-[calc(100vh-3.5rem)] max-w-2xl items-start px-4 py-12 sm:items-center sm:px-6 lg:px-8">
      <section class="w-full rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm sm:p-10" aria-labelledby="access-error-title">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-xl text-slate-500" aria-hidden="true">—</div>
        <h1 id="access-error-title" class="mt-5 font-alt text-2xl font-bold tracking-tight">This link is not available</h1>
        <p class="mx-auto mt-3 max-w-md text-sm leading-6 text-slate-600">
          It may have expired or been replaced. Contact your property manager for a new link.
        </p>
        <p class="mt-6 text-xs text-slate-500">For privacy, tenancy records are not shown when access cannot be verified.</p>
      </section>
    </main>
  <?php else: ?>
    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
      <section class="mb-6 border-t-4 <?= $expiringSoon ? 'border-amber-500' : 'border-slate-900' ?> rounded-2xl border-x border-b border-slate-200 bg-white px-4 py-4 shadow-sm sm:px-5" aria-labelledby="access-strip-title">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 id="access-strip-title" class="text-sm font-bold text-slate-900">Private tenancy record</h1>
            <p class="mt-1 text-sm leading-6 text-slate-600">This link shows your rental record only. It cannot be used to make changes.</p>
          </div>
          <div class="shrink-0 text-left sm:text-right">
            <p class="text-sm font-semibold <?= $expiringSoon ? 'text-amber-800' : 'text-slate-700' ?>">Expires <?= e($expiresLabel) ?></p>
            <?php if ($expiringSoon): ?>
              <p class="mt-1 text-xs text-amber-700">Save the details you need before it expires.</p>
            <?php else: ?>
              <p class="mt-1 text-xs text-slate-500">Do not forward this link.</p>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <section class="lg:col-span-7" aria-labelledby="balance-title">
          <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <p class="text-sm font-semibold text-slate-600">Current balance</p>
                <p class="mt-3 font-alt text-4xl font-bold tabular-nums tracking-tight text-slate-900" id="balance-title">
                  <?= e($money((float)($currentMonth['balance'] ?? 0))) ?>
                </p>
              </div>
              <?php $currentStatus = (string)($currentMonth['payment_status'] ?? 'unpaid'); ?>
              <span class="inline-flex w-fit items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?= e($statusClasses($currentStatus)) ?>">
                <?= e($statusLabel($currentStatus)) ?>
              </span>
            </div>
            <p class="mt-3 text-sm text-slate-600">
              Rent due <?= e($dateLabel($tenant['rent_due_date'] ?? null)) ?> · <?= e($money((float)($currentMonth['rent'] ?? 0))) ?> for <?= e($monthLabel((string)($currentMonth['month'] ?? ''))) ?>
            </p>
            <div class="my-5 h-px bg-slate-200"></div>
            <div class="flex flex-col gap-2 text-sm sm:flex-row sm:items-center sm:justify-between">
              <span class="text-slate-600">Paid this month <strong class="font-semibold text-slate-900"><?= e($money((float)($currentMonth['paid'] ?? 0))) ?></strong></span>
              <span class="text-slate-600">Remaining <strong class="font-semibold <?= ((float)($currentMonth['balance'] ?? 0) > 0) ? 'text-slate-900' : 'text-emerald-700' ?>"><?= e($money((float)($currentMonth['balance'] ?? 0))) ?></strong></span>
            </div>
          </div>
        </section>

        <section class="lg:col-span-5" aria-labelledby="tenancy-title">
          <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 id="tenancy-title" class="font-alt text-lg font-bold">Your tenancy</h2>
            <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-5">
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tenant</dt>
                <dd class="mt-1 text-sm font-semibold text-slate-900"><?= e($tenant['full_name'] ?? '—') ?></dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Room</dt>
                <dd class="mt-1 text-sm font-semibold text-slate-900"><?= e($tenant['room_number'] ?? '—') ?></dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Room type</dt>
                <dd class="mt-1 text-sm text-slate-900"><?= e($tenant['room_type'] ?? '—') ?></dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Monthly rent</dt>
                <dd class="mt-1 text-sm font-semibold tabular-nums text-slate-900"><?= e($money((float)($tenant['effective_rent'] ?? 0))) ?></dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rent due</dt>
                <dd class="mt-1 text-sm text-slate-900"><?= e($dateLabel($tenant['rent_due_date'] ?? null)) ?></dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tenancy status</dt>
                <dd class="mt-1">
                  <?php $tenantStatus = (string)($tenant['tenant_status'] ?? 'active'); ?>
                  <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?= e($statusClasses($tenantStatus)) ?>"><?= e($statusLabel($tenantStatus)) ?></span>
                </dd>
              </div>
            </dl>
          </div>
        </section>
      </div>

      <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="history-title">
        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
          <h2 id="history-title" class="font-alt text-lg font-bold">Payment history</h2>
          <p class="mt-1 text-sm text-slate-600">Payments are listed by month, newest first.</p>
        </div>
        <div class="p-5 sm:p-6">
          <?php $portalMonths = $record['months'] ?? []; require __DIR__ . '/partials/portal_payment_ledger.php'; ?>
        </div>
      </section>

      <section class="mt-6 border-t border-slate-200 pt-5 text-sm leading-6 text-slate-600" aria-labelledby="verification-title">
        <h2 id="verification-title" class="font-semibold text-slate-900">How verification works</h2>
        <p class="mt-1">Pending means a payment has been recorded and is being checked. Confirmed means the daily code matched. Done means the check is complete.</p>
      </section>

      <footer class="mt-6 border-t border-slate-200 pt-5 text-sm leading-6 text-slate-600">
        If a payment or balance looks incorrect, contact your property manager. If this link has expired, request a new link.
      </footer>
    </main>
  <?php endif; ?>
  <script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form[method="POST"]');

    if (!form) return;

    const submitButton = form.querySelector('button[type="submit"]');

    if (!submitButton) return;

    form.addEventListener('submit', function () {
        // Prevent double-clicking / duplicate SMS
        if (form.dataset.submitting === 'true') {
            return;
        }

        form.dataset.submitting = 'true';

        // Save original button content
        submitButton.dataset.originalText = submitButton.innerHTML;

        // Show sending state
        submitButton.disabled = true;
        submitButton.classList.add('opacity-75', 'cursor-not-allowed');

        submitButton.innerHTML = `
            <span class="inline-flex items-center gap-2">
                <svg
                    class="h-4 w-4 animate-spin"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle
                        class="opacity-25"
                        cx="12"
                        cy="12"
                        r="10"
                        stroke="currentColor"
                        stroke-width="4"
                    ></circle>
                    <path
                        class="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                    ></path>
                </svg>

                Sending SMS...
            </span>
        `;
    });
});
</script>
</body>
</html>
