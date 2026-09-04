<?php

declare(strict_types=1);

/**
 * =========================================================
 * TENANT RENT SMS REMINDER
 * =========================================================
 * File: public/remind.php
 * Provider: SMS.UG
 * =========================================================
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/core/bootstrap.php';

use Dotenv\Dotenv;

/* =========================================================
   1. LOAD .ENV
   ========================================================= */

$projectRoot = dirname(__DIR__);

try {
    $dotenv = Dotenv::createImmutable($projectRoot);
    $dotenv->safeLoad();
} catch (Throwable $e) {
    error_log('Dotenv loading failed: ' . $e->getMessage());
}

/* =========================================================
   2. SMS.UG CONFIG
   ========================================================= */

$smsApiKey = trim(
    (string)($_ENV['SMS_UG_API_KEY'] ?? '')
);

$smsApiUrl = 'https://sms.ug/api/';

$smsTitle = 'Rent Reminder';

/* =========================================================
   3. AUTHENTICATION
   ========================================================= */

require_login();

$adminId = (int)(
    $_SESSION['admin_id'] ?? 0
);

if ($adminId <= 0) {
    http_response_code(403);
    exit('Unauthorized.');
}

/* =========================================================
   4. DATABASE
   ========================================================= */

$db = $pdo ?? null;

if (!$db instanceof PDO) {
    http_response_code(500);
    exit('Database connection is not available.');
}

/* =========================================================
   5. HELPERS
   ========================================================= */

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

function money(float $amount): string
{
    return 'UGX ' . number_format(
        max(0, $amount),
        0,
        '.',
        ','
    );
}

/**
 * Normalize Ugandan phone numbers to:
 *
 * 2567XXXXXXXX
 */
function normalizeUgandaPhone(
    string $phone
): ?string {

    $phone = trim($phone);

    $phone = preg_replace(
        '/[\s\-\(\)\.]+/',
        '',
        $phone
    ) ?? '';

    if ($phone === '') {
        return null;
    }

    /* +2567XXXXXXXX */

    if (
        preg_match(
            '/^\+256(7\d{8})$/',
            $phone,
            $matches
        )
    ) {
        return '256' . $matches[1];
    }

    /* 2567XXXXXXXX */

    if (
        preg_match(
            '/^256(7\d{8})$/',
            $phone,
            $matches
        )
    ) {
        return '256' . $matches[1];
    }

    /* 07XXXXXXXX */

    if (
        preg_match(
            '/^0(7\d{8})$/',
            $phone,
            $matches
        )
    ) {
        return '256' . $matches[1];
    }

    /* 7XXXXXXXX */

    if (
        preg_match(
            '/^(7\d{8})$/',
            $phone,
            $matches
        )
    ) {
        return '256' . $matches[1];
    }

    return null;
}

function calculateDaysOverdue(
    ?string $rentDueDate
): int {

    if (!$rentDueDate) {
        return 0;
    }

    try {

        $due = new DateTime($rentDueDate);
        $today = new DateTime('today');

        if ($today <= $due) {
            return 0;
        }

        return (int)$due->diff($today)->days;

    } catch (Throwable $e) {

        return 0;
    }
}

/* =========================================================
   6. GET TENANT ID
   ========================================================= */

$tenantId = filter_input(
    INPUT_GET,
    'tenant_id',
    FILTER_VALIDATE_INT
);

if (!$tenantId) {

    $tenantId = filter_input(
        INPUT_POST,
        'tenant_id',
        FILTER_VALIDATE_INT
    );
}

$tenantId = (int)$tenantId;

if ($tenantId <= 0) {
    http_response_code(400);
    exit('Invalid tenant ID.');
}

/* =========================================================
   7. LOAD TENANT
   ========================================================= */

