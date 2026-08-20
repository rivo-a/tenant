<?php
// controllers/RoomTypeController.php

require_once __DIR__ . '/BaseController.php';

class RoomTypeController extends BaseController
{
    public static function all()
    {
        $stmt = self::db()->prepare(
            "SELECT * FROM room_types WHERE owner_id = ?"
        );
        $stmt->execute([self::ownerId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create($name)
    {
        $stmt = self::db()->prepare(
            "INSERT INTO room_types (name, owner_id)
             VALUES (?, ?)"
        );
        return $stmt->execute([$name, self::ownerId()]);
    }
}
?>