<?php
declare(strict_types=1);

/**
 * Shared navigation partial.
 * Set $active before including this file.
 *
 * Links are gated by capability, and a rent collector gets a single link:
 * navigation is a convenience, never the access control. Every target also
 * refuses the request server-side.
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
    $classes = nav_active($key, $active);
    $current = $key === $active ? ' aria-current="page"' : '';

    echo '<a href="' . e($href) . '" class="min-h-11 inline-flex items-center justify-center rounded-xl border px-3 py-2 text-sm font-semibold transition active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 ' . $classes . '"' . $current . '>' . e($label) . '</a>';
}

$secondaryKeys = ['history', 'tenant_payments', 'reports', 'control', 'maintenance', 'staff', 'caretaker', 'audit'];
$moreIsActive = in_array($active, $secondaryKeys, true);
$moreClasses = $moreIsActive
    ? 'bg-slate-900 text-white border-slate-900'
    : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50';

$isCollector = isRentCollector();

$showStaff = can('staff.manage_collectors');
$showAudit = can('audit.view');
$homeHref = auth_home_for_role();
?>

<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur">
  <div class="mx-auto flex max-w-7xl items-center justify-between gap-2 px-4 py-3 sm:gap-3 sm:px-6 lg:flex-nowrap">
    <a href="<?= e($homeHref) ?>" class="flex min-w-0 shrink-0 items-center gap-3 rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900" aria-label="Urbahan home">
      <img class="w-10" src="/tenant-system/public/assets/urbahan-logo.png" alt="">
      <span class="min-w-0 leading-tight">
        <span class="block truncate font-extrabold text-slate-900">Urbahan</span>
        <span class="block truncate text-xs text-slate-500">
          Logged in as <?= e($_SESSION['admin']['name'] ?? ($_SESSION['admin_name'] ?? 'Admin')) ?>
          <span class="ml-1 hidden items-center rounded-full border border-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-600 sm:inline-flex">
            <?= e(auth_role_label(auth_role())) ?>
          </span>
        </span>
      </span>
    </a>

    <?php if (!$isCollector): ?>
    <button
      id="mobileNavToggle"
      type="button"
      class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 lg:hidden"
      aria-controls="mobileNav"
      aria-expanded="false"
    >
      Menu
    </button>
    <?php endif; ?>

    <nav class="hidden min-w-0 flex-1 items-center justify-end gap-2 lg:flex" aria-label="Primary navigation">
      <?php if ($isCollector): ?>
        <?php nav_link('caretaker_overdue.php', 'Overdue tenants', 'caretaker', 'caretaker'); ?>
      <?php else: ?>
        <?php if (can('payments.record')): ?>
          <?php nav_link('payments.php', 'Payments', 'payments', $active); ?>
        <?php endif; ?>
        <?php if (can('dashboard.view')): ?>
          <?php nav_link('dashboard.php', 'Dashboard', 'dashboard', $active); ?>
        <?php endif; ?>
        <?php if (can('tenants.manage')): ?>
          <?php nav_link('tenants.php', 'Tenants', 'tenants', $active); ?>
        <?php endif; ?>
        <?php if (can('rooms.manage')): ?>
          <?php nav_link('rooms.php', 'Rooms', 'rooms', $active); ?>
        <?php endif; ?>

        <details id="desktopMore" class="relative shrink-0">
          <summary class="min-h-11 inline-flex cursor-pointer list-none items-center justify-center rounded-xl border px-3 py-2 text-sm font-semibold transition active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 <?= $moreClasses ?>">
            More <span aria-hidden="true" class="ml-1 text-xs">⌄</span>
          </summary>
          <div class="absolute right-0 top-[calc(100%+0.5rem)] z-50 grid min-w-48 gap-1 rounded-2xl border border-slate-200 bg-white p-2 shadow-lg">
            <?php if (can('payments.view')): ?>
              <?php nav_link('payments_history.php', 'History', 'history', $active); ?>
              <?php nav_link('tenant_payments.php', 'Tenant Payments', 'tenant_payments', $active); ?>
            <?php endif; ?>
            <?php if (can('reports.view')): ?>
              <?php nav_link('reports.php', 'Reports', 'reports', $active); ?>
            <?php endif; ?>
            <?php if (can('portal.admin')): ?>
              <?php nav_link('tenant_control.php', 'Control', 'control', $active); ?>
            <?php endif; ?>
            <?php if (can('tenants.manage')): ?>
              <?php nav_link('maintenance_requests.php', 'Maintenance', 'maintenance', $active); ?>
            <?php endif; ?>
            <?php if ($showStaff): ?>
              <?php nav_link('admin_management.php', 'Staff', 'staff', $active); ?>
            <?php endif; ?>
            <?php if ($showAudit): ?>
              <?php nav_link('audit_logs.php', 'Audit Logs', 'audit', $active); ?>
            <?php endif; ?>
          </div>
        </details>
      <?php endif; ?>

      <a href="logout.php"
         class="ml-1 inline-flex min-h-11 shrink-0 items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
        Logout
      </a>
    </nav>
  </div>

  <?php if (!$isCollector): ?>
  <nav id="mobileNav" class="hidden border-t border-slate-200 bg-white lg:hidden" aria-label="Mobile navigation">
    <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-3 sm:px-6">
      <?php if (can('payments.record')): ?>
        <?php nav_link('payments.php', 'Payments', 'payments', $active); ?>
      <?php endif; ?>
      <?php if (can('dashboard.view')): ?>
        <?php nav_link('dashboard.php', 'Dashboard', 'dashboard', $active); ?>
      <?php endif; ?>
      <?php if (can('tenants.manage')): ?>
        <?php nav_link('tenants.php', 'Tenants', 'tenants', $active); ?>
      <?php endif; ?>
      <?php if (can('rooms.manage')): ?>
        <?php nav_link('rooms.php', 'Rooms', 'rooms', $active); ?>
      <?php endif; ?>
      <?php if (can('payments.view')): ?>
        <?php nav_link('payments_history.php', 'History', 'history', $active); ?>
        <?php nav_link('tenant_payments.php', 'Tenant Payments', 'tenant_payments', $active); ?>
      <?php endif; ?>
      <?php if (can('reports.view')): ?>
        <?php nav_link('reports.php', 'Reports', 'reports', $active); ?>
      <?php endif; ?>
      <?php if (can('portal.admin')): ?>
        <?php nav_link('tenant_control.php', 'Control', 'control', $active); ?>
      <?php endif; ?>
      <?php if (can('tenants.manage')): ?>
        <?php nav_link('maintenance_requests.php', 'Maintenance', 'maintenance', $active); ?>
      <?php endif; ?>
      <?php if ($showStaff): ?>
        <?php nav_link('admin_management.php', 'Staff', 'staff', $active); ?>
      <?php endif; ?>
      <?php if ($showAudit): ?>
        <?php nav_link('audit_logs.php', 'Audit Logs', 'audit', $active); ?>
      <?php endif; ?>

      <a href="logout.php"
         class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
        Logout
      </a>
    </div>
  </nav>
  <?php endif; ?>
</header>

<script src="assets/js/tenant-control.js" defer></script>
