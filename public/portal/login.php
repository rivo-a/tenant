<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isSecure ? '1' : '0');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['tenant_id']) && ($_SESSION['tenant_role'] ?? '') === 'tenant') {
    header('Location: /tenant-system/public/portal/dashboard.php');
    exit;
}

$error = $_GET['error'] ?? '';

$errorMessages = [
    'invalid'          => 'Invalid phone number or password.',
    'locked'           => 'Too many failed attempts. Your account is temporarily locked. Please try again later.',
    'disabled'         => 'Your account has been disabled. Please contact management.',
    'revoked'          => 'Your account access has been revoked. Please contact management.',
    'session_expired'  => 'Your session has expired. Please log in again.',
];

$displayError = $errorMessages[$error] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tenant Portal — Login</title>
    <link rel="stylesheet" href="/tenant-system/public/assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 flex items-center justify-center px-4">

    <div class="w-full max-w-md">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-900 text-white">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1h-2z" />
                </svg>
            </div>
            <h1 class="text-2xl font-bold tracking-tight font-alt">Tenant Portal</h1>
            <p class="mt-1 text-sm text-slate-500">Sign in with your registered phone number</p>
        </div>

        <!-- Error Alert -->
        <?php if ($displayError): ?>
            <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                <?= htmlspecialchars($displayError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <form method="post" action="/tenant-system/public/portal/login_process.php" class="space-y-5">

                <div>
                    <label for="phone" class="block text-xs font-semibold text-slate-600">
                        Phone Number
                    </label>
                    <input
                        id="phone"
                        name="phone"
                        type="tel"
                        required
                        autocomplete="tel"
                        placeholder="e.g., 0771234567"
                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200"
                    >
                </div>

                <div>
                    <label for="password" class="block text-xs font-semibold text-slate-600">
                        Password
                    </label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200"
                    >
                </div>

                <button
                    type="submit"
                    class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
                >
                    Sign In
                </button>
            </form>
        </section>

        <p class="mt-6 text-center text-xs text-slate-400">
            Forgot your password? Contact your property manager for a reset.
        </p>
    </div>

</body>
</html>