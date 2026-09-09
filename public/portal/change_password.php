<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/tenant_auth.php';

requireTenantAuth();

$pdo = getDB();
$tenantId = getLoggedInTenantId();

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword  = $_POST['current_password'] ?? '';
    $newPassword      = $_POST['new_password'] ?? '';
    $confirmPassword  = $_POST['confirm_password'] ?? '';

    // Fetch current hash
    $stmt = $pdo->prepare("SELECT password_hash FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        $error = 'Account not found.';
    } elseif (!password_verify($currentPassword, $tenant['password_hash'] ?? '')) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match.';
    } elseif ($currentPassword === $newPassword) {
        $error = 'New password must be different from your current password.';
    } else {
        // Hash and save the new password
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("
            UPDATE tenants 
            SET password_hash = ?, must_change_password = 0, account_status = 'active' 
            WHERE id = ?
        ")->execute([$newHash, $tenantId]);

        // Audit log
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $pdo->prepare("
                INSERT INTO audit_logs (actor_type, actor_id, action, target_type, target_id, details, ip_address)
                VALUES ('tenant', ?, 'PASSWORD_CHANGED', 'tenant', ?, 'First login password reset', ?)
            ")->execute([$tenantId, $tenantId, $ip]);
        } catch (Throwable $ignored) {}

        $success = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password — Tenant Portal</title>
    <link rel="stylesheet" href="/tenant-system/public/assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 flex items-center justify-center px-4">

    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-500 text-white">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <h1 class="text-2xl font-bold tracking-tight font-alt">Set Your Password</h1>
            <p class="mt-1 text-sm text-slate-500">
                For your security, you must change your temporary password before continuing.
            </p>
        </div>

        <?php if ($success): ?>
            <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                Password changed successfully! Redirecting to your dashboard...
            </div>
            <script>setTimeout(() => window.location.href = '/tenant-system/public/portal/dashboard.php', 2000);</script>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <form method="post" class="space-y-5">

                <div>
                    <label for="current_password" class="block text-xs font-semibold text-slate-600">
                        Current (Temporary) Password
                    </label>
                    <input id="current_password" name="current_password" type="password" required
                        autocomplete="current-password"
                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200">
                </div>

                <div>
                    <label for="new_password" class="block text-xs font-semibold text-slate-600">
                        New Password
                    </label>
                    <input id="new_password" name="new_password" type="password" required minlength="8"
                        autocomplete="new-password"
                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200">
                    <p class="mt-1 text-xs text-slate-400">Must be at least 8 characters.</p>
                </div>

                <div>
                    <label for="confirm_password" class="block text-xs font-semibold text-slate-600">
                        Confirm New Password
                    </label>
                    <input id="confirm_password" name="confirm_password" type="password" required minlength="8"
                        autocomplete="new-password"
                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200">
                </div>

                <button type="submit"
                    class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                    Update Password & Continue
                </button>
            </form>
        </section>
        <?php endif; ?>
    </div>

</body>
</html>