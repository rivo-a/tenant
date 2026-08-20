<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

$action = $_GET['action'] ?? 'dashboard';

switch ($action) {

    case 'login':
        require_once __DIR__ . '/login.php';
        break;

    case 'dashboard':
        require_once __DIR__ . '/../auth/check.php';
        require_once __DIR__ . '/../views/layout/header.php';
        require_once __DIR__ . '/dashboard.php';
        require_once __DIR__ . '/../views/layout/footer.php';
        break;

    case 'logout':
        require_once __DIR__ . '/../auth/logout.php';
        break;

    default:
        http_response_code(404);
        echo 'Page not found';
}
?>