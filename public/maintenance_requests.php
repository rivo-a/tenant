<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/MaintenanceService.php';

require_login();
require_capability('tenants.manage');

$pdo = getDB();
$businessId = current_business_id();
$actorId = (int)($_SESSION['admin_id'] ?? 0);

$service = new MaintenanceService($pdo);

$filters = [
    'status' => (string)($_GET['status'] ?? 'open'),
    'urgency' => (string)($_GET['urgency'] ?? ''),
    'search' => trim((string)($_GET['search'] ?? '')),
];

if (!in_array($filters['status'], array_merge(['open', ''], MaintenanceService::STATUSES), true)) {
    $filters['status'] = 'open';
}
if (!in_array($filters['urgency'], array_merge([''], MaintenanceService::URGENCIES), true)) {
    $filters['urgency'] = '';
}

$success = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verify_csrf((string)($_POST['csrf'] ?? ''));

        $requestId = (int)($_POST['request_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        $message = trim((string)($_POST['message'] ?? ''));

        if ($action === 'assign') {
            $service->assign($businessId, $requestId, (int)($_POST['staff_id'] ?? 0), $actorId, $message);
            $success = 'Assigned. The tenant can see this update.';
        } elseif ($action === 'status') {
            $service->updateStatus(
                $businessId,
                $requestId,
                (string)($_POST['status'] ?? ''),
                $actorId,
                $message,
                trim((string)($_POST['resolution_note'] ?? ''))
            );
            $success = 'Status updated. The tenant can see this update.';
        } else {
            throw new RuntimeException('Unknown action.');
        }

        $_SESSION['maintenance_flash'] = $success;
        header('Location: maintenance_requests.php?' . http_build_query(array_filter([
            'status' => $filters['status'],
            'urgency' => $filters['urgency'],
            'search' => $filters['search'],
        ])));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (!empty($_SESSION['maintenance_flash'])) {
    $success = (string)$_SESSION['maintenance_flash'];
    unset($_SESSION['maintenance_flash']);
}

$requests = $service->getBusinessRequests($businessId, $filters);
$staff = $service->assignableStaff($businessId);

$openCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM maintenance_requests
     WHERE admin_id = " . (int)$businessId . "
       AND status IN ('submitted','under_review','assigned','in_progress')"
)->fetchColumn();

$urgentCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM maintenance_requests
     WHERE admin_id = " . (int)$businessId . "
       AND urgency = 'urgent'
       AND status IN ('submitted','under_review','assigned','in_progress')"
)->fetchColumn();

$unassignedCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM maintenance_requests
     WHERE admin_id = " . (int)$businessId . "
       AND assigned_to IS NULL
       AND status IN ('submitted','under_review')"
)->fetchColumn();

