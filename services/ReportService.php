<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/rent.php';
require_once __DIR__ . '/ArrearsService.php';

/**
 * Every landlord-facing report reads from here.
 *
 * Money rules (single source of truth, shared with ArrearsService):
 *   collected  = payments with verification_status confirmed/done
 *   pending    = recorded but not verified; never counted as collected
 *   reversed   = kept, shown, excluded from every collected total
 *   outstanding/overdue = ArrearsService, never recomputed here
 *
 * "Expected for a period" bills each active tenant's rent for the months of
 * that period which exist between their move-in date and the period end.
 */
final class ReportService
{
    /** Ageing bands for overdue balances, in days past the due date. */
    public const AGE_BUCKETS = [
        '0-7' => [0, 7],
        '8-30' => [8, 30],
        '31-60' => [31, 60],
        '61-90' => [61, 90],
        '90+' => [91, PHP_INT_MAX],
    ];

    private PDO $pdo;

    private ArrearsService $arrears;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->arrears = new ArrearsService($pdo);
    }

    /* ------------------------------------------------------------------
       Periods and filters
    ------------------------------------------------------------------ */

    /**
     * @return array{from: string, to: string, label: string, days: int}
     */
    public function resolvePeriod(?string $from, ?string $to): array
    {
        $defaultFrom = date('Y-m-01');
        $defaultTo = date('Y-m-d');

        $from = $this->parseDate($from) ?? $defaultFrom;
        $to = $this->parseDate($to) ?? $defaultTo;

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);

        // A single request must not scan years of history.
        if ($start->diff($end)->days > 400) {
            $from = $end->modify('-400 days')->format('Y-m-d');
            $start = new DateTimeImmutable($from);
        }

        return [
            'from' => $from,
            'to' => $to,
            'label' => $start->format('d M Y') . ' – ' . $end->format('d M Y'),
            'days' => (int)$start->diff($end)->days + 1,
        ];
    }

    /**
     * @return list<array{id: int, name: string, address: string, rooms: int}>
     */
    public function properties(int $adminId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT pr.id, pr.name, COALESCE(pr.address, '') AS address,
                   (SELECT COUNT(*) FROM rooms r WHERE r.property_id = pr.id AND r.admin_id = :room_admin) AS rooms
            FROM properties pr
            WHERE pr.admin_id = :admin_id
            ORDER BY pr.name
        ");
        $stmt->execute([':admin_id' => $adminId, ':room_admin' => $adminId]);

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'address' => (string)$row['address'],
            'rooms' => (int)$row['rooms'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Headline figures for the period plus the point-in-time arrears position.
     *
     * @param array{from: string, to: string} $period
     * @return array<string, float|int>
     */
    public function overview(int $adminId, array $period, int $propertyId = 0): array
    {
        $arrears = $this->arrears->getArrears($adminId, 0, null);
        $rows = $this->filterByProperty($arrears['rows'], $propertyId);

        $expected = 0.0;
        foreach ($rows as $row) {
            $months = $this->monthsInPeriod($row['move_in_date'] ?? null, $period['from'], $period['to']);
            $expected += (float)$row['rent'] * $months;
        }

        $money = $this->paymentTotals($adminId, $period, $propertyId);

        $outstanding = 0.0;
        $overdue = 0.0;
        $overdueTenants = 0;
        foreach ($rows as $row) {
            if ((float)$row['balance'] > 0.00001) {
                $outstanding += (float)$row['balance'];
            }
            if (!empty($row['is_overdue'])) {
                $overdue += (float)$row['balance'];
                $overdueTenants++;
            }
        }

        $occupancy = $this->occupancy($adminId, $propertyId);

        return [
            'expected' => round($expected, 2),
            'collected' => $money['collected'],
            'pending' => $money['pending'],
            'reversed' => $money['reversed'],
            'outstanding' => round($outstanding, 2),
            'overdue' => round($overdue, 2),
            'overdue_tenants' => $overdueTenants,
            'tenants' => count($rows),
            'collection_rate' => $expected > 0.00001 ? round($money['collected'] / $expected, 4) : 0.0,
            'rooms_total' => $occupancy['rooms_total'],
            'rooms_occupied' => $occupancy['rooms_occupied'],
            'rooms_vacant' => $occupancy['rooms_vacant'],
            'occupancy_rate' => $occupancy['occupancy_rate'],
        ];
    }

    /**
     * Month-by-month expected, collected and pending for charts.
     *
     * @return list<array{month: string, label: string, expected: float, collected: float, pending: float}>
     */
    public function monthlySeries(int $adminId, int $months = 6, int $propertyId = 0): array
    {
        $months = max(1, min(24, $months));
        $series = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = (new DateTimeImmutable(date('Y-m-01')))->modify('-' . $i . ' months');
            $end = $start->modify('last day of this month');
            $period = ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')];

            $rows = $this->filterByProperty(
                $this->arrears->getArrears($adminId, 0, $end)['rows'],
                $propertyId
            );

            $expected = 0.0;
            foreach ($rows as $row) {
                $expected += (float)$row['rent'] * $this->monthsInPeriod(
                    $row['move_in_date'] ?? null,
                    $period['from'],
                    $period['to']
                );
            }

            $money = $this->paymentTotals($adminId, $period, $propertyId);

            $series[] = [
                'month' => $start->format('Y-m'),
                'label' => $start->format('M'),
                'expected' => round($expected, 2),
                'collected' => $money['collected'],
                'pending' => $money['pending'],
            ];
        }

        return $series;
    }

    /**
     * Outstanding rent by ageing band, plus the rows behind it.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     buckets: list<array{label: string, tenants: int, amount: float}>,
     *     totals: array{outstanding: float, tenants: int, oldest_days: int}
     * }
     */
    public function arrears(int $adminId, int $propertyId = 0): array
    {
        $result = $this->arrears->getArrears($adminId, 0, null);
        $rows = array_values(array_filter(
            $this->filterByProperty($result['rows'], $propertyId),
            static fn(array $row): bool => (float)$row['balance'] > 0.00001
        ));

        $buckets = [];
        foreach (self::AGE_BUCKETS as $label => [$min, $max]) {
            $buckets[$label] = ['label' => $label . ' days', 'tenants' => 0, 'amount' => 0.0];
        }

        $outstanding = 0.0;
        $oldest = 0;

        foreach ($rows as $row) {
            $days = (int)$row['days_overdue'];
            $oldest = max($oldest, $days);
            $outstanding += (float)$row['balance'];

            foreach (self::AGE_BUCKETS as $label => [$min, $max]) {
                if ($days >= $min && $days <= $max) {
                    $buckets[$label]['tenants']++;
                    $buckets[$label]['amount'] += (float)$row['balance'];
                    break;
                }
            }
        }

        foreach ($buckets as $label => $bucket) {
            $buckets[$label]['amount'] = round($bucket['amount'], 2);
        }

        usort($rows, static fn(array $a, array $b): int => [$b['days_overdue'], $b['balance']] <=> [$a['days_overdue'], $a['balance']]);

        return [
            'rows' => $rows,
            'buckets' => array_values($buckets),
            'totals' => [
                'outstanding' => round($outstanding, 2),
                'tenants' => count($rows),
                'oldest_days' => $oldest,
            ],
        ];
    }

    /**
     * @param array{from: string, to: string} $period
     * @return list<array<string, mixed>>
     */
    public function propertyPerformance(int $adminId, array $period): array
    {
        $properties = $this->properties($adminId);
        $rows = $this->arrears->getArrears($adminId, 0, null)['rows'];

        $byProperty = [];
        foreach ($rows as $row) {
            $key = (int)$row['property_id'];
            $byProperty[$key]['expected'] = ($byProperty[$key]['expected'] ?? 0.0)
                + (float)$row['rent'] * $this->monthsInPeriod($row['move_in_date'] ?? null, $period['from'], $period['to']);
            $byProperty[$key]['outstanding'] = ($byProperty[$key]['outstanding'] ?? 0.0) + (float)$row['balance'];
        }

        $collected = $this->collectedByProperty($adminId, $period);

        $result = [];
        foreach ($properties as $property) {
            $id = $property['id'];
            $occupancy = $this->occupancy($adminId, $id);

            $result[] = [
                'id' => $id,
                'name' => $property['name'],
                'address' => $property['address'],
                'rooms_total' => $occupancy['rooms_total'],
                'rooms_occupied' => $occupancy['rooms_occupied'],
                'rooms_vacant' => $occupancy['rooms_vacant'],
                'occupancy_rate' => $occupancy['occupancy_rate'],
                'expected' => round((float)($byProperty[$id]['expected'] ?? 0), 2),
                'collected' => round((float)($collected[$id] ?? 0), 2),
                'outstanding' => round((float)($byProperty[$id]['outstanding'] ?? 0), 2),
            ];
        }

        $unassigned = $this->occupancy($adminId, 0)['rooms_total'] - array_sum(array_column($result, 'rooms_total'));
        if ($unassigned > 0) {
            $unassignedOccupancy = $this->unassignedRoomStats($adminId);
            $result[] = [
                'id' => 0,
                'name' => 'Unassigned rooms',
                'address' => 'Not placed in a property yet',
                'rooms_total' => $unassignedOccupancy['rooms_total'],
                'rooms_occupied' => $unassignedOccupancy['rooms_occupied'],
                'rooms_vacant' => $unassignedOccupancy['rooms_vacant'],
                'occupancy_rate' => $unassignedOccupancy['occupancy_rate'],
                'expected' => round((float)($byProperty[0]['expected'] ?? 0), 2),
                'collected' => round((float)($collected[0] ?? 0), 2),
                'outstanding' => round((float)($byProperty[0]['outstanding'] ?? 0), 2),
            ];
        }

        return $result;
    }

    /**
     * @return array{rooms_total: int, rooms_occupied: int, rooms_vacant: int, occupancy_rate: float}
     */
    public function occupancy(int $adminId, int $propertyId = 0): array
    {
        $sql = "
            SELECT
                COUNT(r.id) AS rooms_total,
                COALESCE(SUM(CASE WHEN occ.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS rooms_occupied
            FROM rooms r
            LEFT JOIN (
                SELECT DISTINCT t.room_id AS id
                FROM tenants t
                WHERE t.admin_id = :tenant_admin
                  AND LOWER(COALESCE(t.status, '')) = 'active'
                  AND t.exit_date IS NULL
                  AND t.room_id IS NOT NULL
            ) occ ON occ.id = r.id
            WHERE r.admin_id = :admin_id
        ";

        $params = [':tenant_admin' => $adminId, ':admin_id' => $adminId];

        if ($propertyId > 0) {
            $sql .= ' AND r.property_id = :property_id';
            $params[':property_id'] = $propertyId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['rooms_total' => 0, 'rooms_occupied' => 0];

        $total = (int)$row['rooms_total'];
        $occupied = (int)$row['rooms_occupied'];

        return [
            'rooms_total' => $total,
            'rooms_occupied' => $occupied,
            'rooms_vacant' => max(0, $total - $occupied),
            'occupancy_rate' => $total > 0 ? round($occupied / $total, 4) : 0.0,
        ];
    }

    /**
     * Payment ledger with filters and pagination.
     *
     * @param array{from?: string, to?: string, property?: int, status?: string, method?: string, search?: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function ledger(int $adminId, array $filters, int $page = 1, int $perPage = 25): array
    {
        $perPage = max(5, min(100, $perPage));
        $page = max(1, $page);

        [$where, $params] = $this->ledgerWhere($adminId, $filters);

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM payments p
             JOIN tenants t ON t.id = p.tenant_id
             LEFT JOIN rooms r ON r.id = t.room_id
             ' . $where
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);

        $stmt = $this->pdo->prepare("
            SELECT
                p.id, p.receipt_no, p.amount, p.payment_date, p.payment_month, p.method,
                p.verification_status, p.note, p.created_at,
                t.full_name AS tenant_name, t.phone AS tenant_phone,
                r.room_number, pr.name AS property_name,
                recorder.full_name AS recorded_by,
                verifier.full_name AS verified_by
            FROM payments p
            JOIN tenants t ON t.id = p.tenant_id
            LEFT JOIN rooms r ON r.id = t.room_id
            LEFT JOIN properties pr ON pr.id = r.property_id
            LEFT JOIN admins recorder ON recorder.id = p.admin_id
            LEFT JOIN admins verifier ON verifier.id = COALESCE(p.verification_done_by, p.verification_confirmed_by)
            {$where}
            ORDER BY p.payment_date DESC, p.id DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Whole filtered ledger for export, capped so one export cannot exhaust memory.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function ledgerForExport(int $adminId, array $filters, int $max = 5000): array
    {
        $result = $this->ledger($adminId, $filters, 1, min(100, $max));

        if ($result['total'] > count($result['rows'])) {
            $rows = $result['rows'];
            for ($page = 2; $page <= $result['pages'] && count($rows) < $max; $page++) {
                $chunk = $this->ledger($adminId, $filters, $page, 100);
                $rows = array_merge($rows, $chunk['rows']);
            }

            return array_slice($rows, 0, $max);
        }

        return $result['rows'];
    }

    /**
     * What staff actually did, from the audit log only. Nothing is inferred.
     *
     * @param array{from: string, to: string} $period
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function staffActivity(int $adminId, array $period): array
    {
        $stmt = $this->pdo->prepare("
            SELECT al.actor_id, COUNT(*) AS events, MAX(al.created_at) AS last_seen,
                   GROUP_CONCAT(DISTINCT action) AS actions,
                   MAX(COALESCE(a.full_name, 'Unknown')) AS actor_name
            FROM audit_logs al
            LEFT JOIN admins a ON a.id = al.actor_id
            WHERE al.actor_type = 'admin'
              AND al.created_at >= :from
              AND al.created_at <= :to
              AND (
                    al.owner_admin_id = :b1
                 OR al.actor_id IN (SELECT id FROM admins WHERE id = :b2 OR landlord_id = :b3)
              )
            GROUP BY actor_id
            ORDER BY events DESC
        ");
        $stmt->execute([
            ':from' => $period['from'] . ' 00:00:00',
            ':to' => $period['to'] . ' 23:59:59',
            ':b1' => $adminId,
            ':b2' => $adminId,
            ':b3' => $adminId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['rows' => $rows, 'total' => array_sum(array_map(static fn(array $r): int => (int)$r['events'], $rows))];
    }

    /* ------------------------------------------------------------------
       Internals
    ------------------------------------------------------------------ */

    /**
     * @param array{from: string, to: string} $period
     * @return array{collected: float, pending: float, reversed: float}
     */
    private function paymentTotals(int $adminId, array $period, int $propertyId = 0): array
    {
        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN " . payment_verified_sql('p') . " THEN p.amount ELSE 0 END), 0) AS collected,
                COALESCE(SUM(CASE WHEN " . payment_pending_sql('p') . " THEN p.amount ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN " . payment_reversed_sql('p') . " THEN p.amount ELSE 0 END), 0) AS reversed
            FROM payments p
            JOIN tenants t ON t.id = p.tenant_id
            LEFT JOIN rooms r ON r.id = t.room_id
            WHERE t.admin_id = :admin_id
              AND p.payment_date >= :from
              AND p.payment_date <= :to
        ";

        $params = [':admin_id' => $adminId, ':from' => $period['from'], ':to' => $period['to']];

        if ($propertyId > 0) {
            $sql .= ' AND r.property_id = :property_id';
            $params[':property_id'] = $propertyId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'collected' => round((float)($row['collected'] ?? 0), 2),
            'pending' => round((float)($row['pending'] ?? 0), 2),
            'reversed' => round((float)($row['reversed'] ?? 0), 2),
        ];
    }

    /**
     * @param array{from: string, to: string} $period
     * @return array<int, float> collected money keyed by property id (0 = unassigned)
     */
    private function collectedByProperty(int $adminId, array $period): array
    {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(r.property_id, 0) AS property_id, COALESCE(SUM(p.amount), 0) AS collected
            FROM payments p
            JOIN tenants t ON t.id = p.tenant_id
            LEFT JOIN rooms r ON r.id = t.room_id
            WHERE t.admin_id = :admin_id
              AND p.payment_date >= :from
              AND p.payment_date <= :to
              AND " . payment_verified_sql('p') . "
            GROUP BY COALESCE(r.property_id, 0)
        ");
        $stmt->execute([':admin_id' => $adminId, ':from' => $period['from'], ':to' => $period['to']]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int)$row['property_id']] = (float)$row['collected'];
        }

        return $out;
    }

    /**
     * @return array{rooms_total: int, rooms_occupied: int, rooms_vacant: int, occupancy_rate: float}
     */
    private function unassignedRoomStats(int $adminId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(r.id) AS rooms_total,
                   COALESCE(SUM(CASE WHEN occ.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS rooms_occupied
            FROM rooms r
            LEFT JOIN (
                SELECT DISTINCT t.room_id AS id FROM tenants t
                WHERE t.admin_id = :tenant_admin
                  AND LOWER(COALESCE(t.status, '')) = 'active'
                  AND t.exit_date IS NULL
                  AND t.room_id IS NOT NULL
            ) occ ON occ.id = r.id
            WHERE r.admin_id = :admin_id AND r.property_id IS NULL
        ");
        $stmt->execute([':tenant_admin' => $adminId, ':admin_id' => $adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['rooms_total' => 0, 'rooms_occupied' => 0];

        $total = (int)$row['rooms_total'];
        $occupied = (int)$row['rooms_occupied'];

        return [
            'rooms_total' => $total,
            'rooms_occupied' => $occupied,
            'rooms_vacant' => max(0, $total - $occupied),
            'occupancy_rate' => $total > 0 ? round($occupied / $total, 4) : 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function ledgerWhere(int $adminId, array $filters): array
    {
        $where = ['t.admin_id = :admin_id'];
        $params = [':admin_id' => $adminId];

        if (!empty($filters['from'])) {
            $where[] = 'p.payment_date >= :from';
            $params[':from'] = (string)$filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'p.payment_date <= :to';
            $params[':to'] = (string)$filters['to'];
        }
        if (!empty($filters['property'])) {
            $where[] = 'r.property_id = :property_id';
            $params[':property_id'] = (int)$filters['property'];
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'confirmed', 'done', 'reversed'], true)) {
            $where[] = 'LOWER(COALESCE(p.verification_status, \'done\')) = :status';
            $params[':status'] = (string)$filters['status'];
        }
        if (!empty($filters['method'])) {
            $where[] = 'p.method = :method';
            $params[':method'] = (string)$filters['method'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(t.full_name LIKE :search OR p.receipt_no LIKE :search OR r.room_number LIKE :search)';
            $params[':search'] = '%' . (string)$filters['search'] . '%';
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function parseDate(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return ($date && $date->format('Y-m-d') === $value) ? $value : null;
    }

    /**
     * Billing months of the period that fall on or after the move-in month.
     */
    private function monthsInPeriod(?string $moveIn, string $from, string $to): int
    {
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);

        if ($moveIn) {
            $moveInMonth = new DateTimeImmutable(date('Y-m-01', strtotime($moveIn)));
            if ($moveInMonth > $start) {
                $start = $moveInMonth;
            }
        }

        $start = $start->modify('first day of this month');
        $end = $end->modify('first day of this month');

        if ($start > $end) {
            return 0;
        }

        $diff = $start->diff($end);

        return ((int)$diff->y * 12) + (int)$diff->m + 1;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function filterByProperty(array $rows, int $propertyId): array
    {
        if ($propertyId <= 0) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => (int)($row['property_id'] ?? 0) === $propertyId
        ));
    }
}
