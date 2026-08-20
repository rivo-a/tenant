<?php
declare(strict_types=1);

function currentUserRole(): string
{
    return current_admin_role();
}

function isSuperAdmin(): bool
{
    return currentUserRole() === 'super_admin';
}

function requireRole(array $roles): void
{
    if (!in_array(currentUserRole(), $roles, true)) {
        http_response_code(403);
        echo "<h3>Access Denied</h3>";
        echo "<p>You do not have permission to access this page.</p>";
        exit;
    }
}

function requireSuperAdmin(): void
{
    requireRole(['super_admin']);
}

function requireCaretaker(): void
{
    requireRole(['caretaker', 'super_admin']); // allow super admin too
}
?>