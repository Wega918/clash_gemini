<?php
/**
 * JSON Guard: prevents PHP warnings/notices/HTML from corrupting JSON responses.
 * Include this file at the VERY TOP of any JSON endpoint (before any output).
 */
declare(strict_types=0);

// Always serve JSON
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

// Stop PHP from printing warnings/notices into the response (they break JSON)
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

// Buffer everything so we can wipe non-JSON output if needed
if (!ob_get_level()) {
    ob_start();
}

function json_guard_send_error($message, $code = 500) {
    while (ob_get_level()) { @ob_end_clean(); }
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    http_response_code((int)$code);
    echo json_encode(['ok'=>false,'error'=>(string)$message], JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(function($e){
    $msg = ($e instanceof Throwable) ? $e->getMessage() : 'Server error';
    $code = ($e instanceof Throwable) ? (int)$e->getCode() : 500;
    if ($code < 400 || $code > 599) $code = 500;
    json_guard_send_error($msg, $code);
});

set_error_handler(function($severity, $message, $file, $line){
    throw new ErrorException($message . " in " . basename($file) . ":" . $line, 500, $severity, $file, $line);
});

register_shutdown_function(function(){
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        json_guard_send_error($err['message'] . " in " . basename($err['file']) . ":" . $err['line'], 500);
    }
});
