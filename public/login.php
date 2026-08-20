<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

if (isLoggedIn()) {
    redirect('payments.php'); // or dashboard.php if you have it
}

$pdo = getDB();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['csrf'] ?? '');

        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new RuntimeException('Username and password are required.');
        }

        // ✅ IMPORTANT: include role in SELECT
        $stmt = $pdo->prepare("
            SELECT id, username, password, full_name, role
            FROM admins
            WHERE username = :username
            LIMIT 1
        ");
        $stmt->execute([':username' => $username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            throw new RuntimeException('Invalid username or password.');
        }

        if (!password_verify($password, (string)$admin['password'])) {
            throw new RuntimeException('Invalid username or password.');
        }

        secure_login();

        // ✅ Single consistent session contract (new)
        $_SESSION['admin'] = [
            'id'   => (int)$admin['id'],
            'name' => (string)$admin['full_name'],
            'role' => (string)($admin['role'] ?? 'caretaker'),
        ];

        // ✅ Backward compatibility (so older pages still work)
        $_SESSION['admin_id']   = (int)$admin['id'];
        $_SESSION['admin_name'] = (string)$admin['full_name'];
        $_SESSION['admin_role'] = (string)($admin['role'] ?? 'caretaker');

        // ✅ Optional audit
        if (function_exists('logAudit')) {
            try {
                logAudit((int)$admin['id'], 'LOGIN', 'Admin logged in.');
            } catch (Throwable $ignored) {}
        }

        redirect('dashboard.php');

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Login</title>
<style>
body{font-family:sans-serif;max-width:520px;margin:40px auto;}
label{display:block;margin-top:10px;}
input{width:100%;padding:10px;margin-top:5px;}
button{margin-top:15px;padding:10px 14px;}
.error{color:#b00020;}
</style>
</head>
<body>

<h2>Admin Login</h2>

<?php if ($error): ?>
  <p class="error"><?= e($error) ?></p>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <label>Username</label>
  <input type="text" name="username" autocomplete="username" required>

  <label>Password</label>
  <input type="password" name="password" autocomplete="current-password" required>

  <button type="submit">Login</button>
</form>

</body>
</html>
