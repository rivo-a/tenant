<?php
class PaymentService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Record a payment with auto-carry for overpayments.
     *
     * @param int $tenantId
     * @param float $amount
     * @param string $paymentMonth  YYYY-MM
     * @param string $paymentDate   YYYY-MM-DD
     * @param int $adminId
     * @return array  rent summary
     */
    public function recordPayment(
        int $tenantId,
        float $amount,
        string $paymentMonth,
        string $paymentDate,
        int $adminId
    ): array {

        if ($amount <= 0) {
            throw new RuntimeException("Payment amount must be greater than zero.");
        }

        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }

        try {
            /* ===== FETCH TENANT + RENT + DUE DATE ===== */
            $stmt = $this->pdo->prepare("
                SELECT 
                    t.id,
                    t.rent_due_date,
                    COALESCE(r.monthly_rent, rt.default_monthly_rent) AS rent
                FROM tenants t
                JOIN rooms r ON t.room_id = r.id
                JOIN room_types rt ON r.room_type_id = rt.id
                WHERE t.id = ? AND t.admin_id = ? AND t.exit_date IS NULL
                LIMIT 1
            ");
            $stmt->execute([$tenantId, $adminId]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tenant) {
                throw new RuntimeException("Invalid tenant.");
            }

            $rent = (float) $tenant['rent'];
            if ($rent <= 0) {
                throw new RuntimeException("Invalid rent configuration.");
            }

            /* ===== Determine start month ===== */
            $startDate = new DateTime($tenant['rent_due_date'] ?? $paymentMonth . '-01');
            $remaining = round($amount, 2);

            /* ===== LOOP MONTH BY MONTH ===== */
            while ($remaining > 0) {
                $monthKey = $startDate->format('Y-m');

                // amount already paid this month
                $sum = $this->pdo->prepare("
                    SELECT COALESCE(SUM(amount),0)
                    FROM payments
                    WHERE tenant_id=? AND admin_id=? AND payment_month=?
                ");
                $sum->execute([$tenantId, $adminId, $monthKey]);
                $paid = (float) $sum->fetchColumn();

                $due = round($rent - $paid, 2);

                // month fully paid → move to next
                if ($due <= 0) {
                    $startDate->modify('+1 month');
                    continue;
                }

                // pay either remaining or remaining due
                $pay = min($remaining, $due);

                // insert payment slice
                $ins = $this->pdo->prepare("
                    INSERT INTO payments
                    (tenant_id, admin_id, amount, payment_month, payment_date)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $tenantId,
                    $adminId,
                    $pay,
                    $monthKey,
                    $paymentDate
                ]);

                $remaining = round($remaining - $pay, 2);

                // month completed → advance due date
                if ($pay == $due) {
                    // next due date same day as current
                    $day = (int) date('d', strtotime($tenant['rent_due_date'] ?? $paymentDate));
                    $startDate->modify('+1 month');
                    $nextDue = $startDate->format("Y-m-$day");

                    $upd = $this->pdo->prepare("
                        UPDATE tenants SET rent_due_date=? WHERE id=?
                    ");
                    $upd->execute([$nextDue, $tenantId]);
                }
            }

            $this->pdo->commit();

            return [
                'rent'    => $rent,
                'paid'    => $amount,
                'balance' => 0,
                'status'  => 'paid'
            ];

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
?>
