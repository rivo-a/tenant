<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Yoola SMS Test Page
|--------------------------------------------------------------------------
| File: public/remind.php
|
| This page is ONLY for testing the Yoola SMS integration.
| Once confirmed working, we will connect it to tenants/rent.
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| 1. DEFINE PROJECT ROOT
|--------------------------------------------------------------------------
|
| remind.php is inside:
|
| tenant-system/public/remind.php
|
| dirname(__DIR__) takes us one level up to:
|
| tenant-system/
|--------------------------------------------------------------------------
*/

$projectRoot = dirname(__DIR__);


/*
|--------------------------------------------------------------------------
| 2. LOAD COMPOSER
|--------------------------------------------------------------------------
*/

$autoload = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    die(
        'Composer autoloader was not found.<br><br>' .
        'Expected location:<br>' .
        '<strong>' . htmlspecialchars($autoload) . '</strong><br><br>' .
        'Make sure the vendor folder exists in your project root.'
    );
}

require_once $autoload;


/*
|--------------------------------------------------------------------------
| 3. LOAD .ENV
|--------------------------------------------------------------------------
|
| The .env file should be here:
|
| tenant-system/.env
|
| NOT:
|
| tenant-system/public/.env
|--------------------------------------------------------------------------
*/

try {

    $dotenv = Dotenv\Dotenv::createImmutable($projectRoot);
    $dotenv->load();

} catch (Throwable $e) {

    die(
        '<strong>Could not load .env</strong><br><br>' .
        'Make sure you have a file called <strong>.env</strong> ' .
        'in the project root:<br><br>' .
        '<strong>' .
        htmlspecialchars($projectRoot . '/.env') .
        '</strong>'
    );
}


/*
|--------------------------------------------------------------------------
| 4. GET YOOLA API KEY
|--------------------------------------------------------------------------
*/

$yoolaApiKey = $_ENV['YOOLA_API_KEY'] ?? '';

if ($yoolaApiKey === '') {

    die(
        '<strong>Yoola API key is missing.</strong><br><br>' .
        'Add the following to your .env file:<br><br>' .
        '<code>YOOLA_API_KEY=YOUR_NEW_API_KEY</code>'
    );
}


$yoolaApiUrl = 'https://yoolasms.com/api/v1/send';


/*
|--------------------------------------------------------------------------
| 4. CREATE CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {

    $_SESSION['csrf_token'] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];


/*
|--------------------------------------------------------------------------
| 5. DEFAULT VALUES
|--------------------------------------------------------------------------
*/

$phone = '0744063716';

$message =
    'Hello from our Rental Management System. ' .
    'This is a test SMS.';

$result = null;

$error = null;

$success = null;

$httpCode = null;


/*
|--------------------------------------------------------------------------
| 6. HTML ESCAPE FUNCTION
|--------------------------------------------------------------------------
*/

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| 7. PHONE NUMBER NORMALIZATION
|--------------------------------------------------------------------------
*/

function normalizeUgandaPhone(string $phone): string
{
    /*
    |--------------------------------------------------------------------------
    | Remove spaces, hyphens and brackets
    |--------------------------------------------------------------------------
    */

    $phone = preg_replace(
        '/[\s\-\(\)]+/',
        '',
        $phone
    );

    if ($phone === null) {
        return '';
    }


    /*
    |--------------------------------------------------------------------------
    | +256704487563
    | becomes
    | 256704487563
    |--------------------------------------------------------------------------
    */

    if (str_starts_with($phone, '+')) {

        $phone = substr($phone, 1);
    }


    /*
    |--------------------------------------------------------------------------
    | 0704487563
    | becomes
    | 256704487563
    |--------------------------------------------------------------------------
    */

    if (
        preg_match(
            '/^0(7\d{8})$/',
            $phone,
            $matches
        )
    ) {

        $phone =
            '256' .
            $matches[1];
    }


    return $phone;
}


