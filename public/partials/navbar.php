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
    $current = $key === $active ? ' aria-current="page"' : '';

    echo '<a href="' . e($href) . '" class="min-h-11 inline-flex items-center justify-center rounded-xl border px-3 py-2 text-sm font-semibold transition ' . $cls . '"' . $current . '>' . e($label) . '</a>';
}
?>

<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/80 backdrop-blur">
  <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
    <!-- Brand -->
    <div class="flex items-center gap-3">
      <div class="grid h-10 w-10 place-items-center rounded-2xl bg-slate-900 font-black text-white">
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
      id="mobileNavToggle"
      type="button"
      class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:hidden"
      aria-controls="mobileNav"
      aria-expanded="false"
    >
      Menu
    </button>

    <!-- Desktop nav -->
    <nav class="hidden flex-wrap items-center justify-end gap-2 sm:flex" aria-label="Primary navigation">
      <?php nav_link('payments.php', 'Payments', 'payments', $active); ?>
      <?php nav_link('payments_history.php', 'History', 'history', $active); ?>
      <?php nav_link('dashboard.php', 'Dashboard', 'dashboard', $active); ?>
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
         class="ml-2 inline-flex min-h-11 items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
        Logout
      </a>
    </nav>
  </div>

  <!-- Mobile nav -->
  <nav id="mobileNav" class="hidden border-t border-slate-200 bg-white sm:hidden" aria-label="Mobile navigation">
    <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-3">
      <?php nav_link('payments.php', 'Payments', 'payments', $active); ?>
      <?php nav_link('payments_history.php', 'History', 'history', $active); ?>
      <?php nav_link('dashboard.php', 'Dashboard', 'dashboard', $active); ?>
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
         class="inline-flex min-h-11 items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
        Logout
      </a>
    </div>
  </nav>
</header>

<script src="assets/js/tenant-control.js" defer></script>
