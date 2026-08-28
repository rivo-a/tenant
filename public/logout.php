<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

$adminId = current_admin_id();
if ($adminId > 0 && function_exists('logAudit')) {
    try {
        logAudit($adminId, 'LOGOUT', 'Admin logged out.');
    } catch (Throwable $ignored) {}
}

logout();
