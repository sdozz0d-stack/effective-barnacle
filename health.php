<?php

define('APP_SKIP_SCHEMA_CHECK', true);
require_once __DIR__ . '/bootstrap.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

$checks = [
    'application' => 'ok',
];
$healthy = true;

try {
    $conn->query('SELECT 1');
    $checks['database'] = 'ok';
} catch (Throwable $exception) {
    $healthy = false;
    $checks['database'] = 'unavailable';
    error_log('Health check database failure: ' . $exception->getMessage());
}

http_response_code($healthy ? 200 : 503);
echo json_encode([
    'status' => $healthy ? 'ok' : 'degraded',
    'checks' => $checks,
]);
