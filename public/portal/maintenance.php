<?php
declare(strict_types=1);

if (getenv('APP_ENV') !== 'production') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/tenant_auth.php';
require_once __DIR__ . '/../../services/MaintenanceService.php';

requireTenantAuth();

$tenantId = getLoggedInTenantId();
$service = new MaintenanceService(getDB());

$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'open', 'resolved'], true)) {
    $filter = 'all';
}

$form = ['category' => '', 'description' => '', 'urgency' => 'normal'];
$error = '';
$success = isset($_GET['created']) ? 'Request submitted. You will see updates from management here.' : '';
$openForm = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verify_csrf((string)($_POST['csrf'] ?? ''));

        $form = [
            'category' => trim((string)($_POST['category'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'urgency' => strtolower(trim((string)($_POST['urgency'] ?? 'normal'))),
        ];

        $service->createRequest($tenantId, [
            'category' => $form['category'],
            'description' => $form['description'],
            'urgency' => $form['urgency'],
            'attachment' => $_FILES['attachment'] ?? null,
        ]);

        header('Location: maintenance.php?created=1&filter=' . urlencode($filter));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $openForm = true;
    }
}

$requests = $service->getTenantRequests($tenantId, $filter);

/* Timeline for every listed request in one query. */
$timelines = [];
if ($requests) {
    $ids = array_map(static fn(array $row): int => (int)$row['id'], $requests);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getDB()->prepare("
        SELECT request_id, actor_type, new_status, message, created_at
        FROM maintenance_request_updates
        WHERE request_id IN ({$placeholders})
        ORDER BY created_at ASC, id ASC
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $update) {
        $timelines[(int)$update['request_id']][] = $update;
    }
}

function maintenanceStatus(string $status): array
{
    return match (strtolower($status)) {
        'submitted' => ['Submitted', 'bg-sky-50 text-sky-700 ring-sky-600/20'],
        'under_review', 'acknowledged' => ['Under review', 'bg-indigo-50 text-indigo-700 ring-indigo-600/20'],
        'assigned' => ['Assigned', 'bg-violet-50 text-violet-700 ring-violet-600/20'],
        'in_progress' => ['In progress', 'bg-amber-50 text-amber-700 ring-amber-600/20'],
        'completed', 'resolved' => ['Completed', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'],
        'rejected' => ['Closed', 'bg-slate-100 text-slate-600 ring-slate-500/20'],
        default => [ucfirst(str_replace('_', ' ', strtolower($status))), 'bg-slate-50 text-slate-600 ring-slate-500/10'],
    };
}

function maintenanceUrgency(string $urgency): ?array
{
    return match (strtolower($urgency)) {
        'urgent' => ['Urgent', 'bg-red-50 text-red-700 ring-red-600/20'],
        'low' => ['Low', 'bg-slate-50 text-slate-500 ring-slate-500/10'],
        default => null,
    };
}

function maintenanceWhen(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);

    return $ts ? date('d M Y, H:i', $ts) : '—';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maintenance — Tenant Portal</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/tenant-system/public/assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<?php require __DIR__ . '/partials/portal_navbar.php'; ?>

<main class="mx-auto max-w-4xl px-4 py-6 sm:px-6">

    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-xl font-bold tracking-tight sm:text-2xl">Maintenance</h1>
            <p class="mt-1 text-sm text-slate-600">Report a problem in your room and follow what management does about it.</p>
        </div>
        <button type="button" id="openForm"
                class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
            Report an issue
        </button>
    </div>

    <?php if ($success !== ''): ?>
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <nav class="mb-4 flex flex-wrap gap-2" aria-label="Filter requests">
        <?php foreach (['all' => 'All', 'open' => 'Open', 'resolved' => 'Closed'] as $key => $label): ?>
            <a href="maintenance.php?filter=<?= e($key) ?>"
               class="inline-flex min-h-11 items-center rounded-xl border px-3 py-2 text-sm font-semibold transition <?= $filter === $key ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' ?>"
               <?= $filter === $key ? 'aria-current="page"' : '' ?>>
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if (!$requests): ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
            <h2 class="text-base font-semibold">Nothing reported yet</h2>
            <p class="mx-auto mt-1 max-w-sm text-sm text-slate-600">
                When something in your room needs fixing, report it here with a photo so the landlord can send the right person.
            </p>
        </div>
    <?php else: ?>
        <ul role="list" class="grid gap-3">
            <?php foreach ($requests as $request): ?>
                <?php
                    [$statusLabel, $statusTone] = maintenanceStatus((string)$request['status']);
                    $urgency = maintenanceUrgency((string)($request['urgency'] ?? 'normal'));
                    $requestTimeline = $timelines[(int)$request['id']] ?? [];
                ?>
                <li class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold"><?= e($request['category']) ?></p>
                            <p class="mt-0.5 text-xs text-slate-500">Reported <?= e(maintenanceWhen((string)$request['created_at'])) ?></p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <?php if ($urgency !== null): ?>
                                <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ring-1 ring-inset <?= $urgency[1] ?>"><?= e($urgency[0]) ?></span>
                            <?php endif; ?>
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ring-1 ring-inset <?= $statusTone ?>"><?= e($statusLabel) ?></span>
                        </div>
                    </div>

                    <p class="mt-2 text-sm leading-relaxed text-slate-700"><?= nl2br(e($request['description'])) ?></p>

                    <?php if (!empty($request['resolution_note'])): ?>
                        <p class="mt-3 rounded-xl bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800">
                            Completed: <?= e($request['resolution_note']) ?>
                        </p>
                    <?php endif; ?>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <?php if (!empty($request['attachment_path'])): ?>
                            <a href="maintenance_attachment.php?request=<?= (int)$request['id'] ?>" target="_blank" rel="noopener"
                               class="inline-flex min-h-9 items-center rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-200">
                                View attachment
                            </a>
                        <?php endif; ?>

                        <?php if ($requestTimeline): ?>
                            <details class="w-full">
                                <summary class="min-h-9 cursor-pointer text-xs font-semibold text-slate-600 hover:text-slate-900">
                                    Updates (<?= count($requestTimeline) ?>)
                                </summary>
                                <ol class="mt-2 space-y-2 border-l border-slate-200 pl-3">
                                    <?php foreach ($requestTimeline as $update): ?>
                                        <li>
                                            <p class="text-xs text-slate-500">
                                                <?= e(maintenanceWhen((string)$update['created_at'])) ?>
                                                · <?= $update['actor_type'] === 'tenant' ? 'You' : 'Management' ?>
                                            </p>
                                            <p class="text-xs text-slate-700"><?= e((string)$update['message']) ?></p>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </details>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>

<section id="reportPanel" class="<?= $openForm ? '' : 'hidden' ?> fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 px-4 py-6" role="dialog" aria-modal="true" aria-labelledby="reportTitle">
    <div class="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-5 shadow-xl">
        <div class="flex items-start justify-between gap-3">
            <h2 id="reportTitle" class="text-base font-bold">Report an issue</h2>
            <button type="button" id="closeForm" class="rounded-lg px-2 py-1 text-sm font-semibold text-slate-500 hover:bg-slate-100" aria-label="Close">✕</button>
        </div>

        <form method="post" enctype="multipart/form-data" class="mt-4 grid gap-3">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <label class="flex flex-col gap-1 text-sm font-medium text-slate-700">
                What is the problem?
                <select name="category" required class="min-h-11 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
                    <option value="">Choose a category</option>
                    <?php foreach (MaintenanceService::CATEGORIES as $category): ?>
                        <option value="<?= e($category) ?>" <?= $form['category'] === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm font-medium text-slate-700">
                Describe it
                <textarea name="description" rows="4" required minlength="10" maxlength="2000"
                          placeholder="What is broken, where, and since when?"
                          class="rounded-xl border border-slate-300 px-3 py-2 text-sm font-normal"><?= e($form['description']) ?></textarea>
            </label>

            <fieldset class="flex flex-col gap-1 text-sm font-medium text-slate-700">
                <legend class="mb-1">How urgent is it?</legend>
                <div class="flex flex-wrap gap-2">
                    <?php foreach (['low' => 'Can wait', 'normal' => 'Normal', 'urgent' => 'Urgent'] as $value => $label): ?>
                        <label class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm font-normal">
                            <input type="radio" name="urgency" value="<?= e($value) ?>" <?= $form['urgency'] === $value ? 'checked' : '' ?>>
                            <?= e($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <label class="flex flex-col gap-1 text-sm font-medium text-slate-700">
                Photo or PDF (optional)
                <input type="file" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf"
                       class="mt-1 block w-full text-sm font-normal text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
                <span class="text-xs font-normal text-slate-500">Up to 5MB. JPG, PNG, WEBP or PDF.</span>
            </label>

            <div class="mt-2 flex justify-end gap-2">
                <button type="button" id="cancelForm" class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="min-h-11 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Submit request</button>
            </div>
        </form>
    </div>
</section>

<script nonce="<?= e(csp_nonce()) ?>">
    (function () {
        var panel = document.getElementById('reportPanel');
        var open = document.getElementById('openForm');
        var close = document.getElementById('closeForm');
        var cancel = document.getElementById('cancelForm');

        function show() { if (panel) { panel.classList.remove('hidden'); } }
        function hide() { if (panel) { panel.classList.add('hidden'); } }

        if (open) open.addEventListener('click', show);
        if (close) close.addEventListener('click', hide);
        if (cancel) cancel.addEventListener('click', hide);
        if (panel) panel.addEventListener('click', function (event) { if (event.target === panel) hide(); });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && panel && !panel.classList.contains('hidden')) hide();
        });
    })();
</script>

</body>
</html>
