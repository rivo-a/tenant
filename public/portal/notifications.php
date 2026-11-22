<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/tenant_auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';

requireTenantAuth();

$tenantId = getLoggedInTenantId();
$notifications = new NotificationService(getDB());

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verify_csrf((string)($_POST['csrf'] ?? ''));

        if (($_POST['action'] ?? '') === 'mark_all') {
            $notifications->markAllRead($tenantId);
        } elseif (($_POST['action'] ?? '') === 'mark_one') {
            $notifications->markRead($tenantId, (int)($_POST['id'] ?? 0));
        }

        header('Location: notifications.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$items = $notifications->feed($tenantId, 40);
$unread = $notifications->unreadCount($tenantId);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alerts — Tenant Portal</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/tenant-system/public/assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<?php require __DIR__ . '/partials/portal_navbar.php'; ?>

<main class="mx-auto max-w-3xl px-4 py-6 sm:px-6">

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold tracking-tight sm:text-2xl">Alerts</h1>
            <p class="mt-1 text-sm text-slate-600">
                <?= $unread > 0 ? $unread . ' unread update' . ($unread === 1 ? '' : 's') : 'Nothing unread' ?>
            </p>
        </div>

        <?php if ($unread > 0): ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_all">
                <button class="min-h-11 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Mark all read
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($error !== ''): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$items): ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
            <h2 class="text-base font-semibold">No alerts yet</h2>
            <p class="mx-auto mt-1 max-w-sm text-sm text-slate-600">
                Payment and repair updates from your landlord will appear here.
            </p>
        </div>
    <?php else: ?>
        <ul role="list" class="grid gap-2">
            <?php foreach ($items as $item): ?>
                <?php
                    $isUnread = empty($item['read_at']);
                    $href = $item['target_type'] === 'maintenance_request' ? 'maintenance.php' : 'dashboard.php';
                ?>
                <li class="rounded-2xl border <?= $isUnread ? 'border-slate-300 bg-white' : 'border-slate-200 bg-white/60' ?> p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm <?= $isUnread ? 'font-semibold' : 'font-medium text-slate-700' ?>">
                                <?php if ($isUnread): ?>
                                    <span class="mr-1.5 inline-block h-2 w-2 rounded-full bg-sky-500" aria-label="Unread"></span>
                                <?php endif; ?>
                                <?= e($item['title']) ?>
                            </p>
                            <?php if (!empty($item['body'])): ?>
                                <p class="mt-1 text-xs leading-relaxed text-slate-600"><?= e($item['body']) ?></p>
                            <?php endif; ?>
                            <p class="mt-1.5 text-xs text-slate-500"><?= e(date('d M Y, H:i', strtotime((string)$item['created_at']))) ?></p>
                        </div>

                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <a href="<?= e($href) ?>" class="text-xs font-semibold text-slate-700 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-900">Open</a>
                            <?php if ($isUnread): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="mark_one">
                                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                    <button class="text-xs text-slate-500 hover:text-slate-900">Mark read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>
