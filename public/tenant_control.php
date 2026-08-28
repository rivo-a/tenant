<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/TenantService.php';

require_login();
requireCaretaker();

$pdo = getDB();
$admin_id = current_admin_id();
$tenantService = new TenantService($pdo);

$form = [
    'full_name'    => '',
    'phone'        => '',
    'email'        => '',
    'move_in_date' => date('Y-m-d'),
    'room_id'      => 0,
];

$success = (string)($_SESSION['success'] ?? '');
unset($_SESSION['success']);
$error = '';
$fieldErrors = [];

$postString = static function (string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrfValue = $_POST['csrf'] ?? '';
        verify_csrf(is_string($csrfValue) ? $csrfValue : '');

        $form['full_name'] = $postString('full_name');
        $form['phone'] = $postString('phone');
        $form['email'] = $postString('email');
        $form['move_in_date'] = $postString('move_in_date', date('Y-m-d'));

        $roomValue = $_POST['room_id'] ?? 0;
        $roomId = is_scalar($roomValue) ? filter_var($roomValue, FILTER_VALIDATE_INT) : false;
        $form['room_id'] = $roomId === false ? 0 : (int)$roomId;

        if ($form['full_name'] === '') {
            $fieldErrors['full_name'] = 'Tenant name is required.';
        }

        if ($form['room_id'] <= 0) {
            $fieldErrors['room_id'] = 'Please select a room.';
        }

        if (
            $form['email'] !== '' &&
            filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            $fieldErrors['email'] = 'Please enter a valid email address.';
        }

        $moveInDate = DateTimeImmutable::createFromFormat('!Y-m-d', $form['move_in_date']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $hasDateErrors = $dateErrors !== false && (
            $dateErrors['warning_count'] > 0 ||
            $dateErrors['error_count'] > 0
        );

        if (
            $moveInDate === false ||
            $hasDateErrors ||
            $moveInDate->format('Y-m-d') !== $form['move_in_date']
        ) {
            $fieldErrors['move_in_date'] = 'Please enter a valid move-in date.';
        }

        if ($fieldErrors) {
            throw new RuntimeException('Please correct the highlighted fields.');
        }

        $tenantId = $tenantService->addTenant([
            'room_id'      => $form['room_id'],
            'full_name'    => $form['full_name'],
            'phone'        => $form['phone'],
            'email'        => $form['email'],
            'move_in_date' => $form['move_in_date'],
        ], $admin_id);

        if (function_exists('logAudit')) {
            try {
                logAudit(
                    $admin_id,
                    'TENANT_ONBOARDED',
                    "Tenant #{$tenantId} ({$form['full_name']}) onboarded."
                );
            } catch (Throwable $ignored) {
                // Registration has already succeeded; audit failure must not undo it.
            }
        }

        $_SESSION['success'] = 'Tenant onboarded successfully.';
        redirect('tenant_control.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();

        if (!$fieldErrors) {
            $fieldByMessage = [
                'Tenant name is required.'           => 'full_name',
                'Room ID is required.'               => 'room_id',
                'Invalid tenant email address.'      => 'email',
                'Invalid move-in date.'              => 'move_in_date',
                'Selected room is not available.'   => 'room_id',
                'Room is not available.'             => 'room_id',
                'Room is no longer available.'       => 'room_id',
                'Unauthorized room access.'          => 'room_id',
            ];

            if (isset($fieldByMessage[$error])) {
                $fieldErrors[$fieldByMessage[$error]] = $error;
            }
        }
    }
}

$rooms = $tenantService->getFreeRooms($admin_id);
$roomDescribedBy = [];
if (isset($fieldErrors['room_id'])) {
    $roomDescribedBy[] = 'room_id_error';
}
if (!$rooms) {
    $roomDescribedBy[] = 'room_availability_note';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tenant Control</title>
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<?php
$active = 'control';
require __DIR__ . '/partials/navbar.php';
?>

<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
  <header class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div>
      <h1 class="text-2xl font-bold tracking-tight font-alt">Onboard Tenant</h1>
      <p class="mt-1 text-sm text-slate-600">
        Register a tenant, assign an available room, and start their rent schedule.
      </p>
    </div>
  </header>

  <?php if ($success): ?>
    <div class="mb-5 max-w-4xl rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
      <?= e($success) ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div id="tenantFormSummary" class="mb-5 max-w-4xl rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
      <p class="font-semibold"><?= e($error) ?></p>
      <?php if ($fieldErrors): ?>
        <ul class="mt-2 list-disc space-y-1 pl-5">
          <?php foreach ($fieldErrors as $fieldError): ?>
            <li><?= e($fieldError) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <section class="max-w-4xl rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
    <form
      id="tenantOnboardingForm"
      method="post"
      action="tenant_control.php"
      <?= $error ? 'aria-describedby="tenantFormSummary"' : '' ?>
    >
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <fieldset>
        <legend class="text-lg font-bold font-alt">Contact details</legend>
        <p class="mt-1 text-sm text-slate-600">Basic contact information for the person moving in.</p>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label for="full_name" class="flex items-center justify-between gap-2 text-xs font-semibold text-slate-600">
              <span>Full name</span>
              <span class="text-[11px] font-medium text-slate-500">required</span>
            </label>
            <input
              id="full_name"
              name="full_name"
              type="text"
              value="<?= e($form['full_name']) ?>"
              autocomplete="name"
              required
              class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200"
              <?= isset($fieldErrors['full_name']) ? 'aria-invalid="true" aria-describedby="full_name_error"' : '' ?>
            >
            <?php if (isset($fieldErrors['full_name'])): ?>
              <p id="full_name_error" class="mt-2 text-xs font-medium text-red-700"><?= e($fieldErrors['full_name']) ?></p>
            <?php endif; ?>
          </div>

          <div>
            <label for="phone" class="flex items-center justify-between gap-2 text-xs font-semibold text-slate-600">
              <span>Phone</span>
              <span class="text-[11px] font-medium text-slate-500">optional</span>
            </label>
            <input
              id="phone"
              name="phone"
              type="tel"
              value="<?= e($form['phone']) ?>"
              autocomplete="tel"
              class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200"
            >
          </div>

          <div>
            <label for="email" class="flex items-center justify-between gap-2 text-xs font-semibold text-slate-600">
              <span>Email</span>
              <span class="text-[11px] font-medium text-slate-500">optional</span>
            </label>
            <input
              id="email"
              name="email"
              type="email"
              value="<?= e($form['email']) ?>"
              autocomplete="email"
              class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200"
              <?= isset($fieldErrors['email']) ? 'aria-invalid="true" aria-describedby="email_error"' : '' ?>
            >
            <?php if (isset($fieldErrors['email'])): ?>
              <p id="email_error" class="mt-2 text-xs font-medium text-red-700"><?= e($fieldErrors['email']) ?></p>
            <?php endif; ?>
          </div>
        </div>
      </fieldset>

      <fieldset class="mt-6 border-t border-slate-100 pt-6">
        <legend class="text-lg font-bold font-alt">Tenancy details</legend>
        <p class="mt-1 text-sm text-slate-600">Choose the room and move-in date for this tenancy.</p>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label for="move_in_date" class="flex items-center justify-between gap-2 text-xs font-semibold text-slate-600">
              <span>Move-in date</span>
              <span class="text-[11px] font-medium text-slate-500">required</span>
            </label>
            <input
              id="move_in_date"
              type="date"
              name="move_in_date"
              value="<?= e($form['move_in_date']) ?>"
              required
              class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200"
              <?= isset($fieldErrors['move_in_date']) ? 'aria-invalid="true" aria-describedby="move_in_date_error"' : '' ?>
            >
            <?php if (isset($fieldErrors['move_in_date'])): ?>
              <p id="move_in_date_error" class="mt-2 text-xs font-medium text-red-700"><?= e($fieldErrors['move_in_date']) ?></p>
            <?php endif; ?>
          </div>

          <div>
            <label for="room_id" class="flex items-center justify-between gap-2 text-xs font-semibold text-slate-600">
              <span>Select available room</span>
              <span class="text-[11px] font-medium text-slate-500">required</span>
            </label>

            <?php if (!$rooms): ?>
              <div id="room_availability_note" class="mt-2 rounded-xl border border-dashed border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
                No rooms are currently available. Create a room before onboarding a tenant.
              </div>
            <?php endif; ?>

            <select
              id="room_id"
              name="room_id"
              required
              <?= !$rooms ? 'disabled' : '' ?>
              <?= $roomDescribedBy ? 'aria-describedby="' . e(implode(' ', $roomDescribedBy)) . '"' : '' ?>
              class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500"
              <?= isset($fieldErrors['room_id']) ? 'aria-invalid="true"' : '' ?>
            >
              <?php if (!$rooms): ?>
                <option value="">No available rooms</option>
              <?php else: ?>
                <option value="">Select available room</option>
                <?php foreach ($rooms as $room): ?>
                  <?php
                  $roomRent = (float)($room['rent'] ?? 0);
                  $rentLabel = $roomRent > 0
                      ? ' — UGX ' . number_format($roomRent, 0)
                      : '';
                  ?>
                  <option
                    value="<?= (int)$room['id'] ?>"
                    <?= (int)$form['room_id'] === (int)$room['id'] ? 'selected' : '' ?>
                  >
                    <?= e($room['room_number']) ?>
                    (<?= e($room['room_type'] ?? 'N/A') ?><?= e($rentLabel) ?>)
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
            <?php if (isset($fieldErrors['room_id'])): ?>
              <p id="room_id_error" class="mt-2 text-xs font-medium text-red-700"><?= e($fieldErrors['room_id']) ?></p>
            <?php endif; ?>
          </div>
        </div>
      </fieldset>

      <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-500">The selected room will be marked occupied after registration.</p>
        <button
          id="tenantOnboardingSubmit"
          type="submit"
          name="onboard_tenant"
          value="1"
          data-submitting-label="Onboarding…"
          <?= !$rooms ? 'disabled' : '' ?>
          class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
        >
          Onboard tenant
        </button>
      </div>
    </form>
  </section>
</main>

</body>
</html>
