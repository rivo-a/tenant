<?php
declare(strict_types=1);

class PaymentService
{
    private PDO $pdo;

    private const ALLOWED_PAYMENT_METHODS = ['cash', 'mobile', 'bank'];
    private const ALLOWED_VERIFICATION_STATUSES = ['all', 'pending', 'confirmed', 'done'];
    private const MAX_NOTE_LENGTH = 160;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Record a payment with auto-carry for overpayments.
     * New payment rows enter the controlled verification queue as pending.
     *
     * @return array{rent: float, paid: float, balance: float, status: string, payment_month: string, verification_status: string, payment_ids: list<int>}
     */
    public function recordPayment(
        int $tenantId,
        float $amount,
        string $paymentMonth,
        string $paymentDate,
        int $adminId,
        string $method = 'cash',
        string $note = ''
    ): array {
        if (!is_finite($amount) || $amount <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        $method = strtolower(trim($method));
        if (!in_array($method, self::ALLOWED_PAYMENT_METHODS, true)) {
            throw new RuntimeException('Invalid payment method.');
        }

        $note = trim($note);
        if (strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new RuntimeException('Payment note must be 160 characters or fewer.');
        }

        $paymentMonth = trim($paymentMonth);
        if (!preg_match('/^\d{4}-\d{2}$/', $paymentMonth)) {
            throw new RuntimeException('Invalid payment month.');
        }

        $paymentDateObject = $this->parseDate($paymentDate, 'Invalid payment date.');
        $startedTransaction = false;

        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    t.id,
                    t.rent_due_date,
                    COALESCE(
                        NULLIF(t.monthly_rent, 0),
                        NULLIF(r.monthly_rent, 0),
                        rt.default_monthly_rent,
                        0
                    ) AS rent
                FROM tenants t
                LEFT JOIN rooms r ON t.room_id = r.id
                LEFT JOIN room_types rt ON r.room_type_id = rt.id
                WHERE t.id = ?
                  AND t.admin_id = ?
                  AND LOWER(COALESCE(t.status, '')) = 'active'
                  AND t.exit_date IS NULL
                LIMIT 1
            ");
            $stmt->execute([$tenantId, $adminId]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tenant) {
                throw new RuntimeException('Tenant is not active or has exited.');
            }

            $rent = (float)($tenant['rent'] ?? 0);
            if ($rent <= 0) {
                throw new RuntimeException('Invalid rent configuration.');
            }

            $dueDateRaw = trim((string)($tenant['rent_due_date'] ?? ''));
            $startDate = $dueDateRaw !== ''
                ? $this->parseDate($dueDateRaw, 'Invalid tenant rent due date.')
                : $this->parseDate($paymentMonth . '-01', 'Invalid payment month.');
            $dueDay = (int)$startDate->format('d');

            $remaining = round($amount, 2);
            $allocatedAmount = 0.0;
            $firstMonth = null;
            $firstMonthPaidAfter = null;
            $paymentIds = [];

            for ($iteration = 0; $remaining > 0.00001 && $iteration < 240; $iteration++) {
                $monthKey = $startDate->format('Y-m');

                $sum = $this->pdo->prepare("
                    SELECT COALESCE(SUM(amount), 0)
                    FROM payments
                    WHERE tenant_id = ?
                      AND admin_id = ?
                      AND payment_month = ?
                ");
                $sum->execute([$tenantId, $adminId, $monthKey]);
                $paidBefore = (float)$sum->fetchColumn();
                $due = round($rent - $paidBefore, 2);

                if ($due <= 0.00001) {
                    $startDate = $this->nextDueDate($startDate, $dueDay);
                    continue;
                }

                $pay = round(min($remaining, $due), 2);
                if ($pay <= 0) {
                    break;
                }

                $ins = $this->pdo->prepare("
                    INSERT INTO payments
                        (tenant_id, admin_id, amount, payment_month, payment_date, method, note, verification_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $ins->execute([
                    $tenantId,
                    $adminId,
                    $pay,
                    $monthKey,
                    $paymentDateObject->format('Y-m-d'),
                    $method,
                    $note !== '' ? $note : null,
                ]);
                $paymentIds[] = (int)$this->pdo->lastInsertId();

                if ($firstMonth === null) {
                    $firstMonth = $monthKey;
                    $firstMonthPaidAfter = round($paidBefore + $pay, 2);
                }

                $allocatedAmount = round($allocatedAmount + $pay, 2);
                $remaining = round($remaining - $pay, 2);

                if ($pay >= ($due - 0.00001)) {
                    $startDate = $this->nextDueDate($startDate, $dueDay);
                    $upd = $this->pdo->prepare("
                        UPDATE tenants
                        SET rent_due_date = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND admin_id = ?
                    ");
                    $upd->execute([$startDate->format('Y-m-d'), $tenantId, $adminId]);
                }
            }

            if ($remaining > 0.00001 || $firstMonth === null || $firstMonthPaidAfter === null || !$paymentIds) {
                throw new RuntimeException('Payment could not be allocated safely.');
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            $balance = max(0.0, round($rent - $firstMonthPaidAfter, 2));
            $result = [
                'rent' => $rent,
                'paid' => $allocatedAmount,
                'balance' => $balance,
                'status' => $balance <= 0.00001 ? 'paid' : 'partial',
                'payment_month' => $firstMonth,
                'verification_status' => 'pending',
                'payment_ids' => $paymentIds,
            ];

            $this->audit(
                $adminId,
                'PAYMENT_CREATED_PENDING',
                sprintf(
                    'Payment of UGX %s for tenant #%d was recorded and queued for verification.',
                    number_format($allocatedAmount, 2),
                    $tenantId
                )
            );

            return $result;
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    public function getVerificationQueue(int $adminId, string $status = 'all', string $search = ''): array
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::ALLOWED_VERIFICATION_STATUSES, true)) {
            $status = 'all';
        }

        $search = trim($search);
        $sql = $this->verificationQuerySql();
        $params = [
            ':payment_admin' => $adminId,
            ':tenant_admin' => $adminId,
            ':sum_admin' => $adminId,
        ];

        if ($status !== 'all') {
            $sql .= ' AND record.verification_status = :verification_status';
            $params[':verification_status'] = $status;
        }

        if ($search !== '') {
            $sql .= ' AND (record.full_name LIKE :search_name OR record.room_number LIKE :search_room OR record.payment_month LIKE :search_month)';
            $like = '%' . $search . '%';
            $params[':search_name'] = $like;
            $params[':search_room'] = $like;
            $params[':search_month'] = $like;
        }

        $sql .= "
            ORDER BY
                CASE record.verification_status
                    WHEN 'pending' THEN 1
                    WHEN 'confirmed' THEN 2
                    ELSE 3
                END,
                record.payment_date DESC,
                record.id DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public function getVerificationPayment(int $paymentId, int $adminId): ?array
    {
        if ($paymentId <= 0 || $adminId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            $this->verificationQuerySql() . ' AND record.id = :payment_id LIMIT 1'
        );
        $stmt->execute([
            ':payment_admin' => $adminId,
            ':tenant_admin' => $adminId,
            ':sum_admin' => $adminId,
            ':payment_id' => $paymentId,
        ]);

        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        return $payment ?: null;
    }

    public function getDailyVerificationCode(?DateTimeImmutable $now = null): string
    {
        $secret = function_exists('app_env')
            ? app_env('PAYMENT_VERIFICATION_SECRET')
            : trim((string)($_ENV['PAYMENT_VERIFICATION_SECRET'] ?? getenv('PAYMENT_VERIFICATION_SECRET') ?: ''));

        if ($secret === '') {
            throw new RuntimeException('Payment verification is not configured.');
        }

        $timezone = new DateTimeZone('Africa/Kampala');
        $localNow = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $digest = hash_hmac('sha256', $localNow->format('Y-m-d'), $secret);
        $number = (int)(hexdec(substr($digest, 0, 8)) % 9000) + 1000;

        return str_pad((string)$number, 4, '0', STR_PAD_LEFT);
    }

    public function confirmPayment(int $paymentId, int $adminId, string $submittedCode): void
    {
        $submittedCode = trim($submittedCode);
        if (!preg_match('/^\d{4}$/D', $submittedCode)) {
            throw new RuntimeException('The verification code must be exactly 4 digits.');
        }

        $expectedCode = $this->getDailyVerificationCode();
        if (!hash_equals($expectedCode, $submittedCode)) {
            throw new RuntimeException('The verification code could not be confirmed.');
        }

        if ($paymentId <= 0 || $adminId <= 0) {
            throw new RuntimeException('Payment record not found.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE payments
                SET verification_status = 'confirmed',
                    verification_confirmed_at = CURRENT_TIMESTAMP,
                    verification_confirmed_by = :admin_id
                WHERE id = :payment_id
                  AND admin_id = :admin_id_owner
                  AND verification_status = 'pending'
            ");
            $stmt->execute([
                ':admin_id' => $adminId,
                ':admin_id_owner' => $adminId,
                ':payment_id' => $paymentId,
            ]);

            if ($stmt->rowCount() !== 1) {
                $this->throwIfStalePayment($paymentId, $adminId);
                throw new RuntimeException('Payment record not found.');
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit($adminId, 'PAYMENT_CONFIRMED', "Payment #{$paymentId} was confirmed with the daily verification code.");
    }

    public function markPaymentDone(int $paymentId, int $adminId): void
    {
        if ($paymentId <= 0 || $adminId <= 0) {
            throw new RuntimeException('Payment record not found.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE payments
                SET verification_status = 'done',
                    verification_done_at = CURRENT_TIMESTAMP,
                    verification_done_by = :admin_id
                WHERE id = :payment_id
                  AND admin_id = :admin_id_owner
                  AND verification_status = 'confirmed'
            ");
            $stmt->execute([
                ':admin_id' => $adminId,
                ':admin_id_owner' => $adminId,
                ':payment_id' => $paymentId,
            ]);

            if ($stmt->rowCount() !== 1) {
                $this->throwIfStalePayment($paymentId, $adminId);
                throw new RuntimeException('Payment record not found.');
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit($adminId, 'PAYMENT_MARKED_DONE', "Payment #{$paymentId} verification was completed.");
    }

    private function verificationQuerySql(): string
    {
        $rentExpression = "COALESCE(
            NULLIF(t.monthly_rent, 0),
            NULLIF(r.monthly_rent, 0),
            rt.default_monthly_rent,
            0
        )";

        return "
            SELECT
                record.*,
                MAX(0, record.effective_rent - record.month_paid) AS month_balance,
                CASE
                    WHEN record.effective_rent > 0 AND record.month_paid >= record.effective_rent THEN 'paid'
                    WHEN record.month_paid > 0 THEN 'partial'
                    ELSE 'unpaid'
                END AS month_status
            FROM (
                SELECT
                    p.id,
                    p.tenant_id,
                    p.amount,
                    p.payment_date,
                    p.payment_month,
                    p.method,
                    p.note,
                    COALESCE(p.verification_status, 'done') AS verification_status,
                    p.verification_confirmed_at,
                    p.verification_confirmed_by,
                    p.verification_done_at,
                    p.verification_done_by,
                    t.full_name,
                    t.status AS tenant_status,
                    t.exit_date,
                    r.room_number,
                    rt.name AS room_type,
                    {$rentExpression} AS effective_rent,
                    COALESCE((
                        SELECT SUM(p2.amount)
                        FROM payments p2
                        WHERE p2.tenant_id = p.tenant_id
                          AND p2.admin_id = :sum_admin
                          AND p2.payment_month = p.payment_month
                    ), 0) AS month_paid
                FROM payments p
                JOIN tenants t ON t.id = p.tenant_id
                LEFT JOIN rooms r ON r.id = t.room_id
                LEFT JOIN room_types rt ON rt.id = r.room_type_id
                WHERE p.admin_id = :payment_admin
                  AND t.admin_id = :tenant_admin
            ) AS record
            WHERE 1 = 1
        ";
    }

    private function throwIfStalePayment(int $paymentId, int $adminId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT verification_status FROM payments WHERE id = :id AND admin_id = :admin LIMIT 1'
        );
        $stmt->execute([':id' => $paymentId, ':admin' => $adminId]);
        $status = $stmt->fetchColumn();

        if ($status === false) {
            throw new RuntimeException('Payment record not found.');
        }

        throw new RuntimeException('This payment changed while you were reviewing it.');
    }

    private function audit(int $adminId, string $action, string $description): void
    {
        if (!function_exists('logAudit')) {
            return;
        }

        try {
            logAudit($adminId, $action, $description);
        } catch (Throwable $ignored) {
            // Audit failure must not undo a committed payment transition.
        }
    }

    private function parseDate(string $value, string $message): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = $errors !== false && (
            $errors['warning_count'] > 0 ||
            $errors['error_count'] > 0
        );

        if ($date === false || $hasErrors || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException($message);
        }

        return $date;
    }

    private function nextDueDate(DateTimeImmutable $date, int $day): DateTimeImmutable
    {
        $nextMonth = $date->modify('first day of next month');
        $lastDay = (int)$nextMonth->format('t');

        return $nextMonth->setDate(
            (int)$nextMonth->format('Y'),
            (int)$nextMonth->format('m'),
            min($day, $lastDay)
        );
    }
}
