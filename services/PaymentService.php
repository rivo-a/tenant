<?php
declare(strict_types=1);

class PaymentService
{
    private PDO $pdo;

    private const ALLOWED_PAYMENT_METHODS = ['cash', 'mobile', 'bank'];
    private const MAX_NOTE_LENGTH = 160;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Record a payment with auto-carry for overpayments.
     *
     * @return array{rent: float, paid: float, balance: float, status: string, payment_month: string}
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
                LEFT JOIN rooms r
                    ON t.room_id = r.id
                LEFT JOIN room_types rt
                    ON r.room_type_id = rt.id
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
                        (tenant_id, admin_id, amount, payment_month, payment_date, method, note)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
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

            if ($remaining > 0.00001 || $firstMonth === null || $firstMonthPaidAfter === null) {
                throw new RuntimeException('Payment could not be allocated safely.');
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            $balance = max(0.0, round($rent - $firstMonthPaidAfter, 2));

            return [
                'rent' => $rent,
                'paid' => $allocatedAmount,
                'balance' => $balance,
                'status' => $balance <= 0.00001 ? 'paid' : 'partial',
                'payment_month' => $firstMonth,
            ];
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
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
