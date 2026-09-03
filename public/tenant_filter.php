<?php
declare(strict_types=1);

/**
 * Tenant roster query used by the receive-payment workflow.
 *
 * The default contract is active tenants only. Callers may opt into exited
 * records for a read-only use case, but payment pages never do so.
 */
function getFilteredTenants(PDO $pdo, int $admin_id, array $params): array
{
    $month = date('Y-m');

    $search = isset($params['search']) && is_scalar($params['search'])
        ? trim((string)$params['search'])
        : '';
    $roomType = isset($params['room_type']) && is_scalar($params['room_type'])
        ? trim((string)$params['room_type'])
        : '';
    $tenantStatus = isset($params['tenant_status']) && is_scalar($params['tenant_status'])
        ? strtolower(trim((string)$params['tenant_status']))
        : '';
    $paymentStatus = isset($params['payment_status']) && is_scalar($params['payment_status'])
        ? strtolower(trim((string)$params['payment_status']))
        : '';
    $tenantId = isset($params['tenant_id']) && is_scalar($params['tenant_id'])
        ? filter_var($params['tenant_id'], FILTER_VALIDATE_INT)
        : false;
    $tenantId = $tenantId === false ? 0 : max(0, (int)$tenantId);

    $limit = isset($params['limit']) && is_scalar($params['limit'])
        ? (int)$params['limit']
        : 10;
    $offset = isset($params['offset']) && is_scalar($params['offset'])
        ? (int)$params['offset']
        : 0;

    if ($limit < 1) $limit = 10;
    if ($limit > 200) $limit = 200;
    if ($offset < 0) $offset = 0;

    $sortOrder = isset($params['sort_order']) && is_scalar($params['sort_order'])
        && strtoupper((string)$params['sort_order']) === 'DESC'
        ? 'DESC'
        : 'ASC';

    $sortMap = [
        'full_name'              => 't.full_name',
        'room_number'            => 'r.room_number',
        'rent_due_date'          => 't.rent_due_date',
        'monthly_rent_effective' => 'monthly_rent_effective',
        'paid_this_month'        => 'paid_this_month',
        'outstanding_balance'    => 'outstanding_balance',
        'status'                 => 't.status',
    ];

    $sortKey = isset($params['sort_by']) && is_scalar($params['sort_by'])
        ? (string)$params['sort_by']
        : 'full_name';
    $sortExpr = $sortMap[$sortKey] ?? 't.full_name';

    $rentExpression = "COALESCE(
        NULLIF(t.monthly_rent, 0),
        NULLIF(r.monthly_rent, 0),
        rt.default_monthly_rent,
        0
    )";

    $sql = "
        SELECT
            t.id,
            t.full_name,
            t.phone,
            t.rent_due_date,
            t.status,
            t.exit_date,
            r.room_number,
            rt.name AS room_type,
            {$rentExpression} AS monthly_rent_effective,
            COALESCE(SUM(p.amount), 0) AS paid_this_month,
            MAX(
                0,
                {$rentExpression} - COALESCE(SUM(p.amount), 0)
            ) AS outstanding_balance
        FROM tenants t
        LEFT JOIN rooms r
            ON r.id = t.room_id
        LEFT JOIN room_types rt
            ON rt.id = r.room_type_id
        LEFT JOIN payments p
            ON p.tenant_id = t.id
            AND p.admin_id = :payment_admin_id
            AND strftime('%Y-%m', p.payment_date) = :month
        WHERE t.admin_id = :admin_id
    ";

    $bind = [
        ':admin_id'         => $admin_id,
        ':payment_admin_id' => $admin_id,
        ':month'            => $month,
    ];

    $includeExited = filter_var($params['include_exited'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$includeExited) {
        // Receive Payment is always an active roster. Keep both fields guarded
        // so stale or partially migrated records cannot become payable.
        $sql .= "
            AND LOWER(COALESCE(t.status, '')) = 'active'
            AND t.exit_date IS NULL
        ";
    } elseif (in_array($tenantStatus, ['active', 'inactive', 'exited'], true)) {
        $sql .= " AND LOWER(COALESCE(t.status, '')) = :tenant_status ";
        $bind[':tenant_status'] = $tenantStatus;
    }

    if ($tenantId > 0) {
        $sql .= " AND t.id = :tenant_id ";
        $bind[':tenant_id'] = $tenantId;
    }

    if ($search !== '') {
        $sql .= "
            AND (
                t.full_name LIKE :search
                OR t.phone LIKE :search
                OR r.room_number LIKE :search
            )
        ";
        $bind[':search'] = '%' . $search . '%';
    }

    if ($roomType !== '') {
        $sql .= " AND rt.name = :room_type ";
        $bind[':room_type'] = $roomType;
    }

    $sql .= "
        GROUP BY
            t.id,
            t.full_name,
            t.phone,
            t.rent_due_date,
            t.status,
            t.exit_date,
            t.monthly_rent,
            r.room_number,
            r.monthly_rent,
            rt.name,
            rt.default_monthly_rent
    ";

    if (in_array($paymentStatus, ['paid', 'partial', 'unpaid'], true)) {
        $paidExpression = 'COALESCE(SUM(p.amount), 0)';

        $sql .= match ($paymentStatus) {
            'paid' => " HAVING {$rentExpression} > 0 AND {$paidExpression} >= {$rentExpression} ",
            'partial' => " HAVING {$paidExpression} > 0 AND {$paidExpression} < {$rentExpression} ",
            'unpaid' => " HAVING {$paidExpression} <= 0 ",
        };
    }

    $sql .= " ORDER BY {$sortExpr} {$sortOrder}, t.id ASC LIMIT :limit OFFSET :offset ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':admin_id', $bind[':admin_id'], PDO::PARAM_INT);
    $stmt->bindValue(':payment_admin_id', $bind[':payment_admin_id'], PDO::PARAM_INT);
    $stmt->bindValue(':month', $bind[':month'], PDO::PARAM_STR);

    foreach ($bind as $key => $value) {
        if (in_array($key, [':admin_id', ':payment_admin_id', ':month'], true)) {
            continue;
        }
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$tenant) {
        $tenant['monthly_rent_effective'] = (float)($tenant['monthly_rent_effective'] ?? 0);
        $tenant['paid_this_month'] = (float)($tenant['paid_this_month'] ?? 0);
        $tenant['outstanding_balance'] = (float)($tenant['outstanding_balance'] ?? 0);
    }
    unset($tenant);

    return $rows;
}
