<?php
declare(strict_types=1);

class TenantPortalService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Issue a new 30-day read-only link for an active tenant.
     * The raw token is never persisted or written to audit logs.
     *
     * @return array{url: string, expires_at: string}
     */
    public function issueAccessLink(int $tenantId, int $adminId, int $ttlDays = 30): array
    {
        if ($tenantId <= 0 || $adminId <= 0) {
            throw new RuntimeException('Tenant access could not be issued.');
        }

        $ttlDays = max(1, min(90, $ttlDays));
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM tenants
            WHERE id = :tenant_id
              AND admin_id = :admin_id
              AND LOWER(COALESCE(status, '')) = 'active'
              AND exit_date IS NULL
            LIMIT 1
        ");
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':admin_id' => $adminId,
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Only active tenants can receive a portal link.');
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify("+{$ttlDays} days")
            ->format('Y-m-d H:i:s');
        $url = $this->buildPortalUrl($rawToken);

        $insert = $this->pdo->prepare("
            INSERT INTO tenant_access_tokens
                (tenant_id, token_hash, expires_at, created_by, created_at)
            VALUES (:tenant_id, :token_hash, :expires_at, :created_by, CURRENT_TIMESTAMP)
        ");
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
            ':created_by' => $adminId,
        ]);

        $this->audit(
            $adminId,
            'PORTAL_LINK_ISSUED',
            "Read-only tenant portal link issued for tenant #{$tenantId}; it expires in {$ttlDays} days."
        );

        return [
            'url' => $url,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Resolve a signed link and return only the record it is allowed to see.
     * Invalid, expired, and revoked values intentionally share the same result.
     *
     * @return array<string, mixed>|null
     */
    public function resolveAccess(string $rawToken): ?array
    {
        $rawToken = trim($rawToken);
        if (!preg_match('/^[a-f0-9]{64}$/D', $rawToken)) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                access.id AS access_id,
                access.expires_at,
                t.id AS tenant_id,
                t.admin_id,
                t.full_name,
                t.phone,
                t.email,
                t.status AS tenant_status,
                t.exit_date,
                t.move_in_date,
                t.rent_due_date,
                COALESCE(
                    NULLIF(t.monthly_rent, 0),
                    NULLIF(r.monthly_rent, 0),
                    rt.default_monthly_rent,
                    0
                ) AS effective_rent,
                r.room_number,
                rt.name AS room_type
            FROM tenant_access_tokens access
            JOIN tenants t ON t.id = access.tenant_id
            LEFT JOIN rooms r ON r.id = t.room_id
            LEFT JOIN room_types rt ON rt.id = r.room_type_id
            WHERE access.token_hash = :token_hash
              AND access.revoked_at IS NULL
              AND access.expires_at > CURRENT_TIMESTAMP
            LIMIT 1
        ");
        $stmt->execute([':token_hash' => hash('sha256', $rawToken)]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tenant) {
            return null;
        }

        $accessUpdate = $this->pdo->prepare(
            'UPDATE tenant_access_tokens SET last_accessed_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $accessUpdate->execute([':id' => (int)$tenant['access_id']]);

        $tenantId = (int)$tenant['tenant_id'];
        $adminId = (int)$tenant['admin_id'];
        $rent = max(0.0, (float)($tenant['effective_rent'] ?? 0));
        $currentMonth = (new DateTimeImmutable('now', new DateTimeZone('Africa/Kampala')))->format('Y-m');

        $paymentStmt = $this->pdo->prepare("
            SELECT
                id,
                payment_month,
                payment_date,
                amount,
                method,
                note,
                COALESCE(verification_status, 'done') AS verification_status,
                verification_confirmed_at,
                verification_done_at
            FROM payments
            WHERE tenant_id = :tenant_id
              AND admin_id = :admin_id
            ORDER BY payment_month DESC, payment_date DESC, id DESC
        ");
        $paymentStmt->execute([
            ':tenant_id' => $tenantId,
            ':admin_id' => $adminId,
        ]);
        $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $grouped = [];
        foreach ($payments as $payment) {
            $month = (string)($payment['payment_month'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}$/D', $month)) {
                continue;
            }

            if (!isset($grouped[$month])) {
                $grouped[$month] = [
                    'month' => $month,
                    'rent' => $rent,
                    'paid' => 0.0,
                    'balance' => $rent,
                    'payment_status' => 'unpaid',
                    'payments' => [],
                ];
            }

            $amount = (float)($payment['amount'] ?? 0);
            $grouped[$month]['paid'] = round((float)$grouped[$month]['paid'] + $amount, 2);
            $grouped[$month]['payments'][] = [
                'id' => (int)$payment['id'],
                'payment_month' => $month,
                'payment_date' => (string)($payment['payment_date'] ?? ''),
                'amount' => $amount,
                'method' => strtolower((string)($payment['method'] ?? '')),
                'note' => trim((string)($payment['note'] ?? '')),
                'verification_status' => strtolower((string)($payment['verification_status'] ?? 'done')),
                'verification_confirmed_at' => $payment['verification_confirmed_at'] ?? null,
                'verification_done_at' => $payment['verification_done_at'] ?? null,
            ];
        }

        if (!isset($grouped[$currentMonth])) {
            $grouped[$currentMonth] = [
                'month' => $currentMonth,
                'rent' => $rent,
                'paid' => 0.0,
                'balance' => $rent,
                'payment_status' => 'unpaid',
                'payments' => [],
            ];
        }

        foreach ($grouped as &$monthGroup) {
            $monthGroup['rent'] = $rent;
            $monthGroup['paid'] = round((float)$monthGroup['paid'], 2);
            $monthGroup['balance'] = max(0.0, round($rent - $monthGroup['paid'], 2));
            $monthGroup['payment_status'] = $this->paymentStatus($rent, $monthGroup['paid']);
        }
        unset($monthGroup);

        krsort($grouped, SORT_STRING);
        $months = array_values($grouped);

        return [
            'access_expires_at' => (string)$tenant['expires_at'],
            'tenant' => [
                'id' => $tenantId,
                'full_name' => (string)($tenant['full_name'] ?? ''),
                'phone' => (string)($tenant['phone'] ?? ''),
                'email' => (string)($tenant['email'] ?? ''),
                'tenant_status' => strtolower((string)($tenant['tenant_status'] ?? 'active')),
                'exit_date' => $tenant['exit_date'] ?? null,
                'move_in_date' => $tenant['move_in_date'] ?? null,
                'rent_due_date' => $tenant['rent_due_date'] ?? null,
                'room_number' => $tenant['room_number'] ?? null,
                'room_type' => $tenant['room_type'] ?? null,
                'effective_rent' => $rent,
            ],
            'current_month' => $grouped[$currentMonth],
            'months' => $months,
        ];
    }

    public function buildPortalUrl(string $rawToken): string
    {
        $baseUrl = function_exists('app_env')
            ? app_env('APP_PUBLIC_URL')
            : trim((string)($_ENV['APP_PUBLIC_URL'] ?? getenv('APP_PUBLIC_URL') ?: ''));

        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Tenant portal links are not configured. Set APP_PUBLIC_URL.');
        }

        return $baseUrl . '/tenant-record.php?access=' . rawurlencode($rawToken);
    }

    private function paymentStatus(float $rent, float $paid): string
    {
        if ($rent > 0 && $paid >= $rent) {
            return 'paid';
        }

        return $paid > 0 ? 'partial' : 'unpaid';
    }

    private function audit(int $adminId, string $action, string $description): void
    {
        if (!function_exists('logAudit')) {
            return;
        }

        try {
            logAudit($adminId, $action, $description);
        } catch (Throwable $ignored) {
            // Link issuance remains successful if audit storage is unavailable.
        }
    }
}
