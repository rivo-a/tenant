<?php
declare(strict_types=1);

/**
 * Serves a maintenance attachment to the tenant who raised it, or to staff of
 * the landlord's business. Files live outside the web root, so this is the
 * only way in and every request is authorised against the request's owner.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/MaintenanceService.php';

$requestId = (int)($_GET['request'] ?? 0);

if ($requestId <= 0) {
    http_response_code(404);
    exit('Not found.');
}

$service = new MaintenanceService(getDB());
$file = null;

$isTenantSession = isset($_SESSION['tenant_id']) && ($_SESSION['tenant_role'] ?? '') === 'tenant';

if ($isTenantSession) {
    require_once __DIR__ . '/../core/tenant_auth.php';
    requireTenantAuth();
    $file = $service->attachmentForTenant((int)$_SESSION['tenant_id'], $requestId);
} elseif (isset($_SESSION['admin_id']) || isset($_SESSION['admin']['id'])) {
    require_login();
    require_capability('tenants.view');
    $file = $service->attachmentForBusiness(current_business_id(), $requestId);
} else {
    http_response_code(403);
    exit('Access denied.');
}

if ($file === null) {
    http_response_code(404);
    exit('Attachment not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['path']) ?: 'application/octet-stream';
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

if (!in_array($mime, $allowed, true)) {
    http_response_code(415);
    exit('Unsupported attachment type.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file['path']));
header('Content-Disposition: inline; filename="' . $file['name'] . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

readfile($file['path']);
exit;
