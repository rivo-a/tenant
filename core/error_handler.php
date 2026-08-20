<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

/**
 * HARD-BOUND logger
 * Uses BASE_PATH which must be defined BEFORE including this file
 */
function log_error($message)
{
    $file = BASE_PATH . '/storage/logs/app.log';

    // Only write if file exists
    if (!file_exists($file)) {
        // Try to create it, fail silently if cannot
        @file_put_contents($file, '');
    }

    $time = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$time] $message" . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// Catch all errors
set_error_handler(function ($severity, $message, $file, $line) {
    log_error("ERROR: $message in $file on line $line");
    return true;
});

// Catch all exceptions (compatible with all PHP versions)
set_exception_handler(function ($e) {
    log_error("EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    http_response_code(500);
    echo 'Something went wrong.';
    exit;
});

// Catch fatal shutdown errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error) {
        log_error("FATAL: {$error['message']} in {$error['file']} on line {$error['line']}");
    }
});
?>