$tenantStmt = $db->prepare(
    "
    SELECT
        t.id AS tenant_id,
        t.full_name,
        t.phone,
        t.email,
        t.status AS tenant_status,
        t.move_in_date,
        t.rent_due_date,
        t.monthly_rent AS tenant_rent_override,

        r.id AS room_id,
        r.room_number,
        r.monthly_rent AS room_rent_override,

        rt.name AS room_type,
        rt.default_monthly_rent,

        COALESCE(
            r.monthly_rent,
            rt.default_monthly_rent,
            t.monthly_rent,
            0
        ) AS effective_rent

    FROM tenants t

    LEFT JOIN rooms r
        ON r.id = t.room_id

    LEFT JOIN room_types rt
        ON rt.id = r.room_type_id

    WHERE
        t.id = :tenant_id
        AND t.admin_id = :admin_id
        AND t.exit_date IS NULL

    LIMIT 1
    "
);

$tenantStmt->execute([
    ':tenant_id' => $tenantId,
    ':admin_id' => $adminId,
]);

$tenant = $tenantStmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$tenant) {

    http_response_code(404);

    exit(
        'Tenant not found or you do not have permission to access this tenant.'
    );
}

/* =========================================================
   8. TENANT DATA
   ========================================================= */

$tenantName = trim(
    (string)($tenant['full_name'] ?? '')
);

$tenantPhone = trim(
    (string)($tenant['phone'] ?? '')
);

$roomNumber = trim(
    (string)($tenant['room_number'] ?? '')
);

$roomType = trim(
    (string)($tenant['room_type'] ?? '')
);

$effectiveRent = (float)(
    $tenant['effective_rent'] ?? 0
);

$paymentMonth = date('Y-m');

/* =========================================================
   9. CALCULATE CURRENT MONTH DUE
   ========================================================= */

$scheduleStmt = $db->prepare(
    "
    SELECT
        COALESCE(SUM(amount_due), 0)
    FROM rent_schedule
    WHERE
        tenant_id = :tenant_id
        AND month = :payment_month
    "
);

$scheduleStmt->execute([
    ':tenant_id' => $tenantId,
    ':payment_month' => $paymentMonth,
]);

$scheduledDue = (float)(
    $scheduleStmt->fetchColumn()
);

$amountDue = $scheduledDue > 0
    ? $scheduledDue
    : $effectiveRent;

/* =========================================================
   10. CURRENT MONTH PAYMENTS
   ========================================================= */

$paymentStmt = $db->prepare(
    "
    SELECT
        COALESCE(SUM(amount), 0)
    FROM payments
    WHERE
        tenant_id = :tenant_id
        AND payment_month = :payment_month
        AND admin_id = :admin_id
    "
);

$paymentStmt->execute([
    ':tenant_id' => $tenantId,
    ':payment_month' => $paymentMonth,
    ':admin_id' => $adminId,
]);

$amountPaid = (float)(
    $paymentStmt->fetchColumn()
);

$outstandingBalance = max(
    0,
    $amountDue - $amountPaid
);

/* =========================================================
   11. PAYMENT STATUS
   ========================================================= */

$todayDay = (int)date('j');

if ($outstandingBalance <= 0) {

    $paymentStatus = 'Paid';

} elseif ($todayDay > 5) {

    $paymentStatus = 'Overdue';

} else {

    $paymentStatus = 'Due';
}

$daysOverdue = calculateDaysOverdue(
    $tenant['rent_due_date'] ?? null
);

/* =========================================================
   12. DEFAULT MESSAGE
   ========================================================= */

$defaultMessage = sprintf(
    'Hello %s, this is a reminder that your rent is overdue. Your balance is %s. Please make payment as soon as possible. Thank you.',
    $tenantName !== ''
        ? $tenantName
        : 'Tenant',
   
    money($outstandingBalance)
);

$defaultMessage = mb_substr(
    $defaultMessage,
    0,
    480
);

/* =========================================================
   13. FORM STATE
   ========================================================= */

$message = $defaultMessage;

$phoneForForm = $tenantPhone;

$successMessage = '';

$errorMessage = '';

$providerResponse = null;

