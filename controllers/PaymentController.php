<?php
// controllers/PaymentController.php

require_once __DIR__ . '/BaseController.php';

class PaymentController extends BaseController
{
    public static function all()
    {
        $stmt = self::db()->prepare(
            "SELECT * FROM payments WHERE owner_id = ? ORDER BY paid_at DESC"
        );
        $stmt->execute([self::ownerId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function record($lease_id, $amount)
    {
        $stmt = self::db()->prepare(
            "INSERT INTO payments (lease_id, amount, owner_id)
             VALUES (?, ?, ?)"
        );
        return $stmt->execute([
            $lease_id, $amount, self::ownerId()
        ]);
    }
}
?>