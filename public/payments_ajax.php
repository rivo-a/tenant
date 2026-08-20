<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/tenant_filter.php';

require_login();

$admin_id = (int)$_SESSION['admin_id'];

$params = [
    'search' => $_GET['q'] ?? '',
    'room_type' => $_GET['room_type'] ?? '',
    'limit' => 20,
    'offset' => max(0, ((int)($_GET['page'] ?? 1) - 1) * 20)
];

$tenants = getFilteredTenants($pdo, $admin_id, $params);

foreach ($tenants as $t):
?>
<tr>
<td><?= htmlspecialchars($t['full_name']) ?></td>
<td><?= htmlspecialchars($t['room_number'] ?? '-') ?></td>
<td><?= number_format((float)$t['monthly_rent_effective']) ?></td>
<td><?= number_format((float)$t['outstanding_balance']) ?></td>
<td>
<form method="post">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>">
<input type="number" name="amount" required>
<input type="date" name="payment_date" value="<?= date('Y-m-d') ?>">
<button name="receive_payment">Pay</button>
</form>
</td>
</tr>
<?php endforeach; ?>
