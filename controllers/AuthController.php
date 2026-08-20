<?php
require_once __DIR__ . '/../auth/AuthService.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (AuthService::login($username, $password)) {
    header("Location: /index.php?action=dashboard");
    exit;
}

    } else {
        header("Location: /login.php?error=1");
        exit;
    }

?>