<?php
declare(strict_types=1);

/*
 * Rent collector's only page.
 *
 * Read-only list of genuinely overdue tenants for the collector's landlord.
 * Built on ArrearsService so the figures match reports and the dashboard.
 * Nothing here writes: no payment, no balance, no tenant record.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/ArrearsService.php';

require_login();
require_capability('collector.overdue');

$businessId = current_business_id();
if ($businessId <= 0) {
    http_response_code(403);
    exit('Access denied: no business is linked to this account.');
}

$arrears = new ArrearsService(getDB());
$overdue = $arrears->getOwing($businessId, 0, null, true);

$totalOutstanding = 0.0;
$oldest = 0;
foreach ($overdue as $row) {
    $totalOutstanding += (float)$row['balance'];
    $oldest = max($oldest, (int)$row['days_overdue']);
}

$money = static fn($value): string => 'UGX ' . number_format((float)$value, 0);
$phoneHref = static function (string $phone): string {
    $digits = preg_replace('/[^0-9+]/', '', $phone) ?? '';

    return $digits === '' ? '' : 'tel:' . $digits;
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Overdue rent — collection list</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur">
  <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
    <div class="flex min-w-0 items-center gap-3">
      <img class="w-9" src="/tenant-system/public/assets/urbahan-logo.png" alt="">
      <span class="min-w-0 leading-tight">
        <span class="block truncate font-extrabold">Urbahan</span>
        <span class="block truncate text-xs text-slate-500">
          Rent collection · <?= e(auth_user()['full_name'] ?? '') ?>
        </span>
      </span>
    </div>
    <a href="logout.php"
       class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
      Logout
    </a>
    <a href="my_tasks.php"
       class="inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
      myTasks
    </a>
  </div>
</header>

<main class="mx-auto max-w-5xl px-4 py-6 sm:px-6">

  <div class="mb-6">
    <h1 class="text-2xl font-bold tracking-tight">Overdue tenants</h1>
    <p class="mt-1 max-w-2xl text-sm text-slate-600">
      Visit these tenants and collect the outstanding rent in person. Record every
      collection with the landlord afterwards — this page does not change any balance.
    </p>
  </div>

  <section class="mb-6 grid gap-3 sm:grid-cols-3" aria-label="Summary">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tenants overdue</p>
      <p class="mt-1 text-2xl font-bold tabular-nums"><?= count($overdue) ?></p>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Outstanding</p>
      <p class="mt-1 text-2xl font-bold tabular-nums"><?= e($money($totalOutstanding)) ?></p>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Longest overdue</p>
      <p class="mt-1 text-2xl font-bold tabular-nums"><?= $oldest ?> day<?= $oldest === 1 ? '' : 's' ?></p>
    </div>
  </section>

  <?php if (!$overdue): ?>
    <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
      <h2 class="text-lg font-semibold">No overdue tenants</h2>
      <p class="mx-auto mt-1 max-w-sm text-sm text-slate-600">
        Every active tenant is up to date on rent. Check again after the next due date.
      </p>
    </div>
  <?php else: ?>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-2 font-semibold">Tenant</th>
              <th class="px-4 py-2 font-semibold">Phone</th>
              <th class="px-4 py-2 font-semibold">Room</th>
              <th class="px-4 py-2 font-semibold">Owed since</th>
              <th class="px-4 py-2 font-semibold text-right">Outstanding</th>
              <th class="px-4 py-2 font-semibold text-right">Days overdue</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            <?php foreach ($overdue as $row): ?>
              <?php $tel = $phoneHref((string)$row['phone']); ?>
              <tr>
                <td class="px-4 py-3 font-medium text-slate-900"><?= e($row['full_name']) ?></td>
                <td class="px-4 py-3 text-slate-700">
                  <?php if ($tel !== ''): ?>
                    <a class="font-medium underline decoration-slate-300 underline-offset-2 hover:decoration-slate-900"
                       href="<?= e($tel) ?>"><?= e($row['phone']) ?></a>
                  <?php else: ?>
                    <span class="text-slate-400">No phone recorded</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-slate-700">
                  <?= e($row['room_number'] !== '' ? 'Room ' . $row['room_number'] : 'Unassigned') ?>
                  <?php if ($row['room_type'] !== ''): ?>
                    <span class="block text-xs text-slate-500"><?= e($row['room_type']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-slate-700"><?= e($row['owed_since_label']) ?></td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums"><?= e($money($row['balance'])) ?></td>
                <td class="px-4 py-3 text-right tabular-nums <?= (int)$row['days_overdue'] > 30 ? 'font-semibold text-red-700' : 'text-slate-700' ?>">
                  <?= (int)$row['days_overdue'] ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

</main>
</body>
</html>
