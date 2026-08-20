<?php
// controllers/LeaseController.php

require_once __DIR__ . '/BaseController.php';

class LeaseController extends BaseController
{
    public static function all()
    {
        $stmt = self::db()->prepare(
            "SELECT * FROM leases WHERE owner_id = ?"
        );
        $stmt->execute([self::ownerId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create($tenant_id, $room_id, $start, $end)
    {
        $stmt = self::db()->prepare(
            "INSERT INTO leases (tenant_id, room_id, start_date, end_date, owner_id)
             VALUES (?, ?, ?, ?, ?)"
        );
        return $stmt->execute([
            $tenant_id, $room_id, $start, $end, self::ownerId()
        ]);
    }
}
?>