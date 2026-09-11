<?php
declare(strict_types=1);
require_once __DIR__ . '/../../core/tenant_auth.php';

// Destroy the secure tenant session
destroyTenantSession();

// Redirect to login
header('Location: /tenant-system/public/portal/login.php');
exit;
?>