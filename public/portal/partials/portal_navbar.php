<?php
// public/portal/partials/portal_navbar.php
$tenantName = $_SESSION['tenant_name'] ?? 'Tenant';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<nav class="bg-white border-b border-slate-200">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 justify-between items-center">
            <div class="flex items-center gap-8">
                <span class="text-xl font-bold tracking-tight font-alt text-slate-900">Tenant Portal</span>
                <div class="hidden sm:flex sm:space-x-6">
                    <a href="/tenant-system/public/portal/dashboard.php" 
                       class="<?= $currentPage === 'dashboard.php' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' ?> inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium transition">
                        Dashboard
                    </a>
                    <a href="/tenant-system/public/portal/payments.php" 
                       class="<?= $currentPage === 'payments.php' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' ?> inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium transition">
                        Payments
                    </a>
                    <a href="/tenant-system/public/portal/maintenance.php" 
                       class="<?= $currentPage === 'maintenance.php' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' ?> inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium transition">
                        Maintenance
                    </a>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-sm text-slate-600 hidden sm:block">Welcome, <strong><?= htmlspecialchars($tenantName, ENT_QUOTES, 'UTF-8') ?></strong></span>
                <form action="/tenant-system/public/portal/logout.php" method="POST">
                    <button type="submit" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition">
                        <svg class="mr-1.5 h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>