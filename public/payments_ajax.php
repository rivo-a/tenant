<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/tenant_filter.php';

require_login();

$admin_id = current_admin_id();
if ($admin_id <= 0) {
    http_response_code(403);
    exit('Access denied: admin session not found.');
}

$getString = static function (string $key, string $default = ''): string {
    $value = $_GET[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
};

$page = max(1, (int)$getString('page', '1'));
$perPage = 20;

$params = [
    'search' => $getString('q'),
    'room_type' => $getString('room_type'),
    'tenant_status' => 'active',
    'payment_status' => $getString('payment_status'),
    'tenant_id' => $getString('tenant_id', '0'),
    'limit' => $perPage + 1,
    'offset' => ($page - 1) * $perPage,
    'sort_by' => $getString('sort_by', 'full_name'),
    'sort_order' => $getString('sort_order', 'ASC'),
];

$tenants = getFilteredTenants(getDB(), $admin_id, $params);
$hasNext = count($tenants) > $perPage;
if ($hasNext) {
    $tenants = array_slice($tenants, 0, $perPage);
}

$paymentActionQuery = array_filter([
    'q' => $params['search'],
    'room_type' => $params['room_type'],
    'tenant_status' => 'active',
    'payment_status' => $params['payment_status'],
    'tenant_id' => $params['tenant_id'],
    'page' => $page,
    'sort_by' => $params['sort_by'],
    'sort_order' => strtoupper($params['sort_order']) === 'DESC' ? 'DESC' : 'ASC',
], static fn($value): bool => $value !== '' && $value !== null);

header('Content-Type: text/html; charset=UTF-8');
header('X-Page: ' . $page);
header('X-Has-Next: ' . ($hasNext ? '1' : '0'));
header('X-Has-Previous: ' . ($page > 1 ? '1' : '0'));

$paymentFormData = [];
require __DIR__ . '/partials/payment_tenant_rows.php';
