<?php
require_once __DIR__ . '/../core/bootstrap.php';

class AuthService {

    public static function login($username, $password) {
        $db = getDB();

        $stmt = $db->prepare("
            SELECT * FROM admins 
            WHERE username = :u OR email = :u
            LIMIT 1
        ");
        $stmt->execute(['u' => $username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            return false;
        }

        if (!password_verify($password, $admin['password'])) {
            return false;
        }

        // SESSION SECURITY
        session_regenerate_id(true);

        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_role'] = $admin['role'];


        return true;
    }

    public static function logout() {
        session_destroy();
        header("Location: /login.php");
        exit;
    }

    public static function check() {
        return isset($_SESSION['admin']);
    }

    public static function user() {
        return $_SESSION['admin'] ?? null;
    }
}
?>