<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Capability checks
|--------------------------------------------------------------------------
| Roles are grouped into named capabilities so a page asks for what it does
| ("payments.record"), not for a list of role names. Adding a role later means
| editing the matrix here, not every entry point.
|
| A rent collector holds only 'collector.overdue': no financial, tenant,
| property, staff or report capability, by construction.
*/

if (!function_exists('capability_matrix')) {
    /**
     * @return array<string, list<string>>
     */
    function capability_matrix(): array
    {
        return [
            'platform.admin' => ['super_admin'],
            'dashboard.view' => ['super_admin', 'landlord', 'property_manager'],
            'tenants.view' => ['super_admin', 'landlord', 'property_manager'],
            'tenants.manage' => ['super_admin', 'landlord', 'property_manager'],
            'rooms.manage' => ['super_admin', 'landlord', 'property_manager'],
            'payments.view' => ['super_admin', 'landlord', 'property_manager'],
            'payments.record' => ['super_admin', 'landlord', 'property_manager'],
            'payments.verify' => ['super_admin', 'landlord', 'property_manager'],
            'reports.view' => ['super_admin', 'landlord', 'property_manager'],
            'portal.admin' => ['super_admin', 'landlord', 'property_manager'],
            'properties.manage' => ['super_admin', 'landlord', 'property_manager'],
            'audit.view' => ['super_admin', 'landlord'],
            'staff.manage' => ['super_admin', 'landlord'],
            'staff.manage_collectors' => ['super_admin', 'landlord', 'property_manager'],
            'collector.overdue' => ['super_admin', 'rent_collector'],
        ];
    }
}

if (!function_exists('currentUserRole')) {
    function currentUserRole(): string
    {
        return current_admin_role();
    }
}

if (!function_exists('isSuperAdmin')) {
    function isSuperAdmin(): bool
    {
        return auth_role() === 'super_admin';
    }
}

if (!function_exists('isRentCollector')) {
    function isRentCollector(): bool
    {
        return auth_role() === 'rent_collector';
    }
}

if (!function_exists('can')) {
    function can(string $capability): bool
    {
        $matrix = capability_matrix();
        $roles = $matrix[$capability] ?? [];

        return in_array(auth_role(), $roles, true);
    }
}

if (!function_exists('deny_access')) {
    /**
     * Single refusal path: server-side 403, audited with the real actor,
     * role and business scope. No privilege is granted client-side.
     */
    function deny_access(string $capability = '', string $reason = 'Blocked by access policy.'): never
    {
        $actorId = (int)($_SESSION['admin_id'] ?? 0);
        $details = trim($capability . ' ' . $reason)
            . ' role=' . auth_role()
            . ' path=' . (string)($_SERVER['REQUEST_URI'] ?? '');

        try {
            audit_log_event(
                getDB(),
                $actorId,
                'ACCESS_DENIED',
                $details,
                [
                    'target_type' => 'capability',
                    'actor_type' => $actorId > 0 ? 'admin' : 'anonymous',
                    'owner_admin_id' => current_business_id() > 0 ? current_business_id() : null,
                ]
            );
        } catch (Throwable $ignored) {
            // Refusal must never depend on audit storage.
        }

        if (auth_wants_json()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'forbidden', 'capability' => $capability]);
            exit;
        }

        http_response_code(403);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Access denied</title>';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<link rel="stylesheet" href="assets/css/tailwind.css"></head>';
        echo '<body class="min-h-screen bg-slate-50 text-slate-900">';
        echo '<main class="mx-auto flex max-w-lg flex-col gap-3 px-6 py-24">';
        echo '<h1 class="text-2xl font-bold tracking-tight">Access denied</h1>';
        echo '<p class="text-sm text-slate-600">This account does not have permission for that page.</p>';
        echo '<p class="text-sm text-slate-600">Signed in as ' . e(auth_role_label(auth_role())) . '.</p>';
        echo '<a class="mt-2 inline-flex w-fit items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white" href="' . e(auth_home_for_role()) . '">Go to your page</a>';
        echo '</main></body></html>';
        exit;
    }
}

if (!function_exists('require_capability')) {
    function require_capability(string $capability): void
    {
        if (!can($capability)) {
            deny_access($capability, 'Requires ' . $capability . '.');
        }
    }
}

if (!function_exists('auth_home_for_role')) {
    /** Where each role lands after login, and the only page a collector can use. */
    function auth_home_for_role(?string $role = null): string
    {
        return auth_normalise_role($role ?? auth_role()) === 'rent_collector'
            ? 'caretaker_overdue.php'
            : 'dashboard.php';
    }
}

if (!function_exists('requireRole')) {
    /** Legacy helper kept for older pages. Checks the normalised role. */
    function requireRole(array $roles): void
    {
        $allowed = array_map('auth_normalise_role', $roles);

        if (!in_array(auth_role(), $allowed, true)) {
            deny_access('', 'Requires role: ' . implode(', ', $allowed) . '.');
        }
    }
}

if (!function_exists('requireSuperAdmin')) {
    function requireSuperAdmin(): void
    {
        require_capability('platform.admin');
    }
}

if (!function_exists('requireCaretaker')) {
    /**
     * Legacy name. It used to mean "any admin"; it now means "may manage
     * tenants", which is the correct reading for its three call sites.
     */
    function requireCaretaker(): void
    {
        require_capability('tenants.manage');
    }
}
