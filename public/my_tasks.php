<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/MaintenanceService.php';

require_login();

$businessId = current_business_id();
$me = (int)($_SESSION['admin_id'] ?? ($_SESSION['admin']['id'] ?? 0));
$service = new MaintenanceService(getDB());
$error = '';
$success = is_scalar($_SESSION['success'] ?? null) ? (string)$_SESSION['success'] : '';
unset($_SESSION['success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf(is_scalar($_POST['csrf'] ?? null) ? (string)$_POST['csrf'] : '');

        $requestId = (int)($_POST['request_id'] ?? 0);
        $status = strtolower(trim((string)($_POST['status'] ?? '')));
        $message = trim((string)($_POST['message'] ?? ''));

        if (!in_array($status, ['in_progress', 'completed'], true)) {
            throw new RuntimeException('Choose In progress or Completed.');
        }

        // Only the person the issue was handed to may move it.
        $claim = getDB()->prepare('
            SELECT id FROM maintenance_requests
            WHERE id = :id AND admin_id = :admin_id AND assigned_to = :me
            LIMIT 1
        ');
        $claim->execute([':id' => $requestId, ':admin_id' => $businessId, ':me' => $me]);
        if (!$claim->fetchColumn()) {
            throw new RuntimeException('This issue is not assigned to you.');
        }

        $service->updateStatus($businessId, $requestId, $status, $me, $message);
        $_SESSION['success'] = 'Issue updated. The landlord and tenant can see it.';
        redirect('my_tasks.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = getDB()->prepare("
    SELECT mr.id, mr.category, mr.description, mr.urgency, mr.status, mr.created_at,
           t.full_name AS tenant_name, t.phone AS tenant_phone, r.room_number
    FROM maintenance_requests mr
    JOIN tenants t ON t.id = mr.tenant_id
    LEFT JOIN rooms r ON r.id = mr.room_id
    WHERE mr.admin_id = :admin_id
      AND mr.assigned_to = :me
      AND mr.status NOT IN ('completed', 'rejected', 'resolved')
    ORDER BY CASE mr.urgency WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
             mr.created_at DESC
");
$stmt->execute([':admin_id' => $businessId, ':me' => $me]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$urgencyTone = static fn(string $u): string => match (strtolower($u)) {
    'urgent' => 'bg-red-50 text-red-700 ring-red-200',
    'low' => 'bg-slate-100 text-slate-600 ring-slate-200',
    default => 'bg-amber-50 text-amber-800 ring-amber-200',
};

$active = 'my_tasks';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My tasks · Urbahan</title>
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<?php require __DIR__ . '/partials/navbar.php'; ?>

<main class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="font-alt text-2xl font-bold tracking-tight">My tasks</h1>
      <p class="mt-1 text-sm text-slate-600">Issues assigned to you. Update one to tell the landlord and tenant what happened.</p>
    </div>
    <p class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold">
      <?= count($tasks) ?> open
    </p>
  </div>

  <?php if ($success): ?>
    <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status"><?= e($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert"><?= e($error) ?></div>
  <?php endif; ?>

  <div class="mt-5 space-y-4">
    <?php if (!$tasks): ?>
      <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
        <p class="font-semibold text-slate-900">Nothing assigned to you right now.</p>
        <p class="mt-1 text-sm text-slate-600">New issues appear here as soon as your landlord assigns them.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($tasks as $task): ?>
      <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
              #<?= (int)$task['id'] ?> · <?= e((string)$task['category']) ?>
            </p>
            <h2 class="mt-1 font-alt text-lg font-bold">
              <?= e((string)$task['room_number'] ?: 'Room —') ?> · <?= e((string)$task['tenant_name']) ?>
            </h2>
            <p class="mt-1 text-sm text-slate-600"><?= nl2br(e((string)$task['description'])) ?></p>
            <p class="mt-2 text-xs text-slate-500">
              <?= e((string)$task['tenant_phone']) ?> · opened <?= e(humanDate((string)$task['created_at'])) ?>
            </p>
          </div>
          <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= e($urgencyTone((string)$task['urgency'])) ?>">
            <?= e(ucfirst((string)$task['urgency'])) ?>
          </span>
        </div>

        <form method="post" class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-[minmax(0,180px)_1fr_auto]">
          <?= csrf_field() ?>
          <input type="hidden" name="request_id" value="<?= (int)$task['id'] ?>">
          <label class="text-xs font-bold uppercase tracking-wide text-slate-600">
            New state
            <select name="status" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm font-medium">
              <option value="in_progress">In progress</option>
              <option value="completed">Completed</option>
            </select>
          </label>
          <label class="text-xs font-bold uppercase tracking-wide text-slate-600">
            Note for the landlord and tenant
            <input name="message" maxlength="500" placeholder="Replaced the tap washer" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
          </label>
          <button type="submit" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800">
            Update
          </button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
</main>
</body>
</html>
