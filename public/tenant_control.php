<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/TenantService.php';

require_login();

$admin_id = (int)$_SESSION['admin_id'];
$tenantService = new TenantService($pdo);

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['onboard_tenant'])) {
    try {
        verify_csrf($_POST['csrf'] ?? '');

        $room_id      = (int)($_POST['room_id'] ?? 0);
        $full_name    = trim($_POST['full_name'] ?? '');
        $phone        = trim($_POST['phone'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $move_in_date = $_POST['move_in_date'] ?? date('Y-m-d');

        if ($full_name === '') {
            throw new RuntimeException('Tenant name is required.');
        }
        if ($room_id <= 0) {
            throw new RuntimeException('Please select a room.');
        }

        /* 🔒 Fetch room snapshot (authoritative) */
        $stmt = $pdo->prepare("
            SELECT 
                r.id,
                r.room_number,
                r.monthly_rent,
                rt.default_monthly_rent
            FROM rooms r
            LEFT JOIN room_types rt ON rt.id = r.room_type_id
            WHERE r.id = :room_id
              AND r.admin_id = :admin_id
              AND r.id NOT IN (
                    SELECT room_id 
                    FROM tenants 
                    WHERE status = 'active'
              )
        ");
        $stmt->execute([
            ':room_id' => $room_id,
            ':admin_id' => $admin_id
        ]);

        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new RuntimeException('Selected room is not available.');
        }

        /* ✅ Resolve rent correctly */
        $monthlyRent = $room['monthly_rent'] 
            ?? $room['default_monthly_rent'] 
            ?? 0;

        $tenantService->addTenant([
            'room_id'      => $room_id,
            'full_name'    => $full_name,
            'phone'        => $phone,
            'email'        => $email,
            'move_in_date' => $move_in_date,
            'monthly_rent' => (float)$monthlyRent
        ], $admin_id);

        $success = 'Tenant onboarded successfully.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Tenant Control</title>
<link rel="stylesheet" href="/tenant-system/assets/css/tenant-control.css">
</head>

<body>

<h2>Onboard Tenant</h2>

<nav>
    <a href="rooms.php">Rooms</a>
    <a href="tenants.php">Tenants</a>
    <a href="payments.php">Payments</a>
    <a href="payments_history.php">History</a>
    <a href="logout.php">Logout</a>
</nav>

<?php if ($success): ?><p class="success"><?= htmlspecialchars($success) ?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">

<label>Full Name</label>
<input name="full_name" required>

<label>Phone</label>
<input name="phone">

<label>Email</label>
<input name="email">

<label>Move-in Date</label>
<input type="date" name="move_in_date" value="<?= date('Y-m-d') ?>">

<label>Select Room</label>
<select name="room_id" required>
<option value="">-- Select Available Room --</option>

<?php
$stmt = $pdo->prepare("
    SELECT 
        r.id,
        r.room_number,
        rt.name AS room_type
    FROM rooms r
    LEFT JOIN room_types rt ON rt.id = r.room_type_id
    WHERE r.admin_id = :admin_id
      AND r.id NOT IN (
            SELECT room_id 
            FROM tenants 
            WHERE status = 'active'
      )
    ORDER BY r.room_number
");
$stmt->execute([':admin_id' => $admin_id]);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $room):
?>
<option value="<?= (int)$room['id'] ?>">
    <?= htmlspecialchars($room['room_number']) ?>
    (<?= htmlspecialchars($room['room_type'] ?? 'N/A') ?>)
</option>
<?php endforeach; ?>
</select>

<button name="onboard_tenant">Onboard Tenant</button>
</form>

</body>
</html>
