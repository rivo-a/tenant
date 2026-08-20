<?php
require_once __DIR__ . '/../config/config.php';

class BaseController
{
    protected static function requireLogin(): void
    {
        if (!isset($_SESSION['admin_id'])) {
            header('Location: /login.php');
            exit;
        }
    }

    protected static function requireRole(array $roles): void
    {
        $role = $_SESSION['admin_role'] ?? 'caretaker';

        if (!in_array($role, $roles, true)) {
            http_response_code(403);
            exit('Access denied');
        }
    }

    protected static function db(): PDO
    {
        return getDB();
    }
}
?>