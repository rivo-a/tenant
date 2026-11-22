<?php
// public/portal/partials/portal_navbar.php
if (!function_exists('icon')) {
    // Portal pages do not load the admin bootstrap; the icon renderer is shared.
    require_once __DIR__ . '/../../../core/icons.php';
}
if (!function_exists('e')) {
    require_once __DIR__ . '/../../../core/helpers.php';
}

$tenantName = $_SESSION['tenant_name'] ?? 'Tenant';
$currentPage = basename($_SERVER['PHP_SELF']);

$navUnread = 0;
if (!empty($_SESSION['tenant_id']) && function_exists('getDB')) {
    try {
        require_once __DIR__ . '/../../../services/NotificationService.php';
        $navUnread = (new NotificationService(getDB()))->unreadCount((int)$_SESSION['tenant_id']);
    } catch (Throwable $ignored) {
        $navUnread = 0;
    }
}
$navLinkClass = static function (string $page) use ($currentPage): string {
    return $currentPage === $page
        ? 'border-slate-900 text-slate-900'
        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700';
};
?>
<nav class="bg-white border-b border-slate-200">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 justify-between items-center">
            <div class="flex items-center gap-8">
                <span class="text-xl font-bold tracking-tight font-alt text-slate-900">Tenant Portal</span>
                <div class="flex flex-wrap gap-x-6 gap-y-1 sm:space-x-6 sm:gap-y-0">
                    <a href="/tenant-system/public/portal/dashboard.php" 
                       class="<?= $navLinkClass('dashboard.php') ?> inline-flex items-center gap-1.5 border-b-2 px-1 pt-1 text-sm font-medium transition">
                        <?= icon('house', 'h-4 w-4') ?> My rent
                    </a>
                    <a href="/tenant-system/public/portal/payments.php"
                       class="<?= $navLinkClass('payments.php') ?> inline-flex items-center gap-1.5 border-b-2 px-1 pt-1 text-sm font-medium transition">
                        <?= icon('wallet', 'h-4 w-4') ?> Payments
                    </a>
                    <a href="/tenant-system/public/portal/maintenance.php" 
                       class="<?= $navLinkClass('maintenance.php') ?> inline-flex items-center gap-1.5 border-b-2 px-1 pt-1 text-sm font-medium transition">
                        <?= icon('wrench', 'h-4 w-4') ?> Maintenance
                    </a>
                    <a href="/tenant-system/public/portal/notifications.php"
                       class="<?= $navLinkClass('notifications.php') ?> inline-flex items-center gap-1.5 border-b-2 px-1 pt-1 text-sm font-medium transition">
                        <?= icon('bell', 'h-4 w-4') ?> Alerts
                        <?php if ($navUnread > 0): ?>
                            <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-sky-600 px-1.5 py-0.5 text-[11px] font-bold text-white"><?= $navUnread ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-sm text-slate-600 hidden sm:block">Welcome, <strong><?= htmlspecialchars($tenantName, ENT_QUOTES, 'UTF-8') ?></strong></span>
                <form action="/tenant-system/public/portal/logout.php" method="POST">
                    <button type="submit" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition">
                        <?= icon('log-out', 'mr-1.5 h-4 w-4') ?>
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>