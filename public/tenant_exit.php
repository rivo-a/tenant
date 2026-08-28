<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';

$tenantId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$adminId = (int) $_SESSION['admin_id'];

if ($tenantId <= 0) {
    die("Invalid tenant ID.");
}

function monthsAndDaysBetween(DateTime $start, DateTime $end): array
{
    $invert = $start > $end;
    if ($invert) {
        $tmp = $start;
        $start = $end;
        $end = $tmp;
    }
    $diff = $start->diff($end);
    return [(($diff->y * 12) + $diff->m), $diff->d];
}

/**
 * STEP 1: Load tenant, room, and room type data
 */
$stmt = $pdo->prepare("
    SELECT 
        t.id, t.full_name, t.move_in_date, t.status, 
        t.monthly_rent AS tenant_rent,
        r.id AS room_id, r.room_number, r.monthly_rent AS room_rent,
        rt.default_monthly_rent AS type_rent
    FROM tenants t
    JOIN rooms r ON t.room_id = r.id
    LEFT JOIN room_types rt ON r.room_type_id = rt.id
    WHERE t.id = :id AND t.admin_id = :admin_id
    LIMIT 1
");

$stmt->execute(['id' => $tenantId, 'admin_id' => $adminId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    die("Tenant not found or you do not have permission.");
}

if ($tenant['status'] !== 'active') {
    die("This tenant is already exited.");
}

// Determine the effective rent (Tenant -> Room -> Room Type)
$effectiveRent = $tenant['tenant_rent'] ?? $tenant['room_rent'] ?? $tenant['type_rent'];
$rentMissing = (empty($effectiveRent) || (float)$effectiveRent <= 0);

/**
 * STEP 2: Handle Form Submission
 */
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exitDateInput = $_POST['exit_date'] ?? date('Y-m-d');
    $exitReason = trim($_POST['exit_reason'] ?? '');
    $allowUncleared = isset($_POST['allow_uncleared']);
    
    // If rent was missing in DB, use the manually entered amount
    $finalMonthlyRent = $rentMissing ? (float)($_POST['manual_rent'] ?? 0) : (float)$effectiveRent;

    if ($exitReason === '') {
        $error = "Exit reason is required.";
    } elseif ($rentMissing && $finalMonthlyRent <= 0) {
        $error = "Please enter a valid monthly rent to calculate the balance.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Exit tenant (Removed exit_reason as column doesn't exist)
            $stmt = $pdo->prepare("
                UPDATE tenants
                SET status = 'exited', exit_date = :exit_date
                WHERE id = :id AND admin_id = :admin_id
            ");
            $stmt->execute([
                'exit_date' => $exitDateInput,
                'id'        => $tenantId,
                'admin_id'  => $adminId
            ]);

            // 2. Free the room (Schema uses 'free', not 'vacant')
            $stmt = $pdo->prepare("
                UPDATE rooms
                SET status = 'free'
                WHERE id = :room_id AND admin_id = :admin_id
            ");
            $stmt->execute(['room_id' => $tenant['room_id'], 'admin_id' => $adminId]);

            // 3. Log to audit_logs
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (admin_id, action, description)
                    VALUES (:admin_id, 'TENANT_EXIT', :desc)
                ");
                $stmt->execute([
                    'admin_id' => $adminId,
                    'desc'     => "Tenant '{$tenant['full_name']}' exited. Reason: {$exitReason}. Monthly Rent Used: {$finalMonthlyRent}"
                ]);
            } catch (Throwable $e) { /* Ignore audit errors */ }

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
 * STEP 3: Calculate Financials (if rent is known)
 */
$monthlyRent = $rentMissing ? 0 : (float)$effectiveRent;
$totalRentCharged = 0;
$totalPayments = 0;
$totalArrears = 0;
$durationStr = '0 days';

if ($monthlyRent > 0) {
    $moveInDate = new DateTime($tenant['move_in_date']);
    $exitDate = new DateTime();
    list($monthsOccupied, $daysOccupied) = monthsAndDaysBetween($moveInDate, $exitDate);

    $dailyRate = $monthlyRent / 30;
    $totalRentCharged = ($monthsOccupied * $monthlyRent) + ($daysOccupied * $dailyRate);
    $durationStr = ($monthsOccupied > 0 ? $monthsOccupied . ' month' . ($monthsOccupied > 1 ? 's' : '') . ' ' : '') . $daysOccupied . ' day' . ($daysOccupied !== 1 ? 's' : '');

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE tenant_id = :tenant_id AND admin_id = :admin_id");
    $stmt->execute(['tenant_id' => $tenantId, 'admin_id' => $adminId]);
    $totalPayments = (float)$stmt->fetchColumn();

    $totalArrears = max(0, $totalRentCharged - $totalPayments);
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Exit Tenant</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f9; padding: 20px; }
        .box { max-width: 700px; margin: 40px auto; padding: 30px; border: 1px solid #ddd; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .error { color: #d9534f; background: #f2dede; padding: 12px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #ebccd1; }
        .row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee; }
        .label { font-weight: 600; color: #555; }
        .value { color: #333; }
        .total-row { font-size: 1.3em; font-weight: bold; border-top: 3px solid #333; border-bottom: none; margin-top: 10px; padding-top: 15px; }
        .positive { color: #d9534f; }
        .negative { color: #5cb85c; }
        .warning-box { background: #fff3cd; color: #856404; padding: 15px; border-radius: 4px; border: 1px solid #ffeeba; margin-bottom: 20px; }
        input[type="date"], input[type="text"], input[type="number"] { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .btn { padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; margin-right: 10px; }
        .btn-danger { background: #d9534f; color: white; }
        .btn-secondary { background: #ddd; color: #333; text-decoration: none; display: inline-block; padding: 12px 24px; border-radius: 4px; }
        .checkbox-label { display: flex; align-items: center; gap: 8px; margin: 15px 0; color: #d9534f; font-weight: 600; }
    </style>
</head>
<body>

<div class="box">
    <h2 style="margin-top: 0;">Exit Tenant</h2>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="row">
        <span class="label">Name:</span>
        <span class="value"><?= htmlspecialchars($tenant['full_name']) ?></span>
    </div>
    <div class="row">
        <span class="label">Room:</span>
        <span class="value"><?= htmlspecialchars($tenant['room_number']) ?></span>
    </div>

    <?php if ($rentMissing): ?>
        <div class="warning-box">
            ⚠️ <strong>Missing Rent Data:</strong> The monthly rent is not recorded in the database for this tenant or room. 
            Please enter the agreed monthly rent below so we can calculate the final balance.
        </div>
        <div class="row" style="flex-direction: column;">
            <label class="label" style="margin-bottom: 5px;">Agreed Monthly Rent (UGX):</label>
            <input type="number" name="manual_rent" value="<?= htmlspecialchars($_POST['manual_rent'] ?? '') ?>" placeholder="e.g. 700000" required style="margin-top: 0;">
        </div>
    <?php else: ?>
        <div class="row">
            <span class="label">Duration Occupied:</span>
            <span class="value"><?= $durationStr ?></span>
        </div>
        <div class="row">
            <span class="label">Monthly Rent:</span>
            <span class="value">UGX <?= number_format($monthlyRent, 0) ?></span>
        </div>
        <div class="row">
            <span class="label">Total Rent Charged:</span>
            <span class="value">UGX <?= number_format($totalRentCharged, 2) ?></span>
        </div>
        <div class="row">
            <span class="label">Total Payments Made:</span>
            <span class="value negative">UGX <?= number_format($totalPayments, 2) ?></span>
        </div>
        <div class="row total-row">
            <span>Total Arrears / Outstanding Balance:</span>
            <span class="<?= $totalArrears > 0 ? 'positive' : 'negative' ?>">
                UGX <?= number_format($totalArrears, 2) ?>
            </span>
        </div>
    <?php endif; ?>

    <form method="post" style="margin-top: 30px;">
        <label style="font-weight: 600;">Exit Date:</label>
        <input type="date" name="exit_date" value="<?= date('Y-m-d') ?>" required>

        <label style="font-weight: 600; margin-top: 20px; display: block;">Exit Reason:</label>
        <input type="text" name="exit_reason" placeholder="e.g., End of lease, Relocation, Non-payment" value="<?= htmlspecialchars($_POST['exit_reason'] ?? '') ?>" required>

        <?php if (!$rentMissing && $totalArrears > 0): ?>
            <label class="checkbox-label">
                <input type="checkbox" name="allow_uncleared">
                Allow exit despite outstanding arrears of UGX <?= number_format($totalArrears, 2) ?>
            </label>
        <?php endif; ?>

        <div style="margin-top: 25px;">
            <button type="submit" class="btn btn-danger">Confirm Exit</button>
            <a href="tenants.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

</body>
</html>