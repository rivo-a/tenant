<?php
require_once __DIR__ . '/../config/config.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $username  = trim($_POST['username']);
    $email     = trim($_POST['email']);
    $password  = password_hash($_POST['password'], PASSWORD_BCRYPT);

    // Check if username/email exists
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username=? OR email=?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        $message = "Username or email already exists.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO admins (full_name, username, email, password) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$full_name, $username, $email, $password])) {
            $message = "Admin registered successfully! <a href='login.php'>Login here</a>";
        } else {
            $message = "Error during registration.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Registration</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<h2>Admin Registration</h2>
<?php if ($message) echo "<p>$message</p>"; ?>
<form method="POST" action="">
    <input type="text" name="full_name" placeholder="Full Name" required><br><br>
    <input type="text" name="username" placeholder="Username" required><br><br>
    <input type="email" name="email" placeholder="Email" required><br><br>
    <input type="password" name="password" placeholder="Password" required><br><br>
    <button type="submit">Register</button>
</form>
</body>
</html>
