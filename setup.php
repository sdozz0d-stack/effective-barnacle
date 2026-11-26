<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

define('APP_SKIP_SCHEMA_CHECK', true);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/PositionsSchema.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/sheets_schema.php';
require_once __DIR__ . '/competency_template_schema.php';

try {
    if (!app_allow_ddl()) {
        throw new RuntimeException('Database schema changes are disabled by app_allow_ddl().');
    }

    ensure_auth_schema($conn);
    ensure_positions_schema($conn);
    ensure_schema($conn);
    ensure_competency_template_schema($conn);
    ensure_sheets_schema($conn);
    ensure_drafts_schema($conn);
    $conn->query("SELECT account_type, rater_position FROM users LIMIT 0");
    $conn->query("SELECT cs.candidate_id, cs.rater, cs.psycho, cs.potential, u.account_type
        FROM candidate_scores cs LEFT JOIN users u ON u.id = cs.rated_by LIMIT 0");
    $conn->query("SELECT id, candidate_name, office_name, position_title, office_type FROM candidates LIMIT 0");
    $conn->query("SELECT s.title, s.candidate_name, s.office_name, s.updated_at, u.username, u.account_type
        FROM sheets s JOIN users u ON u.id = s.user_id WHERE s.status = 'draft' LIMIT 0");
    $conn->query("SELECT d.candidate_name, d.position_title, d.office_name, d.updated_at, u.username, u.account_type
        FROM form_drafts d JOIN users u ON u.id = d.user_id WHERE d.status = 'draft' LIMIT 0");
    app_require_tables($conn, [
        'users', 'remember_tokens', 'login_attempts', 'submission_attempts',
        'candidates', 'candidate_scores', 'sheet_settings',
        'position_rater_order', 'removed_tabs', 'sheet_documents',
        'competency_templates', 'sheets', 'sheet_cells', 'evidence_options',
        'positions', 'positions_seed_state_v2', 'password_reset_requests',
        'form_drafts', 'deleted_candidates', 'office_tabs', 'score_audit', 'database_backups',
    ]);
    fwrite(STDOUT, "Database setup completed successfully for '" . DB_NAME . "'.\n");
} catch (Throwable $exception) {
    error_log('setup.php failed: ' . $exception);
    fwrite(STDERR, "Database setup failed for '" . DB_NAME . "' at " . DB_HOST . ':' . DB_PORT . ".\n");
    fwrite(STDERR, "Error: " . $exception->getMessage() . PHP_EOL);
    exit(1);
}