/* =========================================================
   14. CSRF TOKEN
   ========================================================= */

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token'])
) {

    $_SESSION['csrf_token'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    $_SESSION['csrf_token'];

/* =========================================================
   15. HANDLE POST
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* =====================================================
       CSRF
       ===================================================== */

    $submittedCsrf = (string)(
        $_POST['csrf_token'] ?? ''
    );

    if (
        !hash_equals(
            (string)$_SESSION['csrf_token'],
            $submittedCsrf
        )
    ) {

        $errorMessage =
            'Security validation failed. Please refresh the page and try again.';
    }

    /* =====================================================
       FORM INPUT
       ===================================================== */

    $phoneForForm = trim(
        (string)(
            $_POST['phone']
            ?? $tenantPhone
        )
    );

    $message = trim(
        (string)(
            $_POST['message']
            ?? $defaultMessage
        )
    );

    /* =====================================================
       API KEY
       ===================================================== */

    if (
        $errorMessage === '' &&
        $smsApiKey === ''
    ) {

        $errorMessage =
            'SMS could not be sent because SMS.UG API access is not configured. Check SMS_UG_API_KEY in your .env file.';
    }

    /* =====================================================
       PHONE
       ===================================================== */

    $normalizedPhone =
        normalizeUgandaPhone(
            $phoneForForm
        );

    if (
        $errorMessage === '' &&
        $normalizedPhone === null
    ) {

        $errorMessage =
            'Please enter a valid Ugandan mobile number, for example 0704487563.';
    }

    /* =====================================================
       MESSAGE
       ===================================================== */

    if (
        $errorMessage === '' &&
        $message === ''
    ) {

        $errorMessage =
            'SMS message cannot be empty.';
    }

    if (
        $errorMessage === '' &&
        mb_strlen($message) > 480
    ) {

        $errorMessage =
            'SMS.UG allows a maximum of 480 characters.';
    }

    /* =====================================================
       RECHECK TENANT OWNERSHIP
       ===================================================== */

    if ($errorMessage === '') {

        $ownershipStmt = $db->prepare(
            "
            SELECT id
            FROM tenants
            WHERE
                id = :tenant_id
                AND admin_id = :admin_id
                AND exit_date IS NULL
            LIMIT 1
            "
        );

        $ownershipStmt->execute([
            ':tenant_id' => $tenantId,
            ':admin_id' => $adminId,
        ]);

        if (!$ownershipStmt->fetchColumn()) {

            $errorMessage =
                'You are not authorized to send a reminder to this tenant.';
        }
    }

    /* =====================================================
       RECHECK BALANCE
       ===================================================== */

    if ($errorMessage === '') {

        $verifyScheduleStmt = $db->prepare(
            "
            SELECT
                COALESCE(SUM(amount_due), 0)
            FROM rent_schedule
            WHERE
                tenant_id = :tenant_id
                AND month = :payment_month
            "
        );

        $verifyScheduleStmt->execute([
            ':tenant_id' => $tenantId,
            ':payment_month' => $paymentMonth,
        ]);

        $verifiedScheduledDue =
            (float)$verifyScheduleStmt->fetchColumn();

        if ($verifiedScheduledDue <= 0) {

            $verifiedScheduledDue =
                $effectiveRent;
        }

        $verifyPaymentStmt = $db->prepare(
            "
            SELECT
                COALESCE(SUM(amount), 0)
            FROM payments
            WHERE
                tenant_id = :tenant_id
                AND payment_month = :payment_month
                AND admin_id = :admin_id
            "
        );

        $verifyPaymentStmt->execute([
            ':tenant_id' => $tenantId,
            ':payment_month' => $paymentMonth,
            ':admin_id' => $adminId,
        ]);

        $verifiedAmountPaid =
            (float)$verifyPaymentStmt->fetchColumn();

        $verifiedBalance = max(
            0,
            $verifiedScheduledDue -
            $verifiedAmountPaid
        );

        $amountDue =
            $verifiedScheduledDue;

        $amountPaid =
            $verifiedAmountPaid;

        $outstandingBalance =
            $verifiedBalance;

        if ($verifiedBalance <= 0) {

            $errorMessage =
                'This tenant has already paid the current month\'s rent. The SMS was not sent.';
        }
    }

    /* =====================================================
       CREATE SMS LOG — PENDING
       ===================================================== */

    $smsLogId = null;

    if ($errorMessage === '') {

        try {

            $insertSmsLog = $db->prepare(
                "
                INSERT INTO sms_logs (
                    tenant_id,
                    admin_id,
                    phone,
                    message,
                    sms_type,
                    amount_due,
                    amount_paid,
                    outstanding_balance,
                    provider,
                    status,
                    created_at,
                    updated_at
                )
                VALUES (
                    :tenant_id,
                    :admin_id,
                    :phone,
                    :message,
                    :sms_type,
                    :amount_due,
                    :amount_paid,
                    :outstanding_balance,
                    :provider,
                    'PENDING',
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                "
            );

            $insertSmsLog->execute([
                ':tenant_id' =>
                    $tenantId,

                ':admin_id' =>
                    $adminId,

                ':phone' =>
                    $normalizedPhone,

                ':message' =>
                    $message,

                ':sms_type' =>
                    'RENT_REMINDER',

                ':amount_due' =>
                    $amountDue,

                ':amount_paid' =>
                    $amountPaid,

                ':outstanding_balance' =>
                    $outstandingBalance,

                ':provider' =>
                    'sms.ug',
            ]);

            $smsLogId =
                (int)$db->lastInsertId();

        } catch (Throwable $e) {

            error_log(
                'SMS log creation failed: ' .
                $e->getMessage()
            );

            $errorMessage =
                'The SMS could not be prepared for sending. Please try again.';
        }
    }

    /* =====================================================
       PREPARE SMS.UG PAYLOAD
       ===================================================== */

    if (
        $errorMessage === '' &&
        $smsLogId !== null
    ) {

        $payload = [
            'title' => $smsTitle,
            'message' => $message,
            'contacts' => [
                $normalizedPhone
            ],
        ];

        $jsonPayload = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($jsonPayload === false) {

            $errorMessage =
                'The SMS request could not be prepared.';

            try {

                $updateLog = $db->prepare(
                    "
                    UPDATE sms_logs
                    SET
                        status = 'FAILED',
                        failure_reason = :reason,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                    "
                );

                $updateLog->execute([
                    ':reason' =>
                        'JSON encoding failed',

                    ':id' =>
                        $smsLogId,
                ]);

            } catch (Throwable $e) {

                error_log(
                    'Failed to update SMS log: ' .
                    $e->getMessage()
                );
            }
        }

        /* =================================================
           SEND REQUEST
           ================================================= */

        if (
            $errorMessage === '' &&
            $jsonPayload !== false
        ) {

            $ch = curl_init(
                $smsApiUrl
            );

            curl_setopt_array(
                $ch,
                [
                    CURLOPT_POST => true,

                    CURLOPT_RETURNTRANSFER => true,

                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' .
                        $smsApiKey,

                        'Content-Type: application/json',

                        'Accept: application/json',
                    ],

                    CURLOPT_POSTFIELDS =>
                        $jsonPayload,

                    CURLOPT_TIMEOUT => 30,

                    CURLOPT_CONNECTTIMEOUT => 10,

                    CURLOPT_SSL_VERIFYPEER => true,

                    CURLOPT_SSL_VERIFYHOST => 2,
                ]
            );

            $responseBody =
                curl_exec($ch);

            $curlError =
                curl_error($ch);

            $httpCode =
                (int)curl_getinfo(
                    $ch,
                    CURLINFO_HTTP_CODE
                );

            curl_close($ch);

            if (
                $responseBody !== false
            ) {

                $providerResponse =
                    $responseBody;
            }

            /* =============================================
               CURL ERROR
               ============================================= */

            if (
                $responseBody === false ||
                $curlError !== ''
            ) {

                $failureReason =
                    'Connection error: ' .
                    (
                        $curlError !== ''
                            ? $curlError
                            : 'Unknown cURL error'
                    );

                try {

                    $updateLog = $db->prepare(
                        "
                        UPDATE sms_logs
                        SET
                            status = 'FAILED',
                            failure_reason = :reason,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                        "
                    );

                    $updateLog->execute([
                        ':reason' =>
                            $failureReason,

                        ':id' =>
                            $smsLogId,
                    ]);

                } catch (Throwable $e) {

                    error_log(
                        'Failed to update SMS failure log: ' .
                        $e->getMessage()
                    );
                }

                $errorMessage =
                    'SMS.UG could not be reached. Please try again later.';

                error_log(
                    'SMS.UG connection error: ' .
                    $failureReason
                );
            }

            /* =============================================
               PROCESS RESPONSE
               ============================================= */

            else {

                $providerData =
                    json_decode(
                        $responseBody,
                        true
                    );

                /* =========================================
                   SUCCESS
                   ========================================= */

                if (
                    $httpCode === 200 &&
                    is_array($providerData) &&
                    isset($providerData['status']) &&
                    $providerData['status'] === 'success'
                ) {

                    $providerToken = null;

                    if (
                        isset($providerData['token'])
                    ) {

                        $providerToken =
                            (string)$providerData['token'];
                    }

                    try {

                        $updateLog = $db->prepare(
                            "
                            UPDATE sms_logs
                            SET
                                status = 'SENT',
                                provider_message_id = :token,
                                sent_at = CURRENT_TIMESTAMP,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                            "
                        );

                        $updateLog->execute([
                            ':token' =>
                                $providerToken,

                            ':id' =>
                                $smsLogId,
                        ]);

                    } catch (Throwable $e) {

                        error_log(
                            'SMS success log update failed: ' .
                            $e->getMessage()
                        );
                    }

                    /* =====================================
                       AUDIT
                       ===================================== */

                    try {

                        logAudit(
                            $adminId,
                            'SMS_REMINDER_SENT',
                            sprintf(
                                'Rent reminder SMS sent to tenant ID %d (%s), phone %s, outstanding balance %s, SMS log ID %d, SMS.UG token %s.',
                                $tenantId,
                                $tenantName,
                                $normalizedPhone,
                                money($outstandingBalance),
                                $smsLogId,
                                $providerToken ?? 'N/A'
                            )
                        );

                    } catch (Throwable $e) {

                        error_log(
                            'Audit log failed: ' .
                            $e->getMessage()
                        );
                    }

                    $successMessage =
                        'SMS was accepted by SMS.UG and recorded successfully.';
                }

                /* =========================================
                   FAILURE
                   ========================================= */

                else {

                    $errorCode = '';

                    $providerErrorMessage = '';

                    if (is_array($providerData)) {

                        $errorCode =
                            (string)(
                                $providerData['error']
                                ?? ''
                            );

                        $providerErrorMessage =
                            (string)(
                                $providerData['message']
                                ?? ''
                            );
                    }

                    $failureReason =
                        $providerErrorMessage !== ''
                            ? $providerErrorMessage
                            : (
                                $errorCode !== ''
                                    ? $errorCode
                                    : 'SMS.UG returned HTTP ' .
                                      $httpCode
                            );

                    try {

                        $updateLog = $db->prepare(
                            "
                            UPDATE sms_logs
                            SET
                                status = 'FAILED',
                                failure_reason = :reason,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                            "
                        );

                        $updateLog->execute([
                            ':reason' =>
                                mb_substr(
                                    $failureReason,
                                    0,
                                    1000
                                ),

                            ':id' =>
                                $smsLogId,
                        ]);

                    } catch (Throwable $e) {

                        error_log(
                            'SMS failure log update failed: ' .
                            $e->getMessage()
                        );
                    }

                    /* =====================================
                       FRIENDLY ERROR
                       ===================================== */

                    if (
                        $errorCode === 'insufficient_balance'
                    ) {

                        $errorMessage =
                            'SMS.UG does not have enough balance to send this SMS.';

                    } elseif (
                        $errorCode === 'invalid_contacts'
                    ) {

                        $errorMessage =
                            'SMS.UG rejected the phone number. Please check the tenant\'s number.';

                    } elseif (
                        $errorCode === 'message_too_long'
                    ) {

                        $errorMessage =
                            'The message exceeds SMS.UG\'s 480-character limit.';

                    } elseif (
                        $errorCode === 'invalid_title'
                    ) {

                        $errorMessage =
                            'SMS.UG rejected the SMS title.';

                    } elseif (
                        $errorCode === 'api_disabled'
                    ) {

                        $errorMessage =
                            'SMS.UG API access is disabled. Enable API access in your SMS.UG account settings.';

                    } elseif (
                        $errorCode === 'unauthorized'
                    ) {

                        $errorMessage =
                            'SMS.UG rejected the API credentials. Check SMS_UG_API_KEY in your .env file.';

                    } elseif (
                        $errorCode === 'rate_limited'
                    ) {

                        $errorMessage =
                            'SMS.UG rate limit reached. Please wait before trying again.';

                    } elseif (
                        $errorCode === 'gateway_failed'
                    ) {

                        $errorMessage =
                            'SMS.UG could not hand the message to the SMS gateway. You were not charged.';

                    } elseif (
                        $errorCode === 'internal_error'
                    ) {

                        $errorMessage =
                            'SMS.UG encountered an internal error. Please try again.';

                    } else {

                        $errorMessage =
                            'SMS.UG rejected the SMS request. ' .
                            $failureReason;
                    }

                    error_log(
                        'SMS.UG API failure: ' .
                        $failureReason
                    );
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Remind Tenant —
        <?= e($tenantName) ?>
    </title>

    <link
        rel="stylesheet"
        href="assets/css/tailwind.css"
    >

</head>

<body class="bg-slate-50 text-slate-900">

<main class="min-h-screen">

    <div
        class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8"
    >

        <!-- BACK -->

        <div class="mb-6">

            <a
                href="tenants.php"
                class="inline-flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-slate-900"
            >
                ← Back to Tenants
            </a>

        </div>

        <!-- HEADER -->

        <div class="mb-8">

            <p
                class="mb-2 text-sm font-semibold uppercase tracking-wider text-indigo-600"
            >
                Rent Reminder
            </p>

            <h1
                class="text-3xl font-bold tracking-tight text-slate-900"
            >
                Remind <?= e($tenantName) ?>
            </h1>

            <p
                class="mt-2 text-sm text-slate-500"
            >
                Review the tenant's balance and SMS before sending.
            </p>

        </div>

        <!-- SUCCESS -->

        <?php if ($successMessage !== ''): ?>

            <div
                class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4"
            >

                <div class="flex gap-3">

                    <div
                        class="mt-0.5 text-emerald-600"
                    >
                        ✓
                    </div>

                    <div>

                        <h2
                            class="font-semibold text-emerald-800"
                        >
                            SMS Sent
                        </h2>

                        <p
                            class="mt-1 text-sm text-emerald-700"
                        >
                            <?= e($successMessage) ?>
                        </p>

                    </div>

                </div>

            </div>

        <?php endif; ?>

        <!-- ERROR -->

        <?php if ($errorMessage !== ''): ?>

            <div
                class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4"
            >

                <div class="flex gap-3">

                    <div
                        class="mt-0.5 font-bold text-red-600"
                    >
                        !
                    </div>

                    <div>

                        <h2
                            class="font-semibold text-red-800"
                        >
                            SMS Not Sent
                        </h2>

                        <p
                            class="mt-1 text-sm text-red-700"
                        >
                            <?= e($errorMessage) ?>
                        </p>

                    </div>

                </div>

            </div>

        <?php endif; ?>

        <div
            class="grid gap-6 lg:grid-cols-3"
        >

            <!-- TENANT SUMMARY -->

            <section
                class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-1"
            >

                <div class="mb-5">

                    <h2
                        class="text-lg font-semibold text-slate-900"
                    >
                        Tenant
                    </h2>

                </div>

                <div class="space-y-5">

                    <div>

                        <p
                            class="text-xs font-medium uppercase tracking-wide text-slate-400"
                        >
                            Name
                        </p>

                        <p
                            class="mt-1 font-semibold text-slate-900"
                        >
                            <?= e($tenantName) ?>
                        </p>

                    </div>

                    <div>

                        <p
                            class="text-xs font-medium uppercase tracking-wide text-slate-400"
                        >
                            Phone
                        </p>

                        <p
                            class="mt-1 text-slate-700"
                        >
                            <?= e(
                                $tenantPhone !== ''
                                    ? $tenantPhone
                                    : 'No phone number'
                            ) ?>
                        </p>

                    </div>

                    <div>

                        <p
                            class="text-xs font-medium uppercase tracking-wide text-slate-400"
                        >
                            Room
                        </p>

                        <p
                            class="mt-1 font-semibold text-slate-900"
                        >
                            <?= e(
                                $roomNumber !== ''
                                    ? $roomNumber
                                    : 'Not assigned'
                            ) ?>
                        </p>

                        <?php if ($roomType !== ''): ?>

                            <p
                                class="text-xs text-slate-500"
                            >
                                <?= e($roomType) ?>
                            </p>

                        <?php endif; ?>

                    </div>

                    <div>

                        <p
                            class="text-xs font-medium uppercase tracking-wide text-slate-400"
                        >
                            Monthly Rent
                        </p>

                        <p
                            class="mt-1 text-lg font-bold text-slate-900"
                        >
                            <?= e(
                                money($effectiveRent)
                            ) ?>
                        </p>

                    </div>

                    <div>

                        <p
                            class="text-xs font-medium uppercase tracking-wide text-slate-400"
                        >
                            Payment Month
                        </p>

                        <p
                            class="mt-1 font-medium text-slate-700"
                        >
                            <?= e($paymentMonth) ?>
                        </p>

                    </div>

                    <div
                        class="rounded-xl border border-slate-200 bg-slate-50 p-4"
                    >

                        <div
                            class="flex items-center justify-between"
                        >

                            <span
                                class="text-sm text-slate-500"
                            >
                                Amount Due
                            </span>

                            <span
                                class="font-semibold text-slate-900"
                            >
                                <?= e(
                                    money($amountDue)
                                ) ?>
                            </span>

                        </div>

                        <div
                            class="mt-3 flex items-center justify-between"
                        >

                            <span
                                class="text-sm text-slate-500"
                            >
                                Amount Paid
                            </span>

                            <span
                                class="font-semibold text-emerald-600"
                            >
                                <?= e(
                                    money($amountPaid)
                                ) ?>
                            </span>

                        </div>

                        <div
                            class="mt-3 border-t border-slate-200 pt-3"
                        >

                            <div
                                class="flex items-center justify-between"
                            >

                                <span
                                    class="text-sm font-medium text-slate-700"
                                >
                                    Outstanding
                                </span>

                                <span
                                    class="text-lg font-bold
                                    <?= $outstandingBalance > 0
                                        ? 'text-red-600'
                                        : 'text-emerald-600' ?>"
                                >
                                    <?= e(
                                        money(
                                            $outstandingBalance
                                        )
                                    ) ?>
                                </span>

                            </div>

                        </div>

                    </div>

                    <div>

                        <span
                            class="inline-flex rounded-full px-3 py-1 text-xs font-semibold
                            <?= $paymentStatus === 'Paid'
                                ? 'bg-emerald-100 text-emerald-700'
                                : (
                                    $paymentStatus === 'Overdue'
                                        ? 'bg-red-100 text-red-700'
                                        : 'bg-amber-100 text-amber-700'
                                ) ?>"
                        >
                            <?= e($paymentStatus) ?>
                        </span>

                        <?php if ($daysOverdue > 0): ?>

                            <p
                                class="mt-2 text-xs text-red-600"
                            >
                                <?= $daysOverdue ?>
                                day<?= $daysOverdue === 1 ? '' : 's' ?>
                                overdue
                            </p>

                        <?php endif; ?>

                    </div>

                </div>

            </section>

            <!-- SMS FORM -->

            <section
                class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2"
            >

                <div class="mb-6">

                    <h2
                        class="text-lg font-semibold text-slate-900"
                    >
                        SMS Reminder
                    </h2>

                    <p
                        class="mt-1 text-sm text-slate-500"
                    >
                        Review the recipient and message before sending.
                    </p>

                </div>

                <form
                    method="POST"
                    action=""
                    id="smsForm"
                    class="space-y-6"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="tenant_id"
                        value="<?= (int)$tenantId ?>"
                    >

                    <!-- PHONE -->

                    <div>

                        <label
                            for="phone"
                            class="mb-2 block text-sm font-medium text-slate-700"
                        >
                            Recipient phone number
                        </label>

                        <input
                            type="text"
                            id="phone"
                            name="phone"
                            value="<?= e($phoneForForm) ?>"
                            autocomplete="tel"
                            required
                            class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                            placeholder="0704487563"
                        >

                        <p
                            class="mt-2 text-xs text-slate-500"
                        >
                            Example: 0704487563
                        </p>

                    </div>

                    <!-- MESSAGE -->

                    <div>

                        <div
                            class="mb-2 flex items-center justify-between"
                        >

                            <label
                                for="message"
                                class="block text-sm font-medium text-slate-700"
                            >
                                Message
                            </label>

                            <span
                                id="charCounter"
                                class="text-xs text-slate-400"
                            >
                                <?= mb_strlen($message) ?>/480
                            </span>

                        </div>

                        <textarea
                            id="message"
                            name="message"
                            rows="8"
                            maxlength="480"
                            required
                            class="block w-full resize-y rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm leading-6 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                        ><?= e($message) ?></textarea>

                        <p
                            class="mt-2 text-xs text-slate-500"
                        >
                            SMS.UG charges multiple SMS units for messages over 160 characters.
                        </p>

                    </div>

                    <!-- BALANCE -->

                    <?php if ($outstandingBalance > 0): ?>

                        <div
                            class="rounded-xl border border-amber-200 bg-amber-50 p-4"
                        >

                            <div class="flex gap-3">

                                <div
                                    class="text-amber-600"
                                >
                                    ⚠
                                </div>

                                <div>

                                    <p
                                        class="text-sm font-semibold text-amber-800"
                                    >
                                        Outstanding balance:
                                        <?= e(
                                            money(
                                                $outstandingBalance
                                            )
                                        ) ?>
                                    </p>

                                    <p
                                        class="mt-1 text-xs text-amber-700"
                                    >
                                        The balance will be verified again before sending.
                                    </p>

                                </div>

                            </div>

                        </div>

                    <?php else: ?>

                        <div
                            class="rounded-xl border border-emerald-200 bg-emerald-50 p-4"
                        >

                            <p
                                class="text-sm font-semibold text-emerald-800"
                            >
                                This tenant currently has no outstanding balance.
                            </p>

                        </div>

                    <?php endif; ?>

                    <!-- ACTIONS -->

                    <div
                        class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-6 sm:flex-row sm:justify-end"
                    >

                        <a
                            href="tenants.php"
                            class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            id="sendButton"
                            <?= $outstandingBalance <= 0
                                ? 'disabled'
                                : '' ?>
                            class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >

                            <span id="sendButtonText">
                                Send SMS
                            </span>

                        </button>

                    </div>

                </form>

            </section>

        </div>

        <!-- PROVIDER RESPONSE -->

        <?php if ($providerResponse !== null): ?>

            <section
                class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
            >

                <div class="mb-3">

                    <h2
                        class="text-sm font-semibold text-slate-900"
                    >
                        SMS.UG Response
                    </h2>

                </div>

                <pre
                    class="overflow-x-auto rounded-xl bg-slate-900 p-4 text-xs leading-6 text-slate-200"
                ><?= e($providerResponse) ?></pre>

                <p
                    class="mt-3 text-xs text-slate-500"
                >
                    A successful response means SMS.UG accepted the message. Delivery status can be checked from the SMS.UG dashboard.
                </p>

            </section>

        <?php endif; ?>

    </div>

</main>

<script>

(function () {

    const form =
        document.getElementById('smsForm');

    const message =
        document.getElementById('message');

    const counter =
        document.getElementById('charCounter');

    const button =
        document.getElementById('sendButton');

    const buttonText =
        document.getElementById('sendButtonText');

    /* CHARACTER COUNTER */

    if (message && counter) {

        const updateCounter = function () {

            counter.textContent =
                message.value.length +
                '/480';

        };

        message.addEventListener(
            'input',
            updateCounter
        );

        updateCounter();
    }

    /* PREVENT DOUBLE SUBMISSION */

    if (form && button) {

        form.addEventListener(
            'submit',
            function () {

                button.disabled = true;

                if (buttonText) {

                    buttonText.textContent =
                        'Sending...';
                }

            }
        );
    }

})();

</script>

</body>

</html>

