<?php
declare(strict_types=1);

/*
 * Self-service landlord signup.
 *
 * The previous version required only config/config.php, then used an undefined
 * $pdo and hid failures. It now runs on the normal bootstrap: CSRF, a hashed
 * password, an explicit landlord role, and straight into onboarding.
 */

require_once __DIR__ . '/../core/bootstrap.php';

if (!empty($_SESSION['admin_id'])) {
    redirect(auth_home_for_role());
}

$errors = [];
$form = ['full_name' => '', 'username' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $read = static fn(string $k): string => is_scalar($_POST[$k] ?? null) ? trim((string)$_POST[$k]) : '';
    $form = [
        'full_name' => $read('full_name'),
        'username' => $read('username'),
        'email' => $read('email'),
        'phone' => $read('phone'),
    ];
    $password = is_scalar($_POST['password'] ?? null) ? (string)$_POST['password'] : '';
    $confirm = is_scalar($_POST['password_confirm'] ?? null) ? (string)$_POST['password_confirm'] : '';

    try {
        verify_csrf(is_scalar($_POST['csrf'] ?? null) ? (string)$_POST['csrf'] : '');

        if ($form['full_name'] === '') {
            $errors['full_name'] = 'Your name is required.';
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{4,32}$/', $form['username'])) {
            $errors['username'] = 'Use 4-32 letters, numbers, dots, dashes or underscores.';
        }
        if ($form['email'] !== '' && filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Use at least 8 characters.';
        } elseif ($password !== $confirm) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        }

        $db = getDB();

        if (!$errors) {
            $taken = $db->prepare('SELECT 1 FROM admins WHERE username = :u LIMIT 1');
            $taken->execute([':u' => $form['username']]);
            if ($taken->fetchColumn()) {
                $errors['username'] = 'That username is already taken.';
            }
        }

        if (!$errors) {
            $insert = $db->prepare("
                INSERT INTO admins (username, password, full_name, email, phone, role, is_active, landlord_id, created_at)
                VALUES (:username, :password, :full_name, :email, :phone, 'landlord', 1, NULL, CURRENT_TIMESTAMP)
            ");
            $insert->execute([
                ':username' => $form['username'],
                ':password' => password_hash($password, PASSWORD_DEFAULT),
                ':full_name' => $form['full_name'],
                ':email' => $form['email'],
                ':phone' => $form['phone'],
            ]);

            $adminId = (int)$db->lastInsertId();

            $_SESSION['admin'] = ['id' => $adminId, 'name' => $form['full_name'], 'role' => 'landlord'];
            $_SESSION['admin_id'] = $adminId;
            $_SESSION['admin_role'] = 'landlord';
            $_SESSION['admin_name'] = $form['full_name'];

            logAudit($adminId, 'STAFF_CREATED', 'Landlord signed up: ' . $form['username']);

            redirect('onboarding.php');
        }
    } catch (Throwable $e) {
        $errors['form'] = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create your account · Urbahan</title>
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<main class="mx-auto grid min-h-screen max-w-5xl items-center gap-10 px-4 py-10 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:px-8">
  <section class="hidden lg:block">
    <a href="index.php" class="inline-flex items-center gap-3">
      <img class="w-10" src="/tenant-system/public/assets/urbahan-logo.png" alt="">
      <span class="font-extrabold">Urbahan</span>
    </a>
    <h1 class="mt-10 font-alt text-3xl font-bold leading-tight">
      One property to start.<br>Every month of rent tracked from then on.
    </h1>
    <ol class="mt-8 space-y-4 text-sm text-slate-600">
      <li class="flex gap-3"><span class="font-alt text-lg font-bold text-slate-900">1</span> Create your account.</li>
      <li class="flex gap-3"><span class="font-alt text-lg font-bold text-slate-900">2</span> Add your first property and its rooms.</li>
      <li class="flex gap-3"><span class="font-alt text-lg font-bold text-slate-900">3</span> Place tenants and record rent.</li>
    </ol>
  </section>

  <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
    <h2 class="font-alt text-xl font-bold">Create your account</h2>
    <p class="mt-1 text-sm text-slate-600">Already have one? <a class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4" href="login.php">Sign in</a></p>

    <?php if (isset($errors['form'])): ?>
      <p class="mt-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert"><?= e($errors['form']) ?></p>
    <?php endif; ?>

    <form method="post" class="mt-5 space-y-3">
      <?= csrf_field() ?>
      <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">Your name
        <input name="full_name" required value="<?= e($form['full_name']) ?>" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
        <?php if (isset($errors['full_name'])): ?><span class="mt-1 block text-xs font-medium text-red-700"><?= e($errors['full_name']) ?></span><?php endif; ?>
      </label>
      <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">Username
        <input name="username" required value="<?= e($form['username']) ?>" autocomplete="username" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
        <?php if (isset($errors['username'])): ?><span class="mt-1 block text-xs font-medium text-red-700"><?= e($errors['username']) ?></span><?php endif; ?>
      </label>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">Email <span class="font-normal normal-case text-slate-500">(optional)</span>
          <input name="email" type="email" value="<?= e($form['email']) ?>" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
        </label>
        <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">Phone <span class="font-normal normal-case text-slate-500">(optional)</span>
          <input name="phone" type="tel" value="<?= e($form['phone']) ?>" placeholder="+256 7XX XXX XXX" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
        </label>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">Password
          <input name="password" type="password" required minlength="8" autocomplete="new-password" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
          <?php if (isset($errors['password'])): ?><span class="mt-1 block text-xs font-medium text-red-700"><?= e($errors['password']) ?></span><?php endif; ?>
        </label>
        <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">Repeat password
          <input name="password_confirm" type="password" required minlength="8" autocomplete="new-password" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
          <?php if (isset($errors['password_confirm'])): ?><span class="mt-1 block text-xs font-medium text-red-700"><?= e($errors['password_confirm']) ?></span><?php endif; ?>
        </label>
      </div>
      <button type="submit" class="mt-2 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800">
        Create account and continue
      </button>
    </form>
  </section>
</main>
</body>
</html>
