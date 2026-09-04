<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/PaymentService.php';

require_login();

$pdo = getDB();
$adminId = current_admin_id();
if ($adminId <= 0) {
    http_response_code(403);
    exit('Access denied.');
}

$paymentService = new PaymentService($pdo);
$allowedStatuses = ['all', 'pending', 'confirmed', 'done'];
$getString = static function (string $key, string $default = ''): string {
    $value = $_GET[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
};
$redirectState = static function (string $status, string $search, int $paymentId = 0): string {
    $query = array_filter([
        'status' => $status !== 'all' ? $status : '',
        'q' => $search,
        'payment_id' => $paymentId > 0 ? $paymentId : '',
    ], static fn($value): bool => $value !== '' && $value !== null);

    return 'payment_verification.php' . ($query ? '?' . http_build_query($query) : '');
};

$status = strtolower($getString('status', 'all'));
$status = in_array($status, $allowedStatuses, true) ? $status : 'all';
$search = $getString('q');
$selectedId = max(0, (int)$getString('payment_id', '0'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postStatus = strtolower(is_scalar($_POST['status'] ?? null) ? (string)$_POST['status'] : 'all');
    $postStatus = in_array($postStatus, $allowedStatuses, true) ? $postStatus : 'all';
    $postSearch = is_scalar($_POST['q'] ?? null) ? trim((string)$_POST['q']) : '';
    $postPaymentId = is_scalar($_POST['payment_id'] ?? null) ? (int)$_POST['payment_id'] : 0;
    $action = is_scalar($_POST['action'] ?? null) ? strtolower(trim((string)$_POST['action'])) : '';

    try {
        $csrfValue = $_POST['csrf'] ?? '';
        verify_csrf(is_scalar($csrfValue) ? (string)$csrfValue : '');

        if ($postPaymentId <= 0 || !in_array($action, ['confirm', 'done'], true)) {
            throw new RuntimeException('The verification action is not available.');
        }

        if ($action === 'confirm') {
            $submittedCode = is_scalar($_POST['verification_code'] ?? null)
                ? trim((string)$_POST['verification_code'])
                : '';
            $_SESSION['verification_code'] = $submittedCode;
            $paymentService->confirmPayment($postPaymentId, $adminId, $submittedCode);
            unset($_SESSION['verification_code']);
            $_SESSION['success'] = 'Payment confirmed. It is ready to be marked done.';
        } else {
            $paymentService->markPaymentDone($postPaymentId, $adminId);
            $_SESSION['success'] = 'Payment verification marked done.';
        }
    } catch (Throwable $e) {
        $_SESSION['verification_error'] = $e->getMessage();
    }

    redirect($redirectState($postStatus, $postSearch, $postPaymentId));
}

$success = is_scalar($_SESSION['success'] ?? null) ? trim((string)$_SESSION['success']) : '';
$error = is_scalar($_SESSION['verification_error'] ?? null) ? trim((string)$_SESSION['verification_error']) : '';
$verificationCode = is_scalar($_SESSION['verification_code'] ?? null) ? (string)$_SESSION['verification_code'] : '';
unset($_SESSION['success'], $_SESSION['verification_error'], $_SESSION['verification_code']);

$dailyCode = '';
$dailyCodeError = '';
try {
    $dailyCode = $paymentService->getDailyVerificationCode();
} catch (Throwable $e) {
    $dailyCodeError = 'The daily verification code is not configured.';
}

try {
    $queue = $paymentService->getVerificationQueue($adminId, $status, $search);
    $selectedPayment = $selectedId > 0
        ? $paymentService->getVerificationPayment($selectedId, $adminId)
        : null;
} catch (Throwable $e) {
    $queue = [];
    $selectedPayment = null;
    $error = $error !== '' ? $error : 'Unable to refresh the verification queue. Try again.';
}

$groupOrder = ['pending', 'confirmed', 'done'];
$queueGroups = [];
foreach ($groupOrder as $groupStatus) {
    if ($status !== 'all' && $status !== $groupStatus) {
        continue;
    }

    $queueGroups[$groupStatus] = array_values(array_filter(
        $queue,
        static fn(array $payment): bool => strtolower((string)($payment['verification_status'] ?? 'done')) === $groupStatus
    ));
}

$money = static fn(float $amount): string => 'UGX ' . number_format($amount, 0, '.', ',');
$monthLabel = static function (string $month): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
    return $date ? $date->format('F Y') : $month;
};
$verificationLabel = static function (string $value): string {
    return match (strtolower($value)) {
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'done' => 'Done',
        default => ucfirst($value),
    };
};
$verificationClasses = static function (string $value): string {
    return match (strtolower($value)) {
        'pending' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'confirmed' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'done' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
};
$paymentStatusLabel = static function (string $value): string {
    return match (strtolower($value)) {
        'paid' => 'Paid',
        'partial' => 'Partial',
        'unpaid' => 'Unpaid',
        default => ucfirst($value),
    };
};
$formatEventTime = static function (?string $value): string {
    if (!$value) {
        return '—';
    }

    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Africa/Kampala'))
            ->format('d M Y, H:i') . ' EAT';
    } catch (Throwable $ignored) {
        return '—';
    }
};
$pendingCount = count($queueGroups['pending'] ?? []);
$confirmedCount = count($queueGroups['confirmed'] ?? []);
$doneCount = count($queueGroups['done'] ?? []);
$active = 'payments';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verification Queue · Payments</title>
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <?php require __DIR__ . '/partials/navbar.php'; ?>

  <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Payments / Verification queue</p>
        <h1 class="mt-2 font-alt text-2xl font-bold tracking-tight">Verification queue</h1>
        <p class="mt-1 text-sm text-slate-600">Review recorded payments with today’s verification code.</p>
      </div>
      <div class="rounded-xl bg-slate-900 px-4 py-3 text-white shadow-sm">
        <p class="text-xs font-semibold text-slate-300">Today’s code</p>
        <?php if ($dailyCode): ?>
          <p class="mt-1 font-alt text-2xl font-bold tracking-[0.2em] tabular-nums"><?= e($dailyCode) ?></p>
          <p class="mt-1 text-xs text-slate-300">Valid until midnight EAT</p>
        <?php else: ?>
          <p class="mt-1 text-sm font-semibold text-amber-200">Unavailable</p>
          <p class="mt-1 text-xs text-slate-300">Set the server secret to enable confirmation.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status" aria-live="polite"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
        <p class="font-semibold"><?= e(str_contains($error, 'verification code') ? 'The code could not be confirmed' : $error) ?></p>
        <?php if (str_contains($error, 'verification code')): ?><p class="mt-1">Check today’s code and try again.</p><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($dailyCodeError): ?>
      <div class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert"><?= e($dailyCodeError) ?></div>
    <?php endif; ?>

    <section id="verification-workspace" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="grid min-h-[640px] grid-cols-1 lg:grid-cols-12">
        <aside id="queue" class="<?= $selectedPayment ? 'hidden lg:block' : '' ?> border-b border-slate-200 lg:col-span-5 lg:border-b-0 lg:border-r" aria-labelledby="queue-title">
          <div class="border-b border-slate-200 p-4 sm:p-5">
            <div class="flex items-center justify-between gap-3">
              <div>
                <h2 id="queue-title" class="font-alt text-lg font-bold">Verification queue</h2>
                <p class="mt-1 text-xs text-slate-500"><?= (int)$pendingCount ?> pending · <?= (int)$confirmedCount ?> confirmed · <?= (int)$doneCount ?> done</p>
              </div>
              <?php if ($search !== '' || $status !== 'all'): ?>
                <a href="payment_verification.php" class="text-xs font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-900">Clear filters</a>
              <?php endif; ?>
            </div>
            <form method="get" class="mt-4 space-y-3">
              <label for="verification-search" class="sr-only">Tenant or room</label>
              <input id="verification-search" name="q" value="<?= e($search) ?>" placeholder="Tenant or room" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
              <?php if ($selectedId > 0): ?><input type="hidden" name="payment_id" value="<?= (int)$selectedId ?>"><?php endif; ?>
              <button type="submit" class="min-h-11 w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">Search queue</button>
            </form>
            <nav class="mt-4 grid grid-cols-4 gap-1 rounded-xl bg-slate-100 p-1" aria-label="Verification status filter">
              <?php foreach ($allowedStatuses as $filterStatus): ?>
                <?php $filterUrl = $redirectState($filterStatus, $search); ?>
                <a href="<?= e($filterUrl) ?>" class="inline-flex min-h-11 items-center justify-center rounded-lg px-2 text-xs font-semibold <?= $status === $filterStatus ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600 hover:text-slate-900' ?>" <?= $status === $filterStatus ? 'aria-current="page"' : '' ?>>
                  <?= e($filterStatus === 'all' ? 'All' : $verificationLabel($filterStatus)) ?>
                </a>
              <?php endforeach; ?>
            </nav>
          </div>

          <div class="max-h-[720px] overflow-y-auto p-2 sm:p-3">
            <?php $hasQueueItems = false; ?>
            <?php foreach ($queueGroups as $groupStatus => $groupItems): ?>
              <?php if (!$groupItems) continue; $hasQueueItems = true; ?>
              <div class="mb-4 last:mb-0">
                <div class="flex items-center justify-between px-2 py-2">
                  <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500"><?= e($verificationLabel($groupStatus)) ?></h3>
                  <span class="text-xs font-semibold text-slate-400"><?= count($groupItems) ?></span>
                </div>
                <div class="space-y-1">
                  <?php foreach ($groupItems as $payment): ?>
                    <?php
                      $paymentId = (int)$payment['id'];
                      $paymentStatus = strtolower((string)($payment['verification_status'] ?? 'done'));
                      $isSelected = $selectedPayment && (int)($selectedPayment['id'] ?? 0) === $paymentId;
                      $itemUrl = $redirectState($status, $search, $paymentId);
                    ?>
                    <a href="<?= e($itemUrl) ?>" class="block min-h-11 rounded-xl border px-3 py-3 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 <?= $isSelected ? 'border-slate-900 bg-slate-900 text-white' : 'border-transparent hover:border-slate-200 hover:bg-slate-50' ?>">
                      <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                          <p class="truncate text-sm font-semibold"><?= e($payment['full_name'] ?? 'Unknown tenant') ?></p>
                          <p class="mt-1 truncate text-xs <?= $isSelected ? 'text-slate-300' : 'text-slate-500' ?>">Room <?= e($payment['room_number'] ?? '—') ?> · <?= e($monthLabel((string)$payment['payment_month'])) ?></p>
                        </div>
                        <p class="shrink-0 font-alt text-sm font-bold tabular-nums"><?= e($money((float)$payment['amount'])) ?></p>
                      </div>
                      <div class="mt-2 flex items-center justify-between gap-2">
                        <span class="text-xs <?= $isSelected ? 'text-slate-300' : 'text-slate-500' ?>"><?= e($payment['payment_date'] ?? '—') ?></span>
                        <span class="inline-flex items-center rounded-full px-2 py-1 text-[11px] font-semibold ring-1 ring-inset <?= $isSelected ? 'bg-white/10 text-white ring-white/20' : e($verificationClasses($paymentStatus)) ?>"><?= e($verificationLabel($paymentStatus)) ?></span>
                      </div>
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (!$hasQueueItems): ?>
              <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-700">
                <p class="font-semibold text-slate-900"><?= $search !== '' || $status !== 'all' ? 'No matching payments' : 'No payments need verification' ?></p>
                <p class="mt-1"><?= $search !== '' || $status !== 'all' ? 'Try another search or clear the filters.' : 'New received payments will appear here as Pending.' ?></p>
              </div>
            <?php endif; ?>
          </div>
        </aside>

        <section class="<?= $selectedPayment ? '' : 'hidden lg:flex' ?> flex-col lg:col-span-7" aria-labelledby="detail-title">
          <?php if (!$selectedPayment): ?>
            <div class="flex min-h-[640px] flex-col items-center justify-center px-6 text-center">
              <div class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-xl text-slate-400" aria-hidden="true">—</div>
              <h2 id="detail-title" class="mt-4 font-alt text-lg font-bold">Select a payment</h2>
              <p class="mt-1 max-w-xs text-sm leading-6 text-slate-600">Choose a record from the queue to review its details.</p>
            </div>
          <?php else: ?>
            <?php
              $detailStatus = strtolower((string)($selectedPayment['verification_status'] ?? 'done'));
              $detailTenantStatus = strtolower((string)($selectedPayment['tenant_status'] ?? 'active'));
            ?>
            <div class="flex min-h-14 items-center justify-between border-b border-slate-200 px-4 py-3 sm:px-6">
              <a href="<?= e($redirectState($status, $search)) ?>" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 lg:hidden">Back to queue</a>
              <div class="ml-auto flex items-center gap-2">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?= e($verificationClasses($detailStatus)) ?>"><?= e($verificationLabel($detailStatus)) ?></span>
              </div>
            </div>
            <div class="flex-1 px-4 py-5 sm:px-6 sm:py-6">
              <h2 id="detail-title" class="font-alt text-xl font-bold">Payment details</h2>
              <div class="mt-5 grid grid-cols-2 gap-x-5 gap-y-5 text-sm sm:grid-cols-3">
                <div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tenant</p><p class="mt-1 font-semibold text-slate-900"><?= e($selectedPayment['full_name'] ?? '—') ?></p></div>
                <div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Room</p><p class="mt-1 text-slate-900"><?= e($selectedPayment['room_number'] ?? '—') ?></p></div>
                <div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Room type</p><p class="mt-1 text-slate-900"><?= e($selectedPayment['room_type'] ?? '—') ?></p></div>
                <div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Payment month</p><p class="mt-1 text-slate-900"><?= e($monthLabel((string)$selectedPayment['payment_month'])) ?></p></div>
                <div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Payment date</p><p class="mt-1 text-slate-900"><?= e($selectedPayment['payment_date'] ?? '—') ?></p></div>
                <div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Method</p><p class="mt-1 text-slate-900"><?= e(ucfirst((string)($selectedPayment['method'] ?? '—'))) ?></p></div>
                <div class="col-span-2 sm:col-span-3"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Amount</p><p class="mt-1 font-alt text-3xl font-bold tabular-nums text-slate-900"><?= e($money((float)$selectedPayment['amount'])) ?></p></div>
                <?php if (trim((string)($selectedPayment['note'] ?? '')) !== ''): ?><div class="col-span-2 sm:col-span-3"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Note</p><p class="mt-1 text-slate-700"><?= e($selectedPayment['note']) ?></p></div><?php endif; ?>
              </div>

              <div class="my-6 h-px bg-slate-200"></div>
              <h3 class="font-alt text-lg font-bold">Monthly position</h3>
              <dl class="mt-4 grid grid-cols-2 gap-x-5 gap-y-4 text-sm sm:grid-cols-4">
                <div><dt class="text-xs text-slate-500">Effective rent</dt><dd class="mt-1 font-semibold tabular-nums text-slate-900"><?= e($money((float)$selectedPayment['effective_rent'])) ?></dd></div>
                <div><dt class="text-xs text-slate-500">Amount recorded</dt><dd class="mt-1 font-semibold tabular-nums text-slate-900"><?= e($money((float)$selectedPayment['month_paid'])) ?></dd></div>
                <div><dt class="text-xs text-slate-500">Remaining balance</dt><dd class="mt-1 font-semibold tabular-nums <?= ((float)$selectedPayment['month_balance'] > 0) ? 'text-red-700' : 'text-emerald-700' ?>"><?= e($money((float)$selectedPayment['month_balance'])) ?></dd></div>
                <div><dt class="text-xs text-slate-500">Month result</dt><dd class="mt-1 font-semibold text-slate-900"><?= e($paymentStatusLabel((string)$selectedPayment['month_status'])) ?></dd></div>
              </dl>

              <div class="my-6 h-px bg-slate-200"></div>
              <h3 class="font-alt text-lg font-bold">Verification</h3>
              <div class="mt-4 flex flex-col gap-2 text-sm sm:flex-row sm:items-center sm:justify-between">
                <span class="text-slate-600">Current status</span>
                <span class="inline-flex w-fit items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?= e($verificationClasses($detailStatus)) ?>"><?= e($verificationLabel($detailStatus)) ?></span>
              </div>
              <?php if ($detailStatus === 'confirmed'): ?><p class="mt-3 text-sm text-slate-600">Confirmed on <?= e($formatEventTime($selectedPayment['verification_confirmed_at'] ?? null)) ?>.</p><?php endif; ?>
              <?php if ($detailStatus === 'done'): ?><p class="mt-3 text-sm text-slate-600">Verification complete<?= !empty($selectedPayment['verification_done_at']) ? ' on ' . e($formatEventTime($selectedPayment['verification_done_at'])) : '' ?>.</p><?php endif; ?>
            </div>

            <?php if ($detailStatus === 'pending'): ?>
              <div class="sticky bottom-0 border-t border-slate-200 bg-white px-4 py-4 sm:px-6 lg:static">
                <form method="post" class="space-y-3">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="confirm">
                  <input type="hidden" name="payment_id" value="<?= (int)$selectedPayment['id'] ?>">
                  <input type="hidden" name="status" value="<?= e($status) ?>">
                  <input type="hidden" name="q" value="<?= e($search) ?>">
                  <label for="verification_code" class="block text-sm font-semibold text-slate-800">Today’s verification code</label>
                  <div class="flex flex-col gap-3 sm:flex-row">
                    <input id="verification_code" name="verification_code" value="<?= e($verificationCode) ?>" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{4}" maxlength="4" placeholder="4 digits" required class="min-h-11 w-full rounded-xl border <?= $error && str_contains($error, 'verification code') ? 'border-red-400 ring-2 ring-red-100' : 'border-slate-300' ?> bg-white px-3 py-2 text-sm tracking-[0.2em] outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200 sm:max-w-xs" aria-describedby="verification-hint <?= $error && str_contains($error, 'verification code') ? 'verification-code-error' : '' ?>">
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">Confirm payment</button>
                  </div>
                  <p id="verification-hint" class="text-xs text-slate-500">Use today’s 4-digit code. The payment stays Pending if the code does not match.</p>
                  <?php if ($error && str_contains($error, 'verification code')): ?><p id="verification-code-error" class="text-sm font-medium text-red-700" role="alert">Check today’s code and try again.</p><?php endif; ?>
                </form>
              </div>
            <?php elseif ($detailStatus === 'confirmed'): ?>
              <div class="flex flex-col gap-3 border-t border-slate-200 bg-white px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <p class="text-sm text-slate-600">The code has been confirmed.</p>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="done">
                  <input type="hidden" name="payment_id" value="<?= (int)$selectedPayment['id'] ?>">
                  <input type="hidden" name="status" value="<?= e($status) ?>">
                  <input type="hidden" name="q" value="<?= e($search) ?>">
                  <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 sm:w-auto">Mark done</button>
                </form>
              </div>
            <?php else: ?>
              <div class="border-t border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-600 sm:px-6">Verification complete.</div>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      </div>
    </section>
  </main>
</body>
</html>
