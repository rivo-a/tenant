<?php
// controllers/RoomController.php

require_once __DIR__ . '/BaseController.php';

class RoomController extends BaseController
{
    public static function all()
    {
        $stmt = self::db()->prepare(
            "SELECT * FROM rooms_new WHERE owner_id = ?"
        );
        $stmt->execute([self::ownerId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create($room_no, $type_id, $rent)
    {
        $stmt = self::db()->prepare(
            "INSERT INTO rooms_new (room_no, type_id, rent, owner_id)
             VALUES (?, ?, ?, ?)"
        );
        return $stmt->execute([
            $room_no, $type_id, $rent, self::ownerId()
        ]);
    }

    public static function delete($id)
    {
        $stmt = self::db()->prepare(
            "DELETE FROM rooms_new WHERE id = ? AND owner_id = ?"
        );
        return $stmt->execute([$id, self::ownerId()]);
    }
}
?>