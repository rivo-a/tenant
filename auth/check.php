<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php?action=login');
exit;

}
?>