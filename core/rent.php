<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Rent & payment helpers (single source of truth)
|--------------------------------------------------------------------------
| Every page must derive rent and payment state from these helpers instead
| of re-declaring the COALESCE chain. Precedence stays:
|   tenant override -> room override -> room type default -> 0
| A room rent change clears the inherited tenant snapshots so the room rent
| is what reports, dashboard and payments read next.
*/

if (!function_exists('rent_expression')) {
    function rent_expression(string $tenant = 't', string $room = 'r', string $roomType = 'rt'): string
    {
        return "COALESCE(
            NULLIF({$tenant}.monthly_rent, 0),
            NULLIF({$room}.monthly_rent, 0),
            NULLIF({$roomType}.default_monthly_rent, 0),
            0
        )";
    }
}

if (!function_exists('payment_verified_sql')) {
    /** Money that counts as collected: confirmed or done. */
    function payment_verified_sql(string $payment = 'p'): string
    {
        return "LOWER(COALESCE({$payment}.verification_status, 'done')) IN ('confirmed', 'done')";
    }
}

if (!function_exists('payment_pending_sql')) {
    /** Money recorded but not yet verified. Never counts as collected. */
    function payment_pending_sql(string $payment = 'p'): string
    {
        return "LOWER(COALESCE({$payment}.verification_status, 'done')) = 'pending'";
    }
}

if (!function_exists('payment_month_sql')) {
    /** Billing month a payment is allocated to, not the day it was handed over. */
    function payment_month_sql(string $payment = 'p'): string
    {
        return "COALESCE(NULLIF({$payment}.payment_month, ''), strftime('%Y-%m', {$payment}.payment_date))";
    }
}

if (!function_exists('room_rent_month')) {
    /** Normalise a date to the first day of its month at midnight. */
    function room_rent_month(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date
            ->setDate((int)$date->format('Y'), (int)$date->format('n'), 1)
            ->setTime(0, 0);
    }
}

if (!function_exists('rent_months_due')) {
    /**
     * Number of billing months a tenant owes for, from move-in to $asOf inclusive.
     * Returns 0 when the tenancy starts after $asOf.
     */
    function rent_months_due(?string $moveInDate, DateTimeImmutable $asOf): int
    {
        $raw = trim((string)$moveInDate);
        if ($raw === '') {
            return 1;
        }

        try {
            $start = room_rent_month(new DateTimeImmutable($raw));
        } catch (Throwable $ignored) {
            return 1;
        }

        $end = room_rent_month($asOf);
        if ($start > $end) {
            return 0;
        }

        $diff = $start->diff($end);

        return max(1, ((int)$diff->y * 12) + (int)$diff->m + 1);
    }
}

if (!function_exists('rent_add_months')) {
    function rent_add_months(DateTimeImmutable $from, int $months): DateTimeImmutable
    {
        return room_rent_month($from)->modify('+' . max(0, $months) . ' months');
    }
}

if (!function_exists('clear_room_tenant_rent_snapshots')) {
    /**
     * Active tenants in a room inherit the new room rent.
     * Snapshot is cleared rather than overwritten so the room stays authoritative.
     *
     * @return int number of tenants updated
     */
    function clear_room_tenant_rent_snapshots(PDO $pdo, int $roomId, int $adminId): int
    {
        if ($roomId <= 0 || $adminId <= 0) {
            return 0;
        }

        $stmt = $pdo->prepare("
            UPDATE tenants
            SET monthly_rent = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE room_id = :room_id
              AND admin_id = :admin_id
              AND LOWER(COALESCE(status, 'active')) = 'active'
              AND exit_date IS NULL
              AND monthly_rent IS NOT NULL
        ");
        $stmt->execute([':room_id' => $roomId, ':admin_id' => $adminId]);

        return (int)$stmt->rowCount();
    }
}

if (!function_exists('clear_room_type_tenant_rent_snapshots')) {
    /**
     * After a room type template rent changes, active tenants in rooms of that
     * type fall back to the template rent (unless the room carries its own).
     *
     * @return int number of tenants updated
     */
    function clear_room_type_tenant_rent_snapshots(PDO $pdo, int $roomTypeId, int $adminId): int
    {
        if ($roomTypeId <= 0 || $adminId <= 0) {
            return 0;
        }

        $stmt = $pdo->prepare("
            UPDATE tenants
            SET monthly_rent = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE admin_id = :admin_id
              AND LOWER(COALESCE(status, 'active')) = 'active'
              AND exit_date IS NULL
              AND monthly_rent IS NOT NULL
              AND room_id IN (
                  SELECT r.id
                  FROM rooms r
                  WHERE r.room_type_id = :room_type_id
                    AND r.admin_id = :room_admin_id
                    AND r.monthly_rent IS NULL
              )
        ");
        $stmt->execute([
            ':admin_id' => $adminId,
            ':room_type_id' => $roomTypeId,
            ':room_admin_id' => $adminId,
        ]);

        return (int)$stmt->rowCount();
    }
}
