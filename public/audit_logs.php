<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

/* =============================
   AUTH
============================= */
if (!isLoggedIn()) {
    redirect('login.php');
}

$admin_id = (int) $_SESSION['admin_id'];
requireRole(['super_admin']);

/*
 NOTE:
 Later, when we add permissions:
 if (!isSuperAdmin()) { deny access }
*/

/* =============================
   INPUTS (FILTERS)
============================= */
$action   = trim($_GET['action'] ?? '');
$admin    = (int) ($_GET['admin'] ?? 0);
$page     = max(1, (int) ($_GET['page'] ?? 1));
$limit    = 25;
$offset   = ($page - 1) * $limit;

/* =============================
   BUILD QUERY
============================= */
$where = [];
$params = [];

if ($action !== '') {
    $where[] = 'al.action = ?';
    $params[] = $action;
}

if ($admin > 0) {
    $where[] = 'al.admin_id = ?';
    $params[] = $admin;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* =============================
   FETCH LOGS
============================= */
$stmt = $pdo->prepare(
    "SELECT al.id,
            al.action,
            al.description,
            al.created_at,
            a.username
     FROM audit_logs al
     JOIN admins a ON a.id = al.admin_id
     $whereSQL
     ORDER BY al.created_at DESC
     LIMIT $limit OFFSET $offset"
);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =============================
   TOTAL COUNT (PAGINATION)
============================= */
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM audit_logs al
     $whereSQL"
);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = (int) ceil($total / $limit);

/* =============================
   FILTER DATA
============================= */
$actions = $pdo->query(
    "SELECT DISTINCT action FROM audit_logs ORDER BY action"
)->fetchAll(PDO::FETCH_COLUMN);

$admins = $pdo->query(
    "SELECT id, username FROM admins ORDER BY username"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Audit Logs</title>
<style>
body{font-family:Arial,sans-serif;}
table{border-collapse:collapse;width:100%;}
th,td{border:1px solid #ccc;padding:8px;font-size:14px;}
th{background:#f4f4f4;}
.filter-box{margin-bottom:15px;}
.badge{padding:3px 6px;border-radius:3px;font-size:11px;color:#fff;}
.action{background:#6c757d;}
.time{color:#666;font-size:12px;}
.pagination a{margin:0 3px;text-decoration:none;}
.pagination strong{margin:0 3px;}
</style>
</head>
<body>

<h2>Audit Logs</h2>
<p style="color:#666;">
All sensitive system actions are recorded here. This page is read-only.
</p>

<!-- FILTERS -->
<div class="filter-box">
<form method="get">
<select name="action">
<option value="">All Actions</option>
<?php foreach ($actions as $a): ?>
<option value="<?= htmlspecialchars($a) ?>"
<?= $a === $action ? 'selected' : '' ?>>
<?= htmlspecialchars($a) ?>
</option>
<?php endforeach; ?>
</select>

<select name="admin">
<option value="">All Admins</option>
<?php foreach ($admins as $a): ?>
<option value="<?= $a['id'] ?>"
<?= $a['id'] === $admin ? 'selected' : '' ?>>
<?= htmlspecialchars($a['username']) ?>
</option>
<?php endforeach; ?>
</select>

<button type="submit">Filter</button>
<a href="audit_logs.php">Reset</a>
</form>
</div>

<!-- LOG TABLE -->
<table>
<tr>
<th>#</th>
<th>Admin</th>
<th>Action</th>
<th>Description</th>
<th>Time</th>
</tr>

<?php if (!$logs): ?>
<tr>
<td colspan="5" style="text-align:center;color:#777;">
No audit logs found.
</td>
</tr>
<?php endif; ?>

<?php foreach ($logs as $log): ?>
<tr>
<td><?= $log['id'] ?></td>
<td><?= htmlspecialchars($log['username']) ?></td>
<td><span class="badge action"><?= htmlspecialchars($log['action']) ?></span></td>
<td><?= htmlspecialchars($log['description']) ?></td>
<td class="time"><?= htmlspecialchars($log['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</table>

<!-- PAGINATION -->
<?php if ($pages > 1): ?>
<div class="pagination">
Pages:
<?php for ($i = 1; $i <= $pages; $i++): ?>
<?php if ($i === $page): ?>
<strong><?= $i ?></strong>
<?php else: ?>
<a href="?<?= http_build_query([
    'page'   => $i,
    'action' => $action,
    'admin'  => $admin
]) ?>"><?= $i ?></a>
<?php endif; ?>
<?php endfor; ?>
</div>
<?php endif; ?>

<hr>

<p>
<a href="dashboard.php">Dashboard</a> |
<a href="rooms.php">Rooms</a> |
<a href="tenants.php">Tenants</a> |
<a href="payments_history.php">Payments</a> |
<a href="logout.php">Logout</a>
</p>

</body>
</html>
