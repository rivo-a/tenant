<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/TenantService.php';

require_login();
requireCaretaker();

$pdo = getDB();
$adminId = current_admin_id();
$tenantService = new TenantService($pdo);

$readScalar = static function (array $source, string $key, string $default = ''): string {
    $value = $source[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
};
$validateDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    $hasErrors = $errors !== false && (
        $errors['warning_count'] > 0 ||
        $errors['error_count'] > 0
    );

    return $date !== false && !$hasErrors && $date->format('Y-m-d') === $value;
};

$tenantIdValue = $_GET['id'] ?? $_POST['tenant_id'] ?? 0;
$tenantId = is_scalar($tenantIdValue) ? filter_var($tenantIdValue, FILTER_VALIDATE_INT) : false;
$tenantId = $tenantId === false ? 0 : (int)$tenantId;

if ($tenantId <= 0) {
    http_response_code(400);
    exit('Invalid tenant ID.');
}

try {
    $tenant = $tenantService->getTenant($tenantId, $adminId);
} catch (Throwable $e) {
    http_response_code(404);
    exit('Tenant not found or you do not have permission to edit this tenant.');
}

$form = [
    'full_name' => (string)($tenant['full_name'] ?? ''),
    'phone' => (string)($tenant['phone'] ?? ''),
    'email' => (string)($tenant['email'] ?? ''),
    'move_in_date' => (string)($tenant['move_in_date'] ?? ''),
];
$fieldErrors = [];
$error = '';
$success = is_scalar($_SESSION['success'] ?? null) ? trim((string)$_SESSION['success']) : '';
unset($_SESSION['success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrfValue = $_POST['csrf'] ?? '';
        verify_csrf(is_scalar($csrfValue) ? (string)$csrfValue : '');

        $form['full_name'] = $readScalar($_POST, 'full_name');
        $form['phone'] = $readScalar($_POST, 'phone');
        $form['email'] = $readScalar($_POST, 'email');
        $form['move_in_date'] = $readScalar($_POST, 'move_in_date');

        if ($form['full_name'] === '') {
            $fieldErrors['full_name'] = 'Tenant name is required.';
        }

        if (
            $form['email'] !== '' &&
            filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            $fieldErrors['email'] = 'Please enter a valid email address.';
        }

        if (!$validateDate($form['move_in_date'])) {
            $fieldErrors['move_in_date'] = 'Please enter a valid move-in date.';
        }

        if ($fieldErrors) {
            throw new RuntimeException('Please correct the highlighted fields.');
        }

        $tenantService->updateTenant($tenantId, $form, $adminId);
        $_SESSION['success'] = 'Tenant details updated successfully.';
        redirect('tenants.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$tenantStatus = strtolower((string)($tenant['status'] ?? 'active'));
$rent = (float)($tenant['monthly_rent'] ?? 0);
$roomNumber = (string)($tenant['room_number'] ?? '—');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Edit tenant · <?= e($tenant['full_name'] ?? '') ?></title>
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
  <?php
  $active = 'tenants';
  require __DIR__ . '/partials/navbar.php';
  ?>

  <main class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
    <header class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tenant management</p>
        <h1 class="mt-2 font-alt text-2xl font-bold tracking-tight">Edit tenant</h1>
        <p class="mt-1 text-sm text-slate-600">Update contact details and the tenancy start date.</p>
      </div>
      <a href="tenants.php" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">Back to tenants</a>
    </header>

    <?php if ($success): ?>
      <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div id="tenantEditSummary" class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
        <p class="font-semibold"><?= e($error) ?></p>
        <?php if ($fieldErrors): ?>
          <ul class="mt-2 list-disc space-y-1 pl-5">
            <?php foreach ($fieldErrors as $fieldError): ?><li><?= e($fieldError) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 lg:col-span-2" aria-labelledby="tenant-summary-title">
        <h2 id="tenant-summary-title" class="font-alt text-lg font-bold">Tenant summary</h2>
        <dl class="mt-5 space-y-4">
          <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Name</dt>
            <dd class="mt-1 font-semibold text-slate-900"><?= e($tenant['full_name'] ?? '—') ?></dd>
          </div>
          <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Room</dt>
            <dd class="mt-1 text-slate-900"><?= e($roomNumber) ?></dd>
          </div>
          <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Monthly rent</dt>
            <dd class="mt-1 font-semibold tabular-nums text-slate-900">UGX <?= e(number_format($rent, 0)) ?></dd>
          </div>
          <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</dt>
            <dd class="mt-1">
              <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?= $tenantStatus === 'active' ? 'bg-sky-50 text-sky-700 ring-sky-200' : 'bg-slate-100 text-slate-700 ring-slate-200' ?>"><?= e(ucfirst($tenantStatus)) ?></span>
            </dd>
          </div>
        </dl>
        <p class="mt-6 border-t border-slate-100 pt-4 text-xs leading-5 text-slate-500">Room assignment and monthly rent are shown for reference. Change those from the room or tenancy workflow.</p>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 lg:col-span-3" aria-labelledby="tenant-details-title">
        <form method="post" action="tenant_edit.php?id=<?= (int)$tenantId ?>" <?= $error ? 'aria-describedby="tenantEditSummary"' : '' ?>>
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="tenant_id" value="<?= (int)$tenantId ?>">

          <fieldset>
            <legend id="tenant-details-title" class="font-alt text-lg font-bold">Contact details</legend>
            <div class="mt-5 space-y-4">
              <div>
                <label for="full_name" class="block text-sm font-semibold text-slate-700">Full name</label>
                <input id="full_name" name="full_name" type="text" value="<?= e($form['full_name']) ?>" autocomplete="name" required class="mt-2 min-h-11 w-full rounded-xl border <?= isset($fieldErrors['full_name']) ? 'border-red-400' : 'border-slate-300' ?> bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" <?= isset($fieldErrors['full_name']) ? 'aria-invalid="true" aria-describedby="full_name_error"' : '' ?>>
                <?php if (isset($fieldErrors['full_name'])): ?><p id="full_name_error" class="mt-2 text-xs font-medium text-red-700"><?= e($fieldErrors['full_name']) ?></p><?php endif; ?>
              </div>

              <div>
                <label for="phone" class="block text-sm font-semibold text-slate-700">Phone <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="phone" name="phone" type="tel" value="<?= e($form['phone']) ?>" autocomplete="tel" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200">
              </div>

              <div>
                <label for="email" class="block text-sm font-semibold text-slate-700">Email <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="email" name="email" type="email" value="<?= e($form['email']) ?>" autocomplete="email" class="mt-2 min-h-11 w-full rounded-xl border <?= isset($fieldErrors['email']) ? 'border-red-400' : 'border-slate-300' ?> bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" <?= isset($fieldErrors['email']) ? 'aria-invalid="true" aria-describedby="email_error"' : '' ?>>
                <?php if (isset($fieldErrors['email'])): ?><p id="email_error" class="mt-2 text-xs font-medium text-red-700"><?= e($fieldErrors['email']) ?></p><?php endif; ?>
              </div>
            </div>
          </fieldset>

          <fieldset class="mt-6 border-t border-slate-100 pt-6">
            <legend class="font-alt text-lg font-bold">Tenancy date</legend>
            <div class="mt-5">
              <label for="move_in_date" class="block text-sm font-semibold text-slate-700">Move-in date</label>
              <input id="move_in_date" name="move_in_date" type="date" value="<?= e($form['move_in_date']) ?>" required class="mt-2 min-h-11 w-full rounded-xl border <?= isset($fieldErrors['move_in_date']) ? 'border-red-400' : 'border-slate-300' ?> bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200" <?= isset($fieldErrors['move_in_date']) ? 'aria-invalid="true" aria-describedby="move_in_date_error"' : '' ?>>
              <?php if (isset($fieldErrors['move_in_date'])): ?><p id="move_in_date_error" class="mt-2 text-xs font-medium text-red-700"><?= e($fieldErrors['move_in_date']) ?></p><?php endif; ?>
            </div>
          </fieldset>

          <div class="mt-6 flex flex-col-reverse gap-3 border-t border-slate-100 pt-6 sm:flex-row sm:justify-end">
            <a href="tenants.php" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">Cancel</a>
            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">Save changes</button>
          </div>
        </form>
      </section>
    </div>
  </main>
</body>
</html>
