<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

/**
 * HARD-BOUND logger
 * Uses BASE_PATH which must be defined BEFORE including this file
 */
function log_error($message)
{
    $dir = BASE_PATH . '/storage/logs';
    $file = $dir . '/app.log';

    /*
     * The directory is git-ignored, so a fresh clone, a deploy or a history
     * rewrite can leave it missing. Without this the logger failed, and every
     * notice surfaced to the user as a bare 500 page.
     */
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $time = date('Y-m-d H:i:s');
    $entry = "[$time] $message" . PHP_EOL;

    if (@file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) === false) {
        // Last resort so the message is never lost completely.
        error_log('[tenant-system] ' . $message);
    }
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