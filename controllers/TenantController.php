<?php
// controllers/TenantController.php

require_once __DIR__ . '/BaseController.php';

class TenantController extends BaseController
{
    public static function all()
    {
        $stmt = self::db()->prepare(
            "SELECT * FROM tenants WHERE owner_id = ? ORDER BY id DESC"
        );
        $stmt->execute([self::ownerId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create($name, $phone)
    {
        $stmt = self::db()->prepare(
            "INSERT INTO tenants (name, phone, owner_id)
             VALUES (?, ?, ?)"
        );
        return $stmt->execute([$name, $phone, self::ownerId()]);
    }

    public static function delete($id)
    {
        $stmt = self::db()->prepare(
            "DELETE FROM tenants WHERE id = ? AND owner_id = ?"
        );
        return $stmt->execute([$id, self::ownerId()]);
    }
}
?>