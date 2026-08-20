<?php
declare(strict_types=1);

/**
 * Tenant Filter Query (Refactored)
 * - Fixes schema mismatch: room_types.default_monthly_rent (not default_rent)
 * - Effective rent priority: tenant.monthly_rent -> rooms.monthly_rent -> room_types.default_monthly_rent
 * - Safe sorting, pagination, and filters
 */

function getFilteredTenants(PDO $pdo, int $admin_id, array $params): array
{
    // Current month (YYYY-MM) for "paid_this_month"
    $month = date('Y-m');

    // --- Normalize inputs (safe defaults) ---
    $search       = isset($params['search']) ? trim((string)$params['search']) : '';
    $roomType     = isset($params['room_type']) ? trim((string)$params['room_type']) : '';
    $tenantStatus = isset($params['tenant_status']) ? trim((string)$params['tenant_status']) : '';

    $limit  = isset($params['limit']) ? (int)$params['limit'] : 10;
    $offset = isset($params['offset']) ? (int)$params['offset'] : 0;

    if ($limit < 1) $limit = 10;
    if ($limit > 200) $limit = 200; // hard cap
    if ($offset < 0) $offset = 0;

    $sortOrder = (isset($params['sort_order']) && strtoupper((string)$params['sort_order']) === 'DESC')
        ? 'DESC'
        : 'ASC';

    // Safe mapping for ORDER BY
    $sortMap = [
        'full_name'              => 't.full_name',
        'room_number'            => 'r.room_number',
        'rent_due_date'          => 't.rent_due_date',
        'monthly_rent_effective' => 'monthly_rent_effective',
        'paid_this_month'        => 'paid_this_month',
        'outstanding_balance'    => 'outstanding_balance',
        'status'                 => 't.status',
    ];

    $sortKey  = isset($params['sort_by']) ? (string)$params['sort_by'] : 'full_name';
    $sortExpr = $sortMap[$sortKey] ?? 't.full_name';

    // --- Base SQL ---
    // Note: "monthly_rent_effective" is computed once and reused.
    // Note: We also compute "outstanding_balance" in SQL so you can sort by it safely.
    $sql = "
        SELECT
            t.id,
            t.full_name,
            t.phone,
            t.rent_due_date,
            t.status,

            r.room_number,
            rt.name AS room_type,

            /* Effective rent (tenant override -> room override -> room type default) */
            COALESCE(
                NULLIF(t.monthly_rent, 0),
                NULLIF(r.monthly_rent, 0),
                rt.default_monthly_rent,
                0
            ) AS monthly_rent_effective,

            /* Paid this month */
            COALESCE(SUM(p.amount), 0) AS paid_this_month,

            /* Outstanding = effective rent - paid (never negative) */
            MAX(
                0,
                COALESCE(
                    NULLIF(t.monthly_rent, 0),
                    NULLIF(r.monthly_rent, 0),
                    rt.default_monthly_rent,
                    0
                ) - COALESCE(SUM(p.amount), 0)
            ) AS outstanding_balance

        FROM tenants t

        LEFT JOIN rooms r
            ON r.id = t.room_id

        LEFT JOIN room_types rt
            ON rt.id = r.room_type_id

        LEFT JOIN payments p
            ON p.tenant_id = t.id
            AND strftime('%Y-%m', p.payment_date) = :month

        WHERE t.admin_id = :admin_id
    ";

    $bind = [
        ':admin_id' => $admin_id,
        ':month'    => $month,
    ];

    // --- Filters ---
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

    if ($tenantStatus !== '') {
        $sql .= " AND LOWER(t.status) = LOWER(:tenant_status) ";
        $bind[':tenant_status'] = $tenantStatus;
    }

    // --- Grouping (safe & explicit) ---
    // We group all non-aggregated columns to avoid SQLite picking arbitrary values.
    $sql .= "
        GROUP BY
            t.id, t.full_name, t.phone, t.rent_due_date, t.status, t.monthly_rent,
            r.room_number, r.monthly_rent,
            rt.name, rt.default_monthly_rent
    ";

    // --- Sorting (safe) ---
    $sql .= " ORDER BY {$sortExpr} {$sortOrder} ";

    // --- Pagination ---
    $sql .= " LIMIT :limit OFFSET :offset ";

    $stmt = $pdo->prepare($sql);

    foreach ($bind as $k => $v) {
        $stmt->bindValue($k, $v);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Normalize numeric fields (optional but clean)
    foreach ($rows as &$t) {
        $t['monthly_rent_effective'] = (float)($t['monthly_rent_effective'] ?? 0);
        $t['paid_this_month']        = (float)($t['paid_this_month'] ?? 0);
        $t['outstanding_balance']    = (float)($t['outstanding_balance'] ?? 0);
    }
    unset($t);

    return $rows;
}
?>