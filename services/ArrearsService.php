<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/rent.php';

/**
 * Rent arrears for active tenants, calculated across every month since move-in.
 *
 * Reports and the dashboard both read from here so the two screens can never
 * disagree about what a tenant owes. Verified payments (confirmed, done) reduce
 * the balance; pending payments are reported alongside but never deducted.
 */
final class ArrearsService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return array{
     *     as_of: string,
     *     rows: list<array<string, mixed>>,
     *     totals: array{tenants: int, owed_tenants: int, expected: float, verified: float, pending: float, balance: float, overdue_tenants: int, overdue_balance: float}
     * }
     */
    public function getArrears(int $adminId, int $roomTypeId = 0, ?DateTimeImmutable $asOf = null, int $tenantId = 0): array
    {
        $timezone = new DateTimeZone('Africa/Kampala');
        $asOf = ($asOf ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $today = $asOf->format('Y-m-d');

        $totals = [
            'tenants' => 0,
            'owed_tenants' => 0,
            'expected' => 0.0,
            'verified' => 0.0,
            'verified_applied' => 0.0,
            'pending' => 0.0,
            'balance' => 0.0,
            'overdue_tenants' => 0,
            'overdue_balance' => 0.0,
        ];

        if ($adminId <= 0) {
            return ['as_of' => $asOf->format('Y-m-d'), 'rows' => [], 'totals' => $totals];
        }

        $rentExpression = rent_expression('t', 'r', 'rt');
        $verifiedExpression = payment_verified_sql('p');
        $pendingExpression = payment_pending_sql('p');
        $roomTypeFilter = $roomTypeId > 0 ? ' AND rt.id = :room_type_id' : '';
        $tenantFilter = $tenantId > 0 ? ' AND t.id = :tenant_id' : '';

        $sql = "
            SELECT
                t.id AS tenant_id,
                t.full_name,
                t.phone,
                t.move_in_date,
                t.rent_due_date,
                r.id AS room_id,
                r.room_number,
                rt.name AS room_type,
                {$rentExpression} AS rent,
                COALESCE(SUM(CASE WHEN {$verifiedExpression} THEN p.amount ELSE 0 END), 0) AS verified_paid,
                COALESCE(SUM(CASE WHEN {$pendingExpression} THEN p.amount ELSE 0 END), 0) AS pending_paid,
                COUNT(p.id) AS payment_count
            FROM tenants t
            LEFT JOIN rooms r ON r.id = t.room_id
            LEFT JOIN room_types rt ON rt.id = r.room_type_id
            LEFT JOIN payments p
                ON p.tenant_id = t.id
               AND p.admin_id = :payment_admin_id
            WHERE t.admin_id = :admin_id
              AND LOWER(COALESCE(t.status, 'active')) = 'active'
              AND t.exit_date IS NULL
              {$roomTypeFilter}
              {$tenantFilter}
            GROUP BY
                t.id,
                t.full_name,
                t.phone,
                t.move_in_date,
                t.rent_due_date,
                r.id,
                r.room_number,
                t.monthly_rent,
                r.monthly_rent,
                rt.name,
                rt.default_monthly_rent
            ORDER BY t.full_name
        ";

        $params = [
            ':payment_admin_id' => $adminId,
            ':admin_id' => $adminId,
        ];
        if ($roomTypeId > 0) {
            $params[':room_type_id'] = $roomTypeId;
        }
        if ($tenantId > 0) {
            $params[':tenant_id'] = $tenantId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $rows = [];
        foreach ($records as $record) {
            $rent = max(0.0, (float)($record['rent'] ?? 0));
            $verifiedPaid = max(0.0, (float)($record['verified_paid'] ?? 0));
            $pendingPaid = max(0.0, (float)($record['pending_paid'] ?? 0));
            $monthsDue = rent_months_due($record['move_in_date'] ?? null, $asOf);
            $expected = round($monthsDue * $rent, 2);

            /*
             * Pending money reduces what the tenant owes (they handed it over)
             * but is never treated as income. Only verified money is revenue.
             */
            $balance = max(0.0, round($expected - $verifiedPaid - $pendingPaid, 2));
            $monthsBehind = $rent > 0 ? (int)ceil($balance / $rent) : 0;

            // Verified money only settles rent up to what has been billed.
            // Anything above that is a credit, not arrears relief.
            $verifiedApplied = $expected > 0 ? min($verifiedPaid, $expected) : $verifiedPaid;

            $owedSince = null;
            $owedSinceLabel = '—';
            if ($balance > 0 && $record['move_in_date']) {
                try {
                    $coveredMonths = $rent > 0 ? (int)floor(($verifiedPaid + $pendingPaid) / $rent) : 0;
                    $coveredMonths = max(0, min($coveredMonths, max(0, $monthsDue - 1)));
                    $owedSince = rent_add_months(new DateTimeImmutable((string)$record['move_in_date']), $coveredMonths);
                    $owedSinceLabel = $owedSince->format('M Y');
                } catch (Throwable $ignored) {
                    $owedSince = null;
                    $owedSinceLabel = '—';
                }
            }

            $dueRaw = trim((string)($record['rent_due_date'] ?? ''));
            $daysOverdue = 0;
            $dueIsPast = false;
            if ($dueRaw !== '') {
                try {
                    $due = (new DateTimeImmutable($dueRaw))->setTime(0, 0);
                    $now = (new DateTimeImmutable($today))->setTime(0, 0);
                    $dueIsPast = $due < $now;
                    if ($dueIsPast) {
                        $daysOverdue = max(0, (int)$due->diff($now)->days);
                    }
                } catch (Throwable $ignored) {
                    $dueIsPast = false;
                }
            }

            $isOverdue = $balance > 0.00001 && $dueIsPast;

            $row = [
                'tenant_id' => (int)($record['tenant_id'] ?? 0),
                'full_name' => (string)($record['full_name'] ?? 'Unknown tenant'),
                'phone' => (string)($record['phone'] ?? ''),
                'room_id' => (int)($record['room_id'] ?? 0),
                'room_number' => (string)($record['room_number'] ?? ''),
                'room_type' => (string)($record['room_type'] ?? ''),
                'move_in_date' => $record['move_in_date'] ?? null,
                'rent_due_date' => $record['rent_due_date'] ?? null,
                'rent' => $rent,
                'months_due' => $monthsDue,
                'expected' => $expected,
                'verified_paid' => $verifiedPaid,
                'verified_applied' => $verifiedApplied,
                'pending_paid' => $pendingPaid,
                'balance' => $balance,
                'months_behind' => $monthsBehind,
                'owed_since' => $owedSince ? $owedSince->format('Y-m') : '',
                'owed_since_label' => $owedSinceLabel,
                'days_overdue' => $daysOverdue,
                'is_overdue' => $isOverdue,
                'payment_count' => (int)($record['payment_count'] ?? 0),
                'payment_url' => 'payments.php?' . http_build_query(['tenant_id' => (int)($record['tenant_id'] ?? 0)]),
            ];

            $rows[] = $row;

            $totals['tenants']++;
            $totals['expected'] += $expected;
            $totals['verified'] += $verifiedPaid;
            $totals['verified_applied'] += $verifiedApplied;
            $totals['pending'] += $pendingPaid;
            if ($balance > 0.00001) {
                $totals['owed_tenants']++;
                $totals['balance'] += $balance;
            }
            if ($isOverdue) {
                $totals['overdue_tenants']++;
                $totals['overdue_balance'] += $balance;
            }
        }

        usort($rows, static function (array $left, array $right): int {
            return [$right['balance'], $left['full_name']] <=> [$left['balance'], $right['full_name']];
        });

        return [
            'as_of' => $asOf->format('Y-m-d'),
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * One tenant's authoritative position, resolved through their own
     * landlord record. Used by the tenant portal so the balance a tenant sees
     * is the same figure the landlord's report shows.
     *
     * @return array<string, mixed>|null
     */
    public function getTenantArrears(int $tenantId, ?DateTimeImmutable $asOf = null): ?array
    {
        if ($tenantId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT admin_id FROM tenants WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $tenantId]);
        $adminId = (int)($stmt->fetchColumn() ?: 0);

        if ($adminId <= 0) {
            return null;
        }

        $result = $this->getArrears($adminId, 0, $asOf, $tenantId);

        return $result['rows'][0] ?? null;
    }

    /**
     * Rows with a positive balance only.
     *
     * @return list<array<string, mixed>>
     */
    public function getOwing(int $adminId, int $roomTypeId = 0, ?DateTimeImmutable $asOf = null, bool $overdueOnly = false): array
    {
        $result = $this->getArrears($adminId, $roomTypeId, $asOf);

        return array_values(array_filter(
            $result['rows'],
            static fn(array $row): bool => (float)$row['balance'] > 0.00001
                && (!$overdueOnly || (bool)$row['is_overdue'])
        ));
    }
}
