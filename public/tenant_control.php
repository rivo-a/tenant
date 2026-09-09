<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/TenantService.php';

require_login();
requireCaretaker();

$pdo = getDB();
$admin_id = current_admin_id();
$tenantService = new TenantService($pdo);

$form = [
    'full_name'    => '',
    'phone'        => '',
    'email'        => '',
    'move_in_date' => date('Y-m-d'),
    'room_id'      => 0,
];

$success = (string)($_SESSION['success'] ?? '');
unset($_SESSION['success']);

/*
|--------------------------------------------------------------------------
| One-time temporary password
|--------------------------------------------------------------------------
| The temporary password is displayed only once after onboarding and then
| immediately removed from the session.
*/
$tempPassword = $_SESSION['temp_password'] ?? null;
$newTenantName = $_SESSION['new_tenant_name'] ?? '';
$newTenantPhone = $_SESSION['new_tenant_phone'] ?? '';

unset(
    $_SESSION['temp_password'],
    $_SESSION['new_tenant_name'],
    $_SESSION['new_tenant_phone']
);

$error = '';
$fieldErrors = [];

$postString = static function (string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
};

/*
|--------------------------------------------------------------------------
| Handle onboarding
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrfValue = $_POST['csrf'] ?? '';
        verify_csrf(is_string($csrfValue) ? $csrfValue : '');

        $form['full_name'] = $postString('full_name');
        $form['phone'] = $postString('phone');
        $form['email'] = $postString('email');
        $form['move_in_date'] = $postString(
            'move_in_date',
            date('Y-m-d')
        );

        $roomValue = $_POST['room_id'] ?? 0;
        $roomId = is_scalar($roomValue)
            ? filter_var($roomValue, FILTER_VALIDATE_INT)
            : false;

        $form['room_id'] = $roomId === false ? 0 : (int)$roomId;

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($form['full_name'] === '') {
            $fieldErrors['full_name'] = 'Tenant name is required.';
        }

        if ($form['phone'] === '') {
            $fieldErrors['phone'] =
                'Phone number is required for portal access.';
        } elseif (!preg_match('/^[0-9\+\-\s]{7,15}$/', $form['phone'])) {
            $fieldErrors['phone'] =
                'Please enter a valid phone number.';
        }

        if ($form['room_id'] <= 0) {
            $fieldErrors['room_id'] = 'Please select a room.';
        }

        if (
            $form['email'] !== '' &&
            filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            $fieldErrors['email'] =
                'Please enter a valid email address.';
        }

        $moveInDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $form['move_in_date']
        );

        $dateErrors = DateTimeImmutable::getLastErrors();

        $hasDateErrors =
            $dateErrors !== false &&
            (
                $dateErrors['warning_count'] > 0 ||
                $dateErrors['error_count'] > 0
            );

        if (
            $moveInDate === false ||
            $hasDateErrors ||
            $moveInDate->format('Y-m-d') !== $form['move_in_date']
        ) {
            $fieldErrors['move_in_date'] =
                'Please enter a valid move-in date.';
        }

        if ($fieldErrors) {
            throw new RuntimeException(
                'Please correct the highlighted fields.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Create tenant
        |--------------------------------------------------------------------------
        */

        $tenantResult = $tenantService->addTenant([
            'room_id'      => $form['room_id'],
            'full_name'    => $form['full_name'],
            'phone'        => $form['phone'],
            'email'        => $form['email'],
            'move_in_date' => $form['move_in_date'],
        ], $admin_id);

        /*
        |--------------------------------------------------------------------------
        | Store temporary credential for one-time display
        |--------------------------------------------------------------------------
        */

        $_SESSION['temp_password'] = $tenantResult['temp_password'];
        $_SESSION['new_tenant_name'] = $form['full_name'];
        $_SESSION['new_tenant_phone'] = $form['phone'];

        /*
        |--------------------------------------------------------------------------
        | Audit
        |--------------------------------------------------------------------------
        */

        if (function_exists('logAudit')) {
            try {
                logAudit(
                    $admin_id,
                    'TENANT_ONBOARDED',
                    "Tenant #{$tenantResult['id']} ({$form['full_name']}) onboarded with portal access."
                );
            } catch (Throwable $ignored) {
                // Audit failure must not break successful onboarding.
            }
        }

        $_SESSION['success'] = 'Tenant onboarded successfully.';

        redirect('tenant_control.php');

    } catch (Throwable $e) {

        $error = $e->getMessage();

        if (!$fieldErrors) {

            $fieldByMessage = [
                'Tenant name is required.' =>
                    'full_name',

                'Phone number is required.' =>
                    'phone',

                'Room ID is required.' =>
                    'room_id',

                'Invalid tenant email address.' =>
                    'email',

                'Invalid move-in date.' =>
                    'move_in_date',

                'Selected room is not available.' =>
                    'room_id',
            ];

            if (isset($fieldByMessage[$error])) {
                $fieldErrors[$fieldByMessage[$error]] = $error;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Available rooms
|--------------------------------------------------------------------------
*/

$rooms = $tenantService->getFreeRooms($admin_id);

$roomDescribedBy = [];

if (isset($fieldErrors['room_id'])) {
    $roomDescribedBy[] = 'room_id_error';
}

if (!$rooms) {
    $roomDescribedBy[] = 'room_availability_note';
}

?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Onboard Tenant</title>

    <link
        rel="stylesheet"
        href="assets/css/tailwind.css"
    >
</head>

<body class="min-h-screen bg-slate-50 text-slate-900">

<?php

$active = 'control';

require __DIR__ . '/partials/navbar.php';

?>

<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

    <!-- ================================================================
         PAGE HEADER
         ================================================================ -->

    <header class="mb-7 overflow-hidden rounded-3xl border border-slate-200 bg-gradient-to-br from-slate-950 via-blue-950 to-indigo-950 text-white shadow-lg">

        <div class="relative px-5 py-6 sm:px-7 sm:py-7">

            <!-- Decorative glow -->

            <div
                class="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-blue-500/10 blur-3xl"
            ></div>

            <div
                class="pointer-events-none absolute -bottom-20 left-1/3 h-48 w-48 rounded-full bg-indigo-500/10 blur-3xl"
            ></div>

            <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">

                <div>

                    <div class="mb-3 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-medium text-blue-100 backdrop-blur">

                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500/20 text-emerald-300">

                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-3.5 w-3.5"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M12 3l7 4v5c0 4.97-3.05 8.86-7 10-3.95-1.14-7-5.03-7-10V7l7-4z"
                                />
                            </svg>

                        </span>

                        Admin-controlled onboarding

                    </div>

                    <h1 class="font-alt text-2xl font-bold tracking-tight sm:text-3xl">
                        Add New Tenant
                    </h1>

                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                        Create a tenancy, assign an available room, and securely provision portal access for the tenant.
                    </p>

                </div>

                <div class="hidden shrink-0 sm:block">

                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl border border-white/10 bg-white/5 backdrop-blur">

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-7 w-7 text-blue-200"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"
                            />

                            <circle
                                cx="9"
                                cy="7"
                                r="4"
                            />

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M19 8v6M22 11h-6"
                            />

                        </svg>

                    </div>

                </div>

            </div>

        </div>

    </header>


    <!-- ================================================================
         ONE-TIME CREDENTIAL CARD
         ================================================================ -->

    <?php if ($tempPassword): ?>

        <section
            class="mb-6 overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-sm"
            aria-labelledby="credential-title"
        >

            <div class="border-b border-emerald-100 bg-emerald-50 px-5 py-4 sm:px-6">

                <div class="flex items-start gap-3">

                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M5 13l4 4L19 7"
                            />

                        </svg>

                    </div>

                    <div>

                        <h2
                            id="credential-title"
                            class="text-base font-bold text-emerald-950"
                        >
                            Tenant portal created successfully
                        </h2>

                        <p class="mt-1 text-sm text-emerald-800">
                            Portal access has been provisioned for
                            <strong><?= e($newTenantName) ?></strong>.
                        </p>

                    </div>

                </div>

            </div>

            <div class="p-5 sm:p-6">

                <div class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-center">

                    <div>

                        <div class="grid gap-3 sm:grid-cols-2">

                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">

                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                                    Tenant phone
                                </p>

                                <p class="mt-1 font-semibold text-slate-900">
                                    <?= e($newTenantPhone) ?>
                                </p>

                            </div>

                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">

                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                                    Login method
                                </p>

                                <p class="mt-1 font-semibold text-slate-900">
                                    Phone + password
                                </p>

                            </div>

                        </div>

                        <div class="mt-5">

                            <div class="mb-2 flex items-center justify-between gap-3">

                                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">
                                    Temporary password
                                </p>

                                <span class="inline-flex items-center gap-1.5 text-xs font-medium text-amber-700">

                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-3.5 w-3.5"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M12 9v4m0 4h.01M10.29 3.86l-8.18 14A2 2 0 003.84 21h16.32a2 2 0 001.73-3.14l-8.18-14a2 2 0 00-3.42 0z"
                                        />

                                    </svg>

                                    Shown once

                                </span>

                            </div>

                            <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-950 p-3 sm:flex-row sm:items-center">

                                <code
                                    class="min-w-0 flex-1 break-all px-2 py-2 text-lg font-bold tracking-widest text-white sm:text-xl"
                                ><?= e($tempPassword) ?></code>

                                <button
                                    type="button"
                                    id="copy-temp-password"
                                    class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-900 transition hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white active:scale-[0.98]"
                                >

                                    <svg
                                        id="copy-icon"
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-4 w-4"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <rect
                                            width="13"
                                            height="13"
                                            x="9"
                                            y="9"
                                            rx="2"
                                        />

                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"
                                        />

                                    </svg>

                                    <span id="copy-label">
                                        Copy password
                                    </span>

                                </button>

                            </div>

                        </div>

                    </div>

                    <div class="rounded-2xl border border-blue-100 bg-blue-50 p-4 lg:max-w-xs">

                        <div class="flex gap-3">

                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="mt-0.5 h-5 w-5 shrink-0 text-blue-600"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M12 11c0 3-1.5 5-4 6"
                                />

                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M12 11c0-3 1.5-5 4-6"
                                />

                                <circle
                                    cx="12"
                                    cy="12"
                                    r="9"
                                />

                            </svg>

                            <div>

                                <p class="text-sm font-bold text-blue-950">
                                    Important
                                </p>

                                <p class="mt-1 text-xs leading-5 text-blue-800">
                                    Share these credentials with the tenant through a trusted channel. The tenant will be required to create a new password during their first login.
                                </p>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </section>

    <?php elseif ($success): ?>

        <div
            class="mb-6 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-900 shadow-sm"
            role="status"
        >

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M5 13l4 4L19 7"
                />

            </svg>

            <span class="font-medium">
                <?= e($success) ?>
            </span>

        </div>

    <?php endif; ?>


    <!-- ================================================================
         ERROR SUMMARY
         ================================================================ -->

    <?php if ($error): ?>

        <div
            id="tenantFormSummary"
            class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900 shadow-sm"
            role="alert"
        >

            <div class="flex items-start gap-3">

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="mt-0.5 h-5 w-5 shrink-0 text-red-600"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M12 9v4m0 4h.01M10.29 3.86l-8.18 14A2 2 0 003.84 21h16.32a2 2 0 001.73-3.14l-8.18-14a2 2 0 00-3.42 0z"
                    />

                </svg>

                <div>

                    <p class="font-bold">
                        <?= e($error) ?>
                    </p>

                    <?php if ($fieldErrors): ?>

                        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">

                            <?php foreach ($fieldErrors as $fieldError): ?>

                                <li><?= e($fieldError) ?></li>

                            <?php endforeach; ?>

                        </ul>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    <?php endif; ?>


    <!-- ================================================================
         MAIN ONBOARDING FORM
         ================================================================ -->

    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

        <div class="border-b border-slate-100 bg-slate-50/70 px-5 py-5 sm:px-7">

            <div class="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">

                <div>

                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600">
                        New tenancy
                    </p>

                    <h2 class="mt-1 font-alt text-xl font-bold text-slate-950">
                        Tenant information
                    </h2>

                    <p class="mt-1 text-sm text-slate-600">
                        Enter the tenant's details and assign their room.
                    </p>

                </div>

                <div class="flex items-center gap-2 text-xs text-slate-500">

                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-blue-100 font-bold text-blue-700">
                        01
                    </span>

                    <span class="hidden sm:block">
                        Account setup
                    </span>

                </div>

            </div>

        </div>


        <form
            id="tenantOnboardingForm"
            method="post"
            action="tenant_control.php"
            <?= $error ? 'aria-describedby="tenantFormSummary"' : '' ?>
        >

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf_token()) ?>"
            >


            <!-- =========================================================
                 TENANT INFORMATION
                 ========================================================= -->

            <div class="px-5 py-6 sm:px-7">

                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">

                    <!-- Full name -->

                    <div>

                        <label
                            for="full_name"
                            class="flex items-center justify-between gap-2 text-xs font-bold uppercase tracking-wide text-slate-600"
                        >

                            <span>Full name</span>

                            <span class="text-[10px] font-semibold normal-case tracking-normal text-red-600">
                                Required
                            </span>

                        </label>

                        <div class="relative">

                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"
                                />

                                <circle
                                    cx="9"
                                    cy="7"
                                    r="4"
                                />

                            </svg>

                            <input
                                id="full_name"
                                name="full_name"
                                type="text"
                                value="<?= e($form['full_name']) ?>"
                                autocomplete="name"
                                placeholder="e.g. John Doe"
                                required
                                class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white py-3 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 hover:border-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10"
                                <?= isset($fieldErrors['full_name'])
                                    ? 'aria-invalid="true" aria-describedby="full_name_error"'
                                    : '' ?>
                            >

                        </div>

                        <?php if (isset($fieldErrors['full_name'])): ?>

                            <p
                                id="full_name_error"
                                class="mt-2 text-xs font-medium text-red-700"
                            >
                                <?= e($fieldErrors['full_name']) ?>
                            </p>

                        <?php endif; ?>

                    </div>


                    <!-- Phone -->

                    <div>

                        <label
                            for="phone"
                            class="flex items-center justify-between gap-2 text-xs font-bold uppercase tracking-wide text-slate-600"
                        >

                            <span>Phone number</span>

                            <span class="text-[10px] font-semibold normal-case tracking-normal text-blue-600">
                                Portal login
                            </span>

                        </label>

                        <div class="relative">

                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M22 16.92v3a2 2 0 01-2.18 2
                                       19.79 19.79 0 01-8.63-3.07
                                       19.5 19.5 0 01-6-6
                                       A19.79 19.79 0 012.12 4.18
                                       2 2 0 014.11 2h3a2 2 0 012 1.72
                                       12.84 12.84 0 00.7 2.81
                                       2 2 0 01-.45 2.11L8.09 9.91
                                       a16 16 0 006 6l1.27-1.27
                                       a2 2 0 012.11-.45
                                       12.84 12.84 0 002.81.7
                                       A2 2 0 0122 16.92z"
                                />

                            </svg>

                            <input
                                id="phone"
                                name="phone"
                                type="tel"
                                value="<?= e($form['phone']) ?>"
                                autocomplete="tel"
                                placeholder="+256 7XX XXX XXX"
                                required
                                class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white py-3 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 hover:border-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10"
                                <?= isset($fieldErrors['phone'])
                                    ? 'aria-invalid="true" aria-describedby="phone_error"'
                                    : '' ?>
                            >

                        </div>

                        <p class="mt-2 text-xs text-slate-500">
                            This number will be used to sign in to the tenant portal.
                        </p>

                        <?php if (isset($fieldErrors['phone'])): ?>

                            <p
                                id="phone_error"
                                class="mt-2 text-xs font-medium text-red-700"
                            >
                                <?= e($fieldErrors['phone']) ?>
                            </p>

                        <?php endif; ?>

                    </div>


                    <!-- Email -->

                    <div>

                        <label
                            for="email"
                            class="flex items-center justify-between gap-2 text-xs font-bold uppercase tracking-wide text-slate-600"
                        >

                            <span>Email address</span>

                            <span class="text-[10px] font-semibold normal-case tracking-normal text-slate-400">
                                Optional
                            </span>

                        </label>

                        <div class="relative">

                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <rect
                                    width="20"
                                    height="16"
                                    x="2"
                                    y="4"
                                    rx="2"
                                />

                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M22 7l-10 5L2 7"
                                />

                            </svg>

                            <input
                                id="email"
                                name="email"
                                type="email"
                                value="<?= e($form['email']) ?>"
                                autocomplete="email"
                                placeholder="tenant@example.com"
                                class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white py-3 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 hover:border-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10"
                                <?= isset($fieldErrors['email'])
                                    ? 'aria-invalid="true" aria-describedby="email_error"'
                                    : '' ?>
                            >

                        </div>

                        <?php if (isset($fieldErrors['email'])): ?>

                            <p
                                id="email_error"
                                class="mt-2 text-xs font-medium text-red-700"
                            >
                                <?= e($fieldErrors['email']) ?>
                            </p>

                        <?php endif; ?>

                    </div>


                    <!-- Move in date -->

                    <div>

                        <label
                            for="move_in_date"
                            class="flex items-center justify-between gap-2 text-xs font-bold uppercase tracking-wide text-slate-600"
                        >

                            <span>Move-in date</span>

                            <span class="text-[10px] font-semibold normal-case tracking-normal text-red-600">
                                Required
                            </span>

                        </label>

                        <input
                            id="move_in_date"
                            type="date"
                            name="move_in_date"
                            value="<?= e($form['move_in_date']) ?>"
                            required
                            class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition hover:border-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10"
                            <?= isset($fieldErrors['move_in_date'])
                                ? 'aria-invalid="true" aria-describedby="move_in_date_error"'
                                : '' ?>
                        >

                        <?php if (isset($fieldErrors['move_in_date'])): ?>

                            <p
                                id="move_in_date_error"
                                class="mt-2 text-xs font-medium text-red-700"
                            >
                                <?= e($fieldErrors['move_in_date']) ?>
                            </p>

                        <?php endif; ?>

                    </div>

                </div>

            </div>


            <!-- =========================================================
                 TENANCY DETAILS
                 ========================================================= -->

            <div class="border-t border-slate-100 bg-slate-50/50 px-5 py-6 sm:px-7">

                <div class="mb-5 flex items-center justify-between gap-4">

                    <div>

                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600">
                            Step 02
                        </p>

                        <h3 class="mt-1 font-alt text-lg font-bold text-slate-950">
                            Tenancy details
                        </h3>

                        <p class="mt-1 text-sm text-slate-600">
                            Select the room that will be assigned to this tenant.
                        </p>

                    </div>

                    <div class="hidden h-9 w-9 items-center justify-center rounded-xl bg-blue-100 text-blue-700 sm:flex">

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"
                            />

                        </svg>

                    </div>

                </div>


                <!-- Room -->

                <div>

                    <label
                        for="room_id"
                        class="flex items-center justify-between gap-2 text-xs font-bold uppercase tracking-wide text-slate-600"
                    >

                        <span>Available room</span>

                        <span class="text-[10px] font-semibold normal-case tracking-normal text-red-600">
                            Required
                        </span>

                    </label>

                    <?php if (!$rooms): ?>

                        <div
                            id="room_availability_note"
                            class="mt-2 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                            role="status"
                        >

                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="mt-0.5 h-5 w-5 shrink-0 text-amber-600"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M12 9v4m0 4h.01M10.29 3.86l-8.18 14A2 2 0 003.84 21h16.32a2 2 0 001.73-3.14l-8.18-14a2 2 0 00-3.42 0z"
                                />

                            </svg>

                            <div>

                                <p class="font-semibold">
                                    No rooms currently available
                                </p>

                                <p class="mt-0.5 text-xs text-amber-800">
                                    Create or free a room before onboarding a new tenant.
                                </p>

                            </div>

                        </div>

                    <?php endif; ?>


                    <select
                        id="room_id"
                        name="room_id"
                        required
                        <?= !$rooms ? 'disabled' : '' ?>
                        <?= $roomDescribedBy
                            ? 'aria-describedby="' . e(implode(' ', $roomDescribedBy)) . '"'
                            : '' ?>
                        class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm text-slate-900 outline-none transition hover:border-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500"
                        <?= isset($fieldErrors['room_id'])
                            ? 'aria-invalid="true"'
                            : '' ?>
                    >

                        <?php if (!$rooms): ?>

                            <option value="">
                                No available rooms
                            </option>

                        <?php else: ?>

                            <option value="">
                                Select an available room
                            </option>

                            <?php foreach ($rooms as $room): ?>

                                <?php

                                $roomRent = (float)($room['rent'] ?? 0);

                                $rentLabel = $roomRent > 0
                                    ? ' — UGX ' . number_format($roomRent, 0)
                                    : '';

                                ?>

                                <option
                                    value="<?= (int)$room['id'] ?>"
                                    <?= (int)$form['room_id'] === (int)$room['id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($room['room_number']) ?>
                                    (<?= e($room['room_type'] ?? 'N/A') ?><?= e($rentLabel) ?>)
                                </option>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </select>


                    <?php if (isset($fieldErrors['room_id'])): ?>

                        <p
                            id="room_id_error"
                            class="mt-2 text-xs font-medium text-red-700"
                        >
                            <?= e($fieldErrors['room_id']) ?>
                        </p>

                    <?php endif; ?>

                </div>

            </div>


            <!-- =========================================================
                 PORTAL ACCESS PREVIEW
                 ========================================================= -->

            <div class="border-t border-slate-100 px-5 py-6 sm:px-7">

                <div class="rounded-2xl border border-blue-100 bg-blue-50/60 p-5">

                    <div class="flex items-start gap-3">

                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-700">

                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <rect
                                    width="18"
                                    height="11"
                                    x="3"
                                    y="11"
                                    rx="2"
                                />

                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M7 11V7a5 5 0 0110 0v4"
                                />

                            </svg>

                        </div>

                        <div class="min-w-0">

                            <h3 class="text-sm font-bold text-blue-950">
                                Tenant Portal Access
                            </h3>

                            <p class="mt-1 text-xs leading-5 text-blue-800">
                                A secure portal account will be created automatically. The tenant will sign in using their registered phone number and a temporary password.
                            </p>

                        </div>

                    </div>


                    <div class="mt-4 grid gap-3 sm:grid-cols-3">

                        <div class="rounded-xl border border-blue-100 bg-white/70 px-4 py-3">

                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                Login
                            </p>

                            <p class="mt-1 text-sm font-semibold text-slate-900">
                                Phone number
                            </p>

                        </div>

                        <div class="rounded-xl border border-blue-100 bg-white/70 px-4 py-3">

                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                First access
                            </p>

                            <p class="mt-1 text-sm font-semibold text-slate-900">
                                Password change required
                            </p>

                        </div>

                        <div class="rounded-xl border border-blue-100 bg-white/70 px-4 py-3">

                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                Access
                            </p>

                            <p class="mt-1 text-sm font-semibold text-slate-900">
                                Private tenant account
                            </p>

                        </div>

                    </div>

                </div>

            </div>


            <!-- =========================================================
                 ACTION AREA
                 ========================================================= -->

            <div class="border-t border-slate-100 bg-white px-5 py-5 sm:px-7">

                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                    <div class="flex items-start gap-3">

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 3l7 4v5c0 4.97-3.05 8.86-7 10-3.95-1.14-7-5.03-7-10V7l7-4z"
                            />

                        </svg>

                        <p class="max-w-xl text-xs leading-5 text-slate-500">
                            Creating this tenant will mark the selected room as occupied and generate one-time portal credentials.
                        </p>

                    </div>


                    <button
                        id="tenantOnboardingSubmit"
                        type="submit"
                        name="onboard_tenant"
                        value="1"
                        <?= !$rooms ? 'disabled' : '' ?>
                        class="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-950 active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                    >

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 5v14M5 12h14"
                            />

                        </svg>

                        <span>
                            Create tenant &amp; generate credentials
                        </span>

                    </button>

                </div>

            </div>

        </form>

    </section>

</main>


<!-- ================================================================
     COPY PASSWORD SCRIPT
     ================================================================ -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    const copyButton = document.getElementById('copy-temp-password');
    const copyLabel = document.getElementById('copy-label');
    const copyIcon = document.getElementById('copy-icon');

    if (!copyButton || !copyLabel || !copyIcon) {
        return;
    }

    const password = <?= json_encode(
        $tempPassword,
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_AMP |
        JSON_HEX_QUOT
    ) ?>;

    copyButton.addEventListener('click', async function () {

        if (!password) {
            return;
        }

        try {

            await navigator.clipboard.writeText(password);

            copyLabel.textContent = 'Copied!';

            copyIcon.innerHTML = `
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M5 13l4 4L19 7"
                />
            `;

            copyButton.classList.remove(
                'bg-white',
                'text-slate-900'
            );

            copyButton.classList.add(
                'bg-emerald-500',
                'text-white'
            );

            setTimeout(function () {

                copyLabel.textContent = 'Copy password';

                copyIcon.innerHTML = `
                    <rect
                        width="13"
                        height="13"
                        x="9"
                        y="9"
                        rx="2"
                    />

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"
                    />
                `;

                copyButton.classList.remove(
                    'bg-emerald-500',
                    'text-white'
                );

                copyButton.classList.add(
                    'bg-white',
                    'text-slate-900'
                );

            }, 2000);

        } catch (error) {

            copyLabel.textContent = 'Copy failed';

            setTimeout(function () {
                copyLabel.textContent = 'Copy password';
            }, 2000);

        }

    });

});
</script>

</body>
</html>

