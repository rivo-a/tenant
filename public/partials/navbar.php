<?php
declare(strict_types=1);

/**
 * Navbar Partial
 * Usage:
 *   $active = 'payments'; // set in each page
 *   require __DIR__ . '/partials/navbar.php';
 */

$active = $active ?? '';

function nav_active(string $key, string $active): string
{
    return $key === $active
        ? 'bg-slate-900 text-white border-slate-900'
        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50';
}

function nav_link(string $href, string $label, string $key, string $active): void
{
    $cls = nav_active($key, $active);
    echo '<a href="' . e($href) . '" class="px-3 py-2 rounded-xl border text-sm font-semibold transition ' . $cls . '">' . e($label) . '</a>';
}
?>

<header class="sticky top-0 z-40 bg-white/80 backdrop-blur border-b border-slate-200">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 py-3 flex items-center justify-between gap-3">
    <!-- Brand -->
    <div class="flex items-center gap-3">
      <div class="h-10 w-10 rounded-2xl bg-slate-900 text-white grid place-items-center font-black">
        TS
      </div>
      <div class="leading-tight">
        <div class="font-extrabold text-slate-900">Tenant System</div>
        <div class="text-xs text-slate-500">
          Logged in as <?= e($_SESSION['admin']['name'] ?? ($_SESSION['admin_name'] ?? 'Admin')) ?>
          <span class="ml-1 inline-flex items-center rounded-full border border-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
            <?= e(current_admin_role()) ?>
          </span>
        </div>
      </div>
    </div>

    <!-- Mobile toggle -->
    <button
      type="button"
      class="sm:hidden inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
      onclick="document.getElementById('mobileNav').classList.toggle('hidden')"
    >
      Menu
    </button>

    <!-- Desktop nav -->
    <nav class="hidden sm:flex items-center gap-2 flex-wrap justify-end">
      <?php nav_link('payments.php', 'Payments', 'payments', $active); ?>
      <?php nav_link('payments_history.php', 'History', 'history', $active); ?>
      <?php nav_link('dashboard.php', 'Dashboard', 'dashboard', $active); ?>

      <!-- ✅ add this (was in your old <p> links) -->
      <?php nav_link('tenant_payments.php', 'Tenant Payments', 'tenant_payments', $active); ?>

      <?php nav_link('tenants.php', 'Tenants', 'tenants', $active); ?>
      <?php nav_link('rooms.php', 'Rooms', 'rooms', $active); ?>
      <?php nav_link('reports.php', 'Reports', 'reports', $active); ?>
      <?php nav_link('tenant_control.php', 'Control', 'control', $active); ?>

      <?php if (current_admin_role() === 'caretaker' || isSuperAdmin()): ?>
        <?php nav_link('caretaker_overdue.php', 'Caretaker', 'caretaker', $active); ?>
      <?php endif; ?>

      <?php if (isSuperAdmin()): ?>
        <?php nav_link('audit_logs.php', 'Audit Logs', 'audit', $active); ?>
      <?php endif; ?>

      <a href="logout.php"
         class="ml-2 px-3 py-2 rounded-xl border border-slate-200 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-50">
        Logout
      </a>
    </nav>
  </div>

  <!-- Mobile nav -->
  <div id="mobileNav" class="hidden sm:hidden border-t border-slate-200 bg-white">
    <div class="mx-auto max-w-7xl px-4 py-3 flex flex-col gap-2">
      <?php nav_link('payments.php', 'Payments', 'payments', $active); ?>
      <?php nav_link('payments_history.php', 'History', 'history', $active); ?>
      <?php nav_link('tenant_payments.php', 'Tenant Payments', 'tenant_payments', $active); ?>

      <?php nav_link('tenants.php', 'Tenants', 'tenants', $active); ?>
      <?php nav_link('rooms.php', 'Rooms', 'rooms', $active); ?>
      <?php nav_link('reports.php', 'Reports', 'reports', $active); ?>
      <?php nav_link('tenant_control.php', 'Control', 'control', $active); ?>

      <?php if (current_admin_role() === 'caretaker' || isSuperAdmin()): ?>
        <?php nav_link('caretaker_overdue.php', 'Caretaker', 'caretaker', $active); ?>
      <?php endif; ?>

      <?php if (isSuperAdmin()): ?>
        <?php nav_link('audit_logs.php', 'Audit Logs', 'audit', $active); ?>
      <?php endif; ?>

      <a href="logout.php"
         class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-50">
        Logout
      </a>
    </div>
  </div>
</header>
