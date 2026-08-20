<?php
/**
 * Tenant Exit Module
 * Safely exits a tenant, calculates final balance,
 * locks tenant record, and frees the room.
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';

$tenantId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($tenantId <= 0) {
    die("Invalid tenant ID.");
}

/**
 * Utility: calculate months and days between two dates
 */
function monthsAndDaysBetween(DateTime $start, DateTime $end): array
{
    $invert = $start > $end;
    if ($invert) {
        $tmp = $start;
        $start = $end;
        $end = $tmp;
    }

    $diff = $start->diff($end);
    $months = ($diff->y * 12) + $diff->m;
    $days = $diff->d;

    return [$months, $days];
}

/**
 * STEP 1: Load tenant and room data
 */
$stmt = $pdo->prepare("
    SELECT 
        t.id,
        t.full_name,
        t.move_in_date,
        t.status,
        r.id AS room_id,
        r.room_number,
        r.monthly_rent AS effective_rent
    FROM tenants t
    JOIN rooms r ON r.id = t.room_id
    WHERE t.id = :id
    LIMIT 1
");

$stmt->execute(['id' => $tenantId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    die("Tenant not found.");
}

if ($tenant['status'] !== 'active') {
    die("This tenant is already exited.");
}

/**
 * STEP 2: Compute financials
 */
$moveInDate = new DateTime($tenant['move_in_date']);
$exitDate = new DateTime(); // default today

list($monthsOccupied, $daysOccupied) = monthsAndDaysBetween(clone $moveInDate, clone $exitDate);
$totalRentCharged = $monthsOccupied * (float)$tenant['effective_rent'];

/**
 * Total payments made
 */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE tenant_id = :tenant_id
");
$stmt->execute(['tenant_id' => $tenantId]);
$totalPayments = (float)$stmt->fetchColumn();

$finalBalance = $totalRentCharged - $totalPayments;

/**
 * STEP 3: Handle form submission
 */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $exitDateInput = $_POST['exit_date'] ?? date('Y-m-d');
    $exitReason = trim($_POST['exit_reason'] ?? '');
    $allowUncleared = isset($_POST['allow_uncleared']);

    if ($exitReason === '') {
        $error = "Exit reason is required.";
    } elseif ($finalBalance > 0 && !$allowUncleared) {
        $error = "Tenant has an outstanding balance. Override required.";
    } else {
        try {
            $pdo->beginTransaction();

            /**
             * Exit tenant
             */
            $stmt = $pdo->prepare("
                UPDATE tenants
                SET status = 'exited',
                    exit_date = :exit_date,
                    exit_reason = :exit_reason
                WHERE id = :id
            ");
            $stmt->execute([
                'exit_date'   => $exitDateInput,
                'exit_reason' => $exitReason,
                'id'          => $tenantId
            ]);

            /**
             * Free the room
             */
            $stmt = $pdo->prepare("
                UPDATE rooms
                SET status = 'vacant'
                WHERE id = :room_id
            ");
            $stmt->execute(['room_id' => $tenant['room_id']]);

            /**
             * Optional: audit log (if table exists)
             */
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (admin_id, action, reference_id, description)
                    VALUES (:admin_id, 'TENANT_EXIT', :ref, :desc)
                ");
                $stmt->execute([
                    'admin_id' => $_SESSION['admin_id'],
                    'ref'      => $tenantId,
                    'desc'     => "Tenant exited with balance {$finalBalance}"
                ]);
            } catch (Throwable $e) {
                // Audit logging failure must not block exit
            }

            $pdo->commit();

            header("Location: tenants.php?exit=success");
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = "Exit failed: " . $e->getMessage();
        }
    }
}

/**
 * Format months + days nicely
 */
$durationStr = '';
if ($monthsOccupied > 0) {
    $durationStr .= $monthsOccupied . ' month' . ($monthsOccupied > 1 ? 's' : '');
}
if ($daysOccupied > 0 || $monthsOccupied === 0) {
    $durationStr .= ($durationStr ? ' ' : '') . $daysOccupied . ' day' . ($daysOccupied !== 1 ? 's' : '');
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Exit Tenant</title>
    <style>
        body { font-family: Arial, sans-serif; }
        .box { max-width: 600px; margin: 40px auto; padding: 20px; border: 1px solid #ccc; }
        .error { color: red; }
        .warn { color: darkorange; }
    </style>
</head>
<body>

<div class="box">
    <h2>Exit Tenant</h2>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <p><strong>Name:</strong> <?= htmlspecialchars($tenant['full_name']) ?></p>
    <p><strong>Room:</strong> <?= htmlspecialchars($tenant['room_number']) ?></p>
    <p><strong>Duration Occupied:</strong> <?= $durationStr ?></p>
    <p><strong>Total Rent Charged:</strong> <?= number_format($totalRentCharged, 2) ?></p>
    <p><strong>Total Payments:</strong> <?= number_format($totalPayments, 2) ?></p>

    <p class="<?= $finalBalance > 0 ? 'warn' : '' ?>">
        <strong>Final Balance:</strong> <?= number_format($finalBalance, 2) ?>
    </p>

    <form method="post">
        <label>Exit Date:</label><br>
        <input type="date" name="exit_date" value="<?= date('Y-m-d') ?>" required><br><br>

        <label>Exit Reason:</label><br>
        <input type="text" name="exit_reason" required style="width:100%;"><br><br>

        <?php if ($finalBalance > 0): ?>
            <label>
                <input type="checkbox" name="allow_uncleared">
                Allow exit despite outstanding balance
            </label><br><br>
        <?php endif; ?>

        <button type="submit">Confirm Exit</button>
        <a href="tenants.php">Cancel</a>
    </form>

    <p>
        <a href="rooms.php">Manage Rooms</a> |
        <a href="tenants.php">Manage Tenants</a> |
        <a href="logout.php">Logout</a>
    </p>
</div>

</body>
</html>