/*
|--------------------------------------------------------------------------
| 8. HANDLE FORM SUBMISSION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    /*
    |--------------------------------------------------------------------------
    | CSRF CHECK
    |--------------------------------------------------------------------------
    */

    $submittedToken =
        $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $error =
            'Security validation failed. ' .
            'Please refresh the page and try again.';
    }


    /*
    |--------------------------------------------------------------------------
    | API KEY CHECK
    |--------------------------------------------------------------------------
    */

    elseif ($yoolaApiKey === '') {

        $error =
            'Yoola API key is not configured. ' .
            'Make sure YOOLA_API_KEY exists in your .env file.';
    }


    /*
    |--------------------------------------------------------------------------
    | READ FORM DATA
    |--------------------------------------------------------------------------
    */

    else {

        $phone =
            trim(
                (string) (
                    $_POST['phone'] ?? ''
                )
            );

        $message =
            trim(
                (string) (
                    $_POST['message'] ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | PHONE VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($phone === '') {

            $error =
                'Please enter a phone number.';

        } else {

            $phone =
                normalizeUgandaPhone(
                    $phone
                );


            /*
            |--------------------------------------------------------------------------
            | Uganda mobile format
            |--------------------------------------------------------------------------
            |
            | 2567XXXXXXXX
            |
            */

            if (
                !preg_match(
                    '/^2567\d{8}$/',
                    $phone
                )
            ) {

                $error =
                    'Please enter a valid Uganda mobile number. ' .
                    'Example: 0704487563 or 256704487563.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | MESSAGE VALIDATION
        |--------------------------------------------------------------------------
        */

        if (
            $error === null &&
            $message === ''
        ) {

            $error =
                'Please enter a message.';
        }


        if (
            $error === null &&
            mb_strlen($message) > 1000
        ) {

            $error =
                'Message cannot exceed 1000 characters.';
        }


        /*
        |--------------------------------------------------------------------------
        | SEND SMS
        |--------------------------------------------------------------------------
        */

        if ($error === null) {


            /*
            |--------------------------------------------------------------------------
            | Yoola Request
            |--------------------------------------------------------------------------
            */

            $data = [

                'api_key' =>
                    $yoolaApiKey,

                'phone' =>
                    $phone,

                'message' =>
                    $message,

            ];


            /*
            |--------------------------------------------------------------------------
            | Convert to JSON
            |--------------------------------------------------------------------------
            */

            $jsonData = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );


            /*
            |--------------------------------------------------------------------------
            | Initialize CURL
            |--------------------------------------------------------------------------
            */

            $ch =
                curl_init(
                    $yoolaApiUrl
                );


            if ($ch === false) {

                $error =
                    'Could not initialize the Yoola connection.';

            } else {


                /*
                |--------------------------------------------------------------------------
                | CURL OPTIONS
                |--------------------------------------------------------------------------
                */

                curl_setopt_array(
                    $ch,
                    [

                        CURLOPT_POST =>
                            true,

                        CURLOPT_POSTFIELDS =>
                            $jsonData,

                        CURLOPT_HTTPHEADER =>
                            [

                                'Content-Type: application/json',

                                'Accept: application/json',

                            ],

                        CURLOPT_RETURNTRANSFER =>
                            true,

                        CURLOPT_TIMEOUT =>
                            30,

                        CURLOPT_CONNECTTIMEOUT =>
                            10,

                        /*
                        |--------------------------------------------------------------------------
                        | Keep SSL verification enabled
                        |--------------------------------------------------------------------------
                        */

                        CURLOPT_SSL_VERIFYPEER =>
                            true,

                        CURLOPT_SSL_VERIFYHOST =>
                            2,

                    ]
                );


                /*
                |--------------------------------------------------------------------------
                | SEND REQUEST
                |--------------------------------------------------------------------------
                */

                $response =
                    curl_exec($ch);


                /*
                |--------------------------------------------------------------------------
                | HTTP STATUS
                |--------------------------------------------------------------------------
                */

                $httpCode =
                    curl_getinfo(
                        $ch,
                        CURLINFO_HTTP_CODE
                    );


                /*
                |--------------------------------------------------------------------------
                | CURL ERROR
                |--------------------------------------------------------------------------
                */

                $curlError =
                    curl_error($ch);


                /*
                |--------------------------------------------------------------------------
                | CLOSE CURL
                |--------------------------------------------------------------------------
                */

                curl_close($ch);


                /*
                |--------------------------------------------------------------------------
                | CONNECTION ERROR
                |--------------------------------------------------------------------------
                */

                if (
                    $response === false ||
                    $curlError !== ''
                ) {

                    $error =
                        'Could not connect to Yoola.';

                    if ($curlError !== '') {

                        $error .=
                            ' CURL Error: ' .
                            $curlError;
                    }

                } else {


                    /*
                    |--------------------------------------------------------------------------
                    | DECODE RESPONSE
                    |--------------------------------------------------------------------------
                    */

                    $decodedResponse =
                        json_decode(
                            $response,
                            true
                        );


                    /*
                    |--------------------------------------------------------------------------
                    | JSON RESPONSE
                    |--------------------------------------------------------------------------
                    */

                    if (
                        json_last_error() ===
                        JSON_ERROR_NONE
                    ) {

                        $result =
                            $decodedResponse;

                    } else {

                        $result =
                            [

                                'raw_response' =>
                                    $response,

                            ];
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | HTTP RESULT
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $httpCode >= 200 &&
                        $httpCode < 300
                    ) {

                        $success =
                            'Yoola accepted the SMS request. ' .
                            'Check the response below for the exact provider status.';

                    } else {

                        $error =
                            'Yoola returned HTTP ' .
                            $httpCode .
                            '. Check the response below.';
                    }
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
        SMS Reminder | Rental Management System
    </title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

            background: #f5f7fb;

            color: #172033;
        }


        .container {

            width:
                min(
                    900px,
                    calc(100% - 32px)
                );

            margin:
                50px auto;
        }


        .header {

            margin-bottom: 24px;
        }


        .header h1 {

            margin:
                0 0 8px;

            font-size: 30px;
        }


        .header p {

            margin: 0;

            color: #687386;

            line-height: 1.6;
        }


        .card {

            background: white;

            border-radius: 16px;

            padding: 28px;

            box-shadow:
                0 8px 30px
                rgba(
                    15,
                    23,
                    42,
                    0.07
                );

            margin-bottom: 20px;
        }


        .card h2 {

            margin-top: 0;

            margin-bottom: 6px;

            font-size: 20px;
        }


        .card-description {

            color: #6b7280;

            margin-top: 0;

            margin-bottom: 24px;

            line-height: 1.6;
        }


        .form-group {

            margin-bottom: 20px;
        }


        label {

            display: block;

            font-weight: 600;

            margin-bottom: 8px;
        }


        input,
        textarea {

            width: 100%;

            border:
                1px solid #d8dee9;

            border-radius: 10px;

            padding:
                13px 14px;

            font-size: 15px;

            outline: none;

            transition:
                border-color 0.2s;

            font-family: inherit;
        }


        input:focus,
        textarea:focus {

            border-color: #4f46e5;
        }


        textarea {

            min-height: 130px;

            resize: vertical;
        }


        .help {

            display: block;

            margin-top: 7px;

            font-size: 13px;

            color: #7b8494;
        }


        .button {

            border: 0;

            border-radius: 10px;

            padding:
                13px 20px;

            font-size: 15px;

            font-weight: 600;

            cursor: pointer;

            background: #4f46e5;

            color: white;

            transition: 0.2s;
        }


        .button:hover {

            background: #4338ca;
        }


        .button:disabled {

            opacity: 0.6;

            cursor:
                not-allowed;
        }


        .alert {

            border-radius: 10px;

            padding:
                15px 16px;

            margin-bottom: 20px;

            line-height: 1.5;
        }


        .alert-error {

            background: #fef2f2;

            color: #991b1b;

            border:
                1px solid #fecaca;
        }


        .alert-success {

            background: #f0fdf4;

            color: #166534;

            border:
                1px solid #bbf7d0;
        }


        .response-box {

            background: #111827;

            color: #e5e7eb;

            padding: 20px;

            border-radius: 10px;

            overflow-x: auto;
        }


        pre {

            margin: 0;

            white-space:
                pre-wrap;

            word-break:
                break-word;

            line-height: 1.6;
        }


        .status {

            display: inline-block;

            padding:
                5px 10px;

            border-radius:
                999px;

            font-size: 13px;

            font-weight: 700;

            margin-bottom: 12px;
        }


        .status-success {

            background: #dcfce7;

            color: #166534;
        }


        .status-error {

            background: #fee2e2;

            color: #991b1b;
        }


        .info-grid {

            display: grid;

            grid-template-columns:
                repeat(
                    3,
                    1fr
                );

            gap: 12px;

            margin-top: 20px;
        }


        .info-item {

            background: #f8fafc;

            border-radius: 10px;

            padding: 14px;
        }


        .info-label {

            display: block;

            font-size: 12px;

            color: #6b7280;

            margin-bottom: 5px;
        }


        .info-value {

            font-weight: 700;

            word-break:
                break-word;
        }


        .check-list {

            padding-left: 20px;

            line-height: 1.9;

            color: #4b5563;
        }


        @media (max-width: 650px) {

            .container {

                margin:
                    25px auto;
            }


            .card {

                padding: 20px;
            }


            .info-grid {

                grid-template-columns:
                    1fr;
            }


            .header h1 {

                font-size: 25px;
            }

        }

    </style>

</head>


<body>


<div class="container">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <div class="header">

        <h1>
            SMS Reminder
        </h1>

        <p>
            Test the Yoola SMS integration before
            connecting it to your tenant and
            rent-reminder system.
        </p>

    </div>


    <!-- =========================================================
         ERROR
    ========================================================== -->

    <?php if ($error !== null): ?>

        <div class="alert alert-error">

            <strong>
                SMS could not be sent.
            </strong>

            <br>

            <?= e($error) ?>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         SUCCESS
    ========================================================== -->

    <?php if ($success !== null): ?>

        <div class="alert alert-success">

            <strong>
                SMS request submitted.
            </strong>

            <br>

            <?= e($success) ?>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         SMS FORM
    ========================================================== -->

    <div class="card">

        <h2>
            Send Test SMS
        </h2>

        <p class="card-description">

            Enter a Uganda mobile number and send
            one test message through Yoola.

        </p>


        <form
            method="POST"
            id="smsForm"
        >


            <!-- CSRF -->

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >


            <!-- PHONE -->

            <div class="form-group">

                <label for="phone">

                    Phone Number

                </label>


                <input
                    type="text"
                    id="phone"
                    name="phone"
                    value="<?= e($phone) ?>"
                    placeholder="0704487563"
                    autocomplete="tel"
                    required
                >


                <small class="help">

                    You can enter:

                    <strong>
                        0704487563
                    </strong>

                    or

                    <strong>
                        256704487563
                    </strong>

                </small>

            </div>


            <!-- MESSAGE -->

            <div class="form-group">

                <label for="message">

                    Message

                </label>


                <textarea
                    id="message"
                    name="message"
                    maxlength="1000"
                    required
                ><?= e($message) ?></textarea>


                <small class="help">

                    This is a test SMS.
                    Yoola SMS charges may apply.

                </small>

            </div>


            <!-- BUTTON -->

            <button
                type="submit"
                class="button"
                id="sendButton"
            >

                Send Test SMS

            </button>


        </form>

    </div>


    <!-- =========================================================
         YOOLA RESPONSE
    ========================================================== -->

    <?php if ($result !== null): ?>

        <div class="card">

            <h2>
                Yoola Response
            </h2>


            <?php

            $isHttpSuccess =
                $httpCode !== null &&
                $httpCode >= 200 &&
                $httpCode < 300;

            ?>


            <?php if ($isHttpSuccess): ?>

                <span class="status status-success">

                    HTTP
                    <?= e(
                        (string) $httpCode
                    ) ?>

                </span>

            <?php else: ?>

                <span class="status status-error">

                    HTTP
                    <?= e(
                        (string) (
                            $httpCode ??
                            'Unknown'
                        )
                    ) ?>

                </span>

            <?php endif; ?>


            <!-- RESPONSE SUMMARY -->

            <div class="info-grid">


                <div class="info-item">

                    <span class="info-label">
                        Phone
                    </span>

                    <span class="info-value">

                        <?= e($phone) ?>

                    </span>

                </div>


                <div class="info-item">

                    <span class="info-label">
                        HTTP Status
                    </span>

                    <span class="info-value">

                        <?= e(
                            (string) (
                                $httpCode ??
                                'Unknown'
                            )
                        ) ?>

                    </span>

                </div>


                <div class="info-item">

                    <span class="info-label">
                        Provider
                    </span>

                    <span class="info-value">

                        Yoola SMS

                    </span>

                </div>


            </div>


            <!-- RAW RESPONSE -->

            <div style="margin-top:20px;">

                <h3>
                    Provider Response
                </h3>


                <div class="response-box">

                    <pre><?= e(
                        json_encode(
                            $result,
                            JSON_PRETTY_PRINT |
                            JSON_UNESCAPED_SLASHES |
                            JSON_UNESCAPED_UNICODE
                        )
                    ) ?></pre>

                </div>

            </div>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         STATUS
    ========================================================== -->

    <div class="card">

        <h2>
            Integration Status
        </h2>


        <p class="card-description">

            This page is currently only a Yoola
            integration test. Do not connect
            automatic rent reminders until this
            test successfully sends an SMS.

        </p>


        <ul class="check-list">

            <li>
                Environment configuration
            </li>

            <li>
                Secure API-key loading
            </li>

            <li>
                CSRF protection
            </li>

            <li>
                Uganda phone-number formatting
            </li>

            <li>
                JSON API request
            </li>

            <li>
                HTTP response handling
            </li>

            <li>
                CURL error handling
            </li>

            <li>
                Yoola response display
            </li>

            <li>
                API key kept outside PHP source
            </li>

        </ul>

    </div>


</div>


<script>

/*
|--------------------------------------------------------------------------
| Prevent double submissions
|--------------------------------------------------------------------------
*/

document
    .getElementById('smsForm')
    .addEventListener(
        'submit',
        function () {

            const button =
                document.getElementById(
                    'sendButton'
                );

            button.disabled = true;

            button.textContent =
                'Sending SMS...';

        }
    );

</script>


</body>

</html>

