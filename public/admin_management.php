<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
requireSuperAdmin();

$success = $error = '';

/* =============================
   CREATE CARETAKER
============================= */
if (isset($_POST['create_admin'])) {
    $name     = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name && $username && $password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO admins (full_name, username, password_hash, role, is_active)
                VALUES (?, ?, ?, 'caretaker', 1)
            ");
            $stmt->execute([$name, $username, $hash]);

            try {
                logAudit(
                    (int)$_SESSION['admin_id'],
                    'ADMIN_CREATED',
                    "Caretaker {$username} created"
                );
            } catch (Throwable $ignored) {}
            $success = "Caretaker created successfully.";
        } catch (PDOException $e) {
            $error = "Username already exists.";
        }
    } else {
        $error = "All fields are required.";
    }
}

/* =============================
   ROLE UPDATE
============================= */
if (isset($_POST['update_role'])) {
    $id   = (int)$_POST['admin_id'];
    $role = $_POST['role'];

    if (in_array($role, ['super_admin', 'caretaker'], true)) {
        $stmt = $pdo->prepare("UPDATE admins SET role = ? WHERE id = ?");
        $stmt->execute([$role, $id]);

        try {
            logAudit(
                (int)$_SESSION['admin_id'],
                'ROLE_CHANGED',
                "Admin {$id} set to {$role}"
            );
        } catch (Throwable $ignored) {}
        $success = "Role updated.";
    }
}

/* =============================
   ACTIVATE / DEACTIVATE
============================= */
if (isset($_POST['toggle_status'])) {
    $id = (int)$_POST['admin_id'];

    if ($id !== $_SESSION['admin_id']) {
        $stmt = $pdo->prepare("
            UPDATE admins
            SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        try {
            logAudit(
                (int)$_SESSION['admin_id'],
                'STATUS_CHANGED',
                "Admin {$id} status toggled"
            );
        } catch (Throwable $ignored) {}
        $success = "Status updated.";
    }
}

/* =============================
   LOAD ADMINS
============================= */
$admins = $pdo->query("
    SELECT id, full_name, username, role, is_active
    FROM admins
    ORDER BY role DESC, full_name
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Admin Management</title>
<style>
body{font-family:Arial}
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #ccc;padding:8px}
.badge{padding:3px 6px;color:#fff;border-radius:3px;font-size:11px}
.super{background:#dc3545}
.caretaker{background:#6c757d}
.active{color:green}
.disabled{color:red}
</style>
</head>
<body>

<h2>Admin / Caretaker Management</h2>

<?= $success ? "<p style='color:green'>$success</p>" : '' ?>
<?= $error ? "<p style='color:red'>$error</p>" : '' ?>

<h3>Create Caretaker</h3>
<form method="post">
<input name="full_name" placeholder="Full name" required>
<input name="username" placeholder="Username" required>
<input name="password" type="password" placeholder="Password" required>
<button name="create_admin">Create</button>
</form>

<hr>

<h3>Admins</h3>
<table>
<tr>
<th>Name</th>
<th>Username</th>
<th>Role</th>
<th>Status</th>
<th>Actions</th>
</tr>

<?php foreach ($admins as $a): ?>
<tr>
<td><?= htmlspecialchars($a['full_name']) ?></td>
<td><?= htmlspecialchars($a['username']) ?></td>
<td>
<span class="badge <?= $a['role'] === 'super_admin' ? 'super' : 'caretaker' ?>">
<?= strtoupper($a['role']) ?>
</span>
</td>
<td class="<?= $a['is_active'] ? 'active' : 'disabled' ?>">
<?= $a['is_active'] ? 'Active' : 'Disabled' ?>
</td>
<td>
<?php if ($a['id'] !== $_SESSION['admin_id']): ?>
<form method="post" style="display:inline">
<input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
<select name="role">
<option value="caretaker">Caretaker</option>
<option value="super_admin">Super Admin</option>
</select>
<button name="update_role">Change</button>
</form>

<form method="post" style="display:inline">
<input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
<button name="toggle_status">Enable / Disable</button>
</form>
<?php else: ?>
—
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</table>

<p>
<a href="dashboard.php">Dashboard</a> |
<a href="logout.php">Logout</a>
</p>

</body>
</html>