function requestStatus(string $status): array
{
    return match (strtolower($status)) {
        'submitted' => ['Submitted', 'bg-sky-50 text-sky-700 ring-sky-600/20'],
        'under_review', 'acknowledged' => ['Under review', 'bg-indigo-50 text-indigo-700 ring-indigo-600/20'],
        'assigned' => ['Assigned', 'bg-violet-50 text-violet-700 ring-violet-600/20'],
        'in_progress' => ['In progress', 'bg-amber-50 text-amber-700 ring-amber-600/20'],
        'completed', 'resolved' => ['Completed', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'],
        'rejected' => ['Closed', 'bg-slate-100 text-slate-600 ring-slate-500/20'],
        default => [ucfirst(str_replace('_', ' ', $status)), 'bg-slate-50 text-slate-600 ring-slate-500/10'],
    };
}

function requestAge(?string $createdAt): string
{
    if (!$createdAt) {
        return '—';
    }
    $ts = strtotime($createdAt);
    if (!$ts) {
        return '—';
    }
    $days = (int)floor((time() - $ts) / 86400);

    return $days <= 0 ? 'today' : $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

$activeNav = 'maintenance';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Maintenance · Urbahan</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<?php
$navPath = __DIR__ . '/partials/navbar.php';
if (is_file($navPath)) {
    require $navPath;
}
?>
<main class="mx-auto max-w-5xl px-4 py-6 sm:px-6">

    <header class="mb-5">
        <h1 class="text-2xl font-bold tracking-tight">Maintenance</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-600">
            Repair requests raised by tenants. Assign someone, move it along, and the tenant sees every update.
        </p>
    </header>

    <?php if ($success !== ''): ?>
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="mb-5 grid gap-3 sm:grid-cols-3" aria-label="Maintenance summary">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Open</p>
            <p class="mt-1 text-2xl font-bold tabular-nums"><?= $openCount ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Urgent and open</p>
            <p class="mt-1 text-2xl font-bold tabular-nums <?= $urgentCount > 0 ? 'text-red-700' : '' ?>"><?= $urgentCount ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Waiting for someone</p>
            <p class="mt-1 text-2xl font-bold tabular-nums"><?= $unassignedCount ?></p>
        </div>
    </section>

    <form method="get" class="mb-4 grid gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:grid-cols-4">
        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            Status
            <select name="status" class="min-h-11 rounded-xl border border-slate-300 bg-white px-2 py-2 text-sm font-normal">
                <option value="open" <?= $filters['status'] === 'open' ? 'selected' : '' ?>>Open only</option>
                <option value="" <?= $filters['status'] === '' ? 'selected' : '' ?>>Any status</option>
                <?php foreach (MaintenanceService::STATUSES as $status): ?>
                    <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(requestStatus($status)[0]) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            Urgency
            <select name="urgency" class="min-h-11 rounded-xl border border-slate-300 bg-white px-2 py-2 text-sm font-normal">
                <option value="">Any</option>
                <?php foreach (MaintenanceService::URGENCIES as $urgency): ?>
                    <option value="<?= e($urgency) ?>" <?= $filters['urgency'] === $urgency ? 'selected' : '' ?>><?= e(ucfirst($urgency)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
            Search
            <input type="search" name="search" value="<?= e($filters['search']) ?>" placeholder="Tenant, room or text"
                   class="min-h-11 rounded-xl border border-slate-300 px-2 py-2 text-sm font-normal">
        </label>
        <div class="flex items-end gap-2">
            <button class="min-h-11 flex-1 rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filter</button>
            <a href="maintenance_requests.php" class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
        </div>
    </form>

    <?php if (!$requests): ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
            <h2 class="text-base font-semibold">No requests match this view</h2>
            <p class="mx-auto mt-1 max-w-sm text-sm text-slate-600">
                Tenants raise requests from their portal. Nothing is waiting on you here right now.
            </p>
        </div>
    <?php else: ?>
        <ul role="list" class="grid gap-3">
            <?php foreach ($requests as $request): ?>
                <?php
                    [$statusLabel, $statusTone] = requestStatus((string)$request['status']);
                    $isUrgent = (string)$request['urgency'] === 'urgent';
                ?>
                <li class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">
                                <?= e($request['category']) ?>
                                <span class="font-normal text-slate-500">· <?= e($request['tenant_name']) ?></span>
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                <?= e($request['room_number'] ? 'Room ' . $request['room_number'] : 'Unassigned room') ?>
                                · raised <?= e(requestAge((string)$request['created_at'])) ?>
                                · <?= e($request['assigned_name'] ? 'with ' . $request['assigned_name'] : 'nobody assigned') ?>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <?php if ($isUrgent): ?>
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Urgent</span>
                            <?php endif; ?>
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ring-1 ring-inset <?= $statusTone ?>"><?= e($statusLabel) ?></span>
                        </div>
                    </div>

                    <p class="mt-2 text-sm leading-relaxed text-slate-700"><?= nl2br(e($request['description'])) ?></p>

                    <?php if (!empty($request['last_admin_message'])): ?>
                        <p class="mt-2 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            <span class="font-semibold">Last update:</span> <?= e($request['last_admin_message']) ?>
                        </p>
                    <?php endif; ?>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <?php if (!empty($request['attachment_path'])): ?>
                            <a href="maintenance_attachment.php?request=<?= (int)$request['id'] ?>" target="_blank" rel="noopener"
                               class="inline-flex min-h-9 items-center rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200">
                                View attachment
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($request['tenant_phone'])): ?>
                            <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string)$request['tenant_phone'])) ?>"
                               class="inline-flex min-h-9 items-center rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200">
                                Call <?= e($request['tenant_phone']) ?>
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="mt-4 grid gap-2 border-t border-slate-100 pt-3 sm:grid-cols-2">
                        <form method="post" class="flex flex-wrap items-end gap-2">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                            <input type="hidden" name="action" value="assign">
                            <label class="flex min-w-0 flex-1 flex-col gap-1 text-xs font-semibold text-slate-600">
                                Assign to
                                <select name="staff_id" required class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-2 py-2 text-sm font-normal">
                                    <option value="">Choose staff</option>
                                    <?php foreach ($staff as $member): ?>
                                        <option value="<?= (int)$member['id'] ?>" <?= (int)$member['id'] === (int)($request['assigned_to'] ?? 0) ? 'selected' : '' ?>>
                                            <?= e($member['full_name']) ?> (<?= e(auth_role_label((string)$member['role'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Assign</button>
                        </form>

                        <form method="post" class="grid gap-2">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                            <input type="hidden" name="action" value="status">
                            <div class="flex flex-wrap items-end gap-2">
                                <label class="flex min-w-0 flex-1 flex-col gap-1 text-xs font-semibold text-slate-600">
                                    Move to
                                    <select name="status" required class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-2 py-2 text-sm font-normal">
                                        <?php foreach (MaintenanceService::STATUSES as $status): ?>
                                            <option value="<?= e($status) ?>" <?= $status === $request['status'] ? 'selected' : '' ?>><?= e(requestStatus($status)[0]) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="min-h-11 rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Update</button>
                            </div>
                            <input type="text" name="message" maxlength="500" placeholder="Note the tenant will see (required to close)"
                                   class="min-h-11 rounded-xl border border-slate-300 px-2 py-2 text-sm">
                            <input type="text" name="resolution_note" maxlength="500" placeholder="What was actually fixed (optional)"
                                   class="min-h-11 rounded-xl border border-slate-300 px-2 py-2 text-sm">
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>
