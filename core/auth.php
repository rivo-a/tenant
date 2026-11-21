<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authenticated identity (single source of truth)
|--------------------------------------------------------------------------
| The session only carries who is logged in. Role, suspension state and the
| landlord a staff member belongs to are re-read from `admins` on every
| request, so a role change or deactivation takes effect immediately instead
| of lasting until the browser session is dropped.
|
| Role vocabulary:
|   super_admin      platform owner, operates every business
|   landlord         SaaS customer, owns the business and its data
|   property_manager delegated operator inside one landlord's business
|   rent_collector   field worker, read-only overdue list for one business
|
| Legacy rows still store 'caretaker' for a landlord. That value is aliased to
| 'landlord' here at read time; no role value is rewritten.
|
| Ownership scope is `admin_id` on business tables. For a landlord that is
| their own id; for staff it is the landlord they belong to (admins.landlord_id).
| Every page must scope with current_business_id(), never with the raw session.
*/

if (!function_exists('auth_role_vocabulary')) {
    /** @return list<string> */
    function auth_role_vocabulary(): array
    {
        return ['super_admin', 'landlord', 'property_manager', 'rent_collector'];
    }
}

if (!function_exists('auth_normalise_role')) {
    function auth_normalise_role(?string $role): string
    {
        $role = strtolower(trim((string)$role));

        if ($role === 'caretaker' || $role === 'admin' || $role === 'owner') {
            return 'landlord';
        }

        return in_array($role, auth_role_vocabulary(), true) ? $role : 'landlord';
    }
}

if (!function_exists('auth_role_label')) {
    function auth_role_label(?string $role): string
    {
        return match (auth_normalise_role($role)) {
            'super_admin' => 'Platform owner',
            'property_manager' => 'Property manager',
            'rent_collector' => 'Rent collector',
            default => 'Landlord',
        };
    }
}

if (!function_exists('auth_user')) {
    /**
     * The live `admins` row for the logged-in identity, cached per request.
     *
     * @return array<string, mixed>|null null when the account no longer exists
     */
    function auth_user(): ?array
    {
        static $resolved = false;
        static $user = null;

        if ($resolved) {
            return $user;
        }

        $resolved = true;
        $id = (int)($_SESSION['admin']['id'] ?? $_SESSION['admin_id'] ?? 0);
        if ($id <= 0) {
            return $user = null;
        }

        try {
            $stmt = getDB()->prepare(
                'SELECT id, username, full_name, role, is_active, landlord_id
                 FROM admins
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $ignored) {
            return $user = null;
        }

        return $user = $row ?: null;
    }
}

if (!function_exists('auth_role')) {
    /** Role straight from the database, aliased onto the current vocabulary. */
    function auth_role(): string
    {
        $user = auth_user();

        if ($user === null) {
            return auth_normalise_role($_SESSION['admin_role'] ?? null);
        }

        return auth_normalise_role($user['role'] ?? null);
    }
}

if (!function_exists('auth_is_active')) {
    function auth_is_active(): bool
    {
        $user = auth_user();

        if ($user === null) {
            return false;
        }

        return (int)($user['is_active'] ?? 0) === 1;
    }
}

if (!function_exists('current_business_id')) {
    /**
     * The landlord whose records this session may touch.
     * Landlord accounts own their data; staff inherit the landlord's id.
     */
    function current_business_id(): int
    {
        $user = auth_user();

        if ($user === null) {
            return 0;
        }

        $ownId = (int)($user['id'] ?? 0);
        $landlordId = (int)($user['landlord_id'] ?? 0);

        return $landlordId > 0 ? $landlordId : $ownId;
    }
}

if (!function_exists('auth_session_matches_database')) {
    /**
     * The session must not keep privileges it no longer has. When the stored
     * role drifts from the database row, the session is rewritten from the DB.
     */
    function auth_session_matches_database(): void
    {
        $user = auth_user();
        if ($user === null) {
            return;
        }

        $dbRole = (string)($user['role'] ?? '');
        $sessionRole = (string)($_SESSION['admin']['role'] ?? $_SESSION['admin_role'] ?? '');

        if ($dbRole === $sessionRole && isset($_SESSION['admin']['id'])) {
            return;
        }

        $_SESSION['admin'] = [
            'id' => (int)$user['id'],
            'name' => (string)($user['full_name'] ?? ''),
            'role' => $dbRole,
        ];
        $_SESSION['admin_id'] = (int)$user['id'];
        $_SESSION['admin_name'] = (string)($user['full_name'] ?? '');
        $_SESSION['admin_role'] = $dbRole;
    }
}

if (!function_exists('auth_wants_json')) {
    function auth_wants_json(): bool
    {
        $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }
}

if (!function_exists('auth_end_session')) {
    function auth_end_session(): never
    {
        $_SESSION = [];
        session_destroy();

        if (auth_wants_json()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'session_revoked']);
            exit;
        }

        redirect('login.php');
    }
}

if (!function_exists('auth_enforce_session')) {
    /**
     * Called from bootstrap. A deleted or suspended account is ejected on its
     * next request, including an already-issued session cookie.
     */
    function auth_enforce_session(): void
    {
        if (!isset($_SESSION['admin_id']) && !isset($_SESSION['admin']['id'])) {
            return;
        }

        $user = auth_user();

        if ($user === null || (int)($user['is_active'] ?? 0) !== 1) {
            audit_log_event(
                getDB(),
                0,
                'SESSION_REVOKED',
                'Session ended because the account was removed or suspended.',
                [
                    'actor_type' => 'anonymous',
                    'actor_id' => (int)($_SESSION['admin_id'] ?? 0),
                ]
            );
            auth_end_session();
        }

        auth_session_matches_database();
    }
}
