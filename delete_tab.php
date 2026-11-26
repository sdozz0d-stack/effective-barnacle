<?php
// ============================================================
// delete_tab.php — COMPLETE FIXED FILE
//
// FIX (Bug C): require_admin(true) — JSON-safe 401/403.
// ============================================================

ob_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_admin(true);

function json_out(array $payload, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['status' => 'error', 'message' => 'Invalid request.'], 405);
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    json_out(['status' => 'error', 'message' => 'Your session expired. Please refresh the page and try again.'], 403);
}

$office = strtoupper(mb_substr(trim((string)($_POST['office'] ?? '')), 0, 100));
if ($office === '') {
    json_out(['status' => 'error', 'message' => 'Invalid tab name.'], 422);
}
json_out([
    'status' => 'error',
    'message' => 'Whole-office deletion is disabled. Delete applicants by account type from the results page.',
], 409);
