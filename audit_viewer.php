<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';

require_admin();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

const AUDIT_VIEWER_ACTIONS = [
    'submit', 'edit', 'delete', 'restore', 'role_change', 'password_reset',
    'login_failure', 'login_success', 'logout',
];
const AUDIT_VIEWER_PAGE_SIZE = 50;

function audit_viewer_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function audit_viewer_date_is_valid(string $date): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function audit_viewer_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') return;
    $arguments = [$types];
    foreach ($params as &$param) {
        $arguments[] = &$param;
    }
    call_user_func_array([$stmt, 'bind_param'], $arguments);
}

function audit_viewer_csv_cell($value): string {
    $value = (string)($value ?? '');
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

$filters = [
    'username' => trim((string)($_GET['username'] ?? '')),
    'action' => trim((string)($_GET['action'] ?? '')),
    'candidate_id' => trim((string)($_GET['candidate_id'] ?? '')),
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
];
$errors = [];
if (mb_strlen($filters['username']) > 100) {
    $errors[] = 'Username filter must be 100 characters or fewer.';
    $filters['username'] = mb_substr($filters['username'], 0, 100);
}
if ($filters['action'] !== '' && !in_array($filters['action'], AUDIT_VIEWER_ACTIONS, true)) {
    $errors[] = 'Choose a valid audit action.';
    $filters['action'] = '';
}
if ($filters['candidate_id'] !== '' && !ctype_digit($filters['candidate_id'])) {
    $errors[] = 'Candidate ID must be a positive whole number.';
    $filters['candidate_id'] = '';
} elseif ($filters['candidate_id'] !== '' && (int)$filters['candidate_id'] < 1) {
    $errors[] = 'Candidate ID must be a positive whole number.';
    $filters['candidate_id'] = '';
}
foreach (['date_from', 'date_to'] as $dateKey) {
    if ($filters[$dateKey] !== '' && !audit_viewer_date_is_valid($filters[$dateKey])) {
        $errors[] = 'Enter dates using YYYY-MM-DD.';
        $filters[$dateKey] = '';
    }
}
if ($filters['date_from'] !== '' && $filters['date_to'] !== ''
    && $filters['date_from'] > $filters['date_to']) {
    $errors[] = 'The start date must not be later than the end date.';
}

$where = [];
$filterTypes = '';
$filterParams = [];
if ($filters['username'] !== '') {
    $where[] = 'username LIKE ?';
    $filterTypes .= 's';
    $filterParams[] = '%' . $filters['username'] . '%';
}
if ($filters['action'] !== '') {
    $where[] = '`action` = ?';
    $filterTypes .= 's';
    $filterParams[] = $filters['action'];
}
if ($filters['candidate_id'] !== '') {
    $where[] = 'candidate_id = ?';
    $filterTypes .= 'i';
    $filterParams[] = (int)$filters['candidate_id'];
}
if ($filters['date_from'] !== '') {
    $where[] = 'created_at >= ?';
    $filterTypes .= 's';
    $filterParams[] = $filters['date_from'] . ' 00:00:00';
}
if ($filters['date_to'] !== '') {
    $where[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $filterTypes .= 's';
    $filterParams[] = $filters['date_to'] . ' 00:00:00';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$export = ($_GET['export'] ?? '') === 'csv';
$auditTableAvailable = true;
try {
    $conn->query('SELECT 1 FROM score_audit LIMIT 0');
} catch (mysqli_sql_exception $e) {
    if ((int)$e->getCode() !== 1146 || stripos($e->getMessage(), 'score_audit') === false) {
        throw $e;
    }
    error_log('audit_viewer schema not initialized: ' . $e->getMessage());
    $auditTableAvailable = false;
}

if (!$auditTableAvailable && $export) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Audit history is not set up yet. Ask the server administrator to run `php setup.php` from the application directory.';
    exit;
}

if (!$errors && $auditTableAvailable && $export) {
    $stmt = $conn->prepare('SELECT id, candidate_id, user_id, username, `action`, old_value, new_value, ip, user_agent, created_at
        FROM score_audit' . $whereSql . ' ORDER BY created_at DESC, id DESC');
    audit_viewer_bind($stmt, $filterTypes, $filterParams);
    $stmt->execute();
    $result = $stmt->get_result();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="score-audit.csv"');
    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new RuntimeException('Unable to open CSV output stream.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['ID', 'Candidate ID', 'User ID', 'Username', 'Action', 'Old Value', 'New Value', 'IP', 'User Agent', 'Created At'], ',', '"', '');
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, array_map('audit_viewer_csv_cell', array_values($row)), ',', '"', '');
    }
    fclose($output);
    $stmt->close();
    exit;
}

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page === false || $page < 1 ? 1 : $page;
$totalRows = 0;
$rows = [];
$totalPages = 1;
if (!$errors && $auditTableAvailable) {
    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM score_audit' . $whereSql);
    audit_viewer_bind($countStmt, $filterTypes, $filterParams);
    $countStmt->execute();
    $totalRows = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();
    $totalPages = max(1, (int)ceil($totalRows / AUDIT_VIEWER_PAGE_SIZE));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * AUDIT_VIEWER_PAGE_SIZE;
    $queryParams = $filterParams;
    $queryParams[] = AUDIT_VIEWER_PAGE_SIZE;
    $queryParams[] = $offset;
    $stmt = $conn->prepare('SELECT id, candidate_id, user_id, username, `action`, old_value, new_value, ip, user_agent, created_at
        FROM score_audit' . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
    audit_viewer_bind($stmt, $filterTypes . 'ii', $queryParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$queryFilters = array_filter($filters, static fn(string $value): bool => $value !== '');
$csvUrl = '?' . http_build_query($queryFilters + ['export' => 'csv']);
$pageUrl = static function (int $targetPage) use ($queryFilters): string {
    return '?' . http_build_query($queryFilters + ['page' => $targetPage]);
};

function audit_viewer_key_label(string $key): string {
    $labels = [
        'user_id' => 'User ID',
        'username' => 'Username',
        'username_attempted' => 'Attempted username',
        'via' => 'Method',
        'ip' => 'IP address',
        'psycho' => 'Psychosocial score',
    ];
    return $labels[$key] ?? (ctype_digit($key) ? 'Entry ' . ((int)$key + 1) : ucwords(str_replace('_', ' ', $key)));
}

function audit_viewer_pairs(array $value, string $prefix = '', int $depth = 0): array {
    $pairs = [];
    foreach ($value as $key => $item) {
        $label = audit_viewer_key_label((string)$key);
        $path = $prefix === '' ? $label : $prefix . ' / ' . $label;
        if (is_array($item) && $depth < 4) {
            $pairs = array_merge($pairs, audit_viewer_pairs($item, $path, $depth + 1));
        } else {
            if (is_array($item)) $item = '[additional nested data]';
            if (is_bool($item)) $item = $item ? 'Yes' : 'No';
            $pairs[] = [$path, $item === null ? '—' : (string)$item];
        }
    }
    return $pairs;
}

function audit_viewer_value(?string $value): string {
    if ($value === null || $value === '') return '—';
    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $pairs = audit_viewer_pairs($decoded);
        if (!$pairs) return '<span class="muted">No values</span>';
        $preview = array_slice($pairs, 0, 2);
        $html = '<div class="value-preview">';
        foreach ($preview as [$label, $item]) {
            $html .= '<span><strong>' . audit_viewer_h($label) . ':</strong> ' . audit_viewer_h($item) . '</span>';
        }
        $html .= '</div>';
        if (count($pairs) > count($preview)) {
            $html .= '<details><summary>Show all ' . count($pairs) . ' fields</summary><dl>';
            foreach ($pairs as [$label, $item]) {
                $html .= '<dt>' . audit_viewer_h($label) . '</dt><dd>' . audit_viewer_h($item) . '</dd>';
            }
            $html .= '</dl></details>';
        }
        return $html;
    }
    return '<span>' . nl2br(audit_viewer_h($value)) . '</span>';
}

function audit_viewer_change(?string $oldValue, ?string $newValue): string {
    return '<div class="change-grid">'
        . '<div><span class="change-label">Before</span>' . audit_viewer_value($oldValue) . '</div>'
        . '<div><span class="change-label">After</span>' . audit_viewer_value($newValue) . '</div>'
        . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Score Audit</title>
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <script src="ui-enhancement.js?v=<?php echo filemtime(__DIR__ . '/ui-enhancement.js'); ?>" defer></script>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 16px; background: #f4f6f9; color: #202124; font-family: Arial, Helvetica, sans-serif; }
        main { max-width: 1400px; margin: 0 auto; }
        header, .actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        header { margin-bottom: 18px; padding: 20px 22px; border: 1px solid #dce1e7; border-radius: 14px; background: #fff; box-shadow: 0 8px 24px rgba(15,23,42,.06); }
        h1 { margin: 0; }
        header p { margin: 6px 0 0; color: #5f6368; }
        a { color: #107c41; font-weight: 700; }
        .card { padding: 18px; margin-bottom: 16px; background: #fff; border: 1px solid #dce1e7; border-radius: 12px; box-shadow: 0 6px 18px rgba(15,23,42,.04); }
        .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; align-items: end; }
        .filters > div:last-child { display: flex; align-items: flex-end; }
        label { display: block; margin-bottom: 5px; font-weight: 700; }
        input, select, button:not(.ui-theme-toggle):not(.ui-alert-button) { width: 100%; padding: 9px 10px; font: inherit; border: 1px solid #cfd8e3; border-radius: 8px; background: #fff; color: #202124; }
        button:not(.ui-theme-toggle):not(.ui-alert-button) { border: 1px solid #107c41; border-radius: 8px; background: #107c41; color: #fff; cursor: pointer; font-weight: 700; }
        .filters button { width: auto; min-width: 140px; justify-self: start; }
        button:not(.ui-theme-toggle):not(.ui-alert-button):hover { background: #0b5c30; }
        .notice { padding: 10px 12px; margin-bottom: 12px; border-radius: 8px; background: #fdecea; color: #7a1f16; border: 1px solid #f0c4c0; }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; min-width: 1040px; border-collapse: collapse; background: #fff; }
        th, td { padding: 11px 10px; border-bottom: 1px solid #e4e8ed; text-align: left; vertical-align: top; }
        th { position: sticky; top: 0; z-index: 1; background: #f1f4f6; color: #56616b; font-size: 12px; white-space: nowrap; }
        tbody tr:hover { background: #f8faf9; }
        td { font-size: 13px; }
        td.id, td.created, td.candidate, td.user, td.action { white-space: nowrap; }
        td.id, td.candidate { color: #5b6670; font-variant-numeric: tabular-nums; }
        td.user small, time small { display: block; margin-top: 3px; color: #77828c; font-size: 11px; }
        time { font-variant-numeric: tabular-nums; }
        .event-badge { display: inline-block; padding: 4px 8px; border-radius: 999px; background: #edf4ef; color: #24613a; font-size: 11px; font-weight: 700; }
        .change-grid { display: grid; grid-template-columns: minmax(150px, 1fr) minmax(150px, 1fr); gap: 12px; min-width: 400px; }
        .change-grid > div { min-width: 0; }
        .change-label { display: block; margin-bottom: 5px; color: #77828c; font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .value-preview { display: grid; gap: 3px; overflow-wrap: anywhere; }
        .value-preview strong { color: #48535c; font-weight: 600; }
        .muted { color: #89939b; }
        details { margin-top: 6px; }
        summary { color: #107c41; cursor: pointer; font-size: 11px; font-weight: 600; }
        dl { display: grid; grid-template-columns: minmax(90px, auto) minmax(0, 1fr); gap: 4px 8px; margin: 8px 0 0; overflow-wrap: anywhere; }
        dt { color: #6b747c; font-size: 11px; }
        dd { margin: 0; font-size: 11px; }
        .pagination { display: flex; justify-content: center; gap: 14px; align-items: center; margin-top: 16px; }
        html[data-theme="dark"] body { background-color: #121815; color: #f3f7f4; }
        html[data-theme="dark"] header { background: #18241d; border-color: #304038; color: #f3f7f4; box-shadow: 0 8px 24px rgba(0, 0, 0, .35); }
        html[data-theme="dark"] h1, html[data-theme="dark"] h2 { color: #f3f7f4; }
        html[data-theme="dark"] header p, html[data-theme="dark"] label, html[data-theme="dark"] .actions span { color: #b8c9be; }
        html[data-theme="dark"] .card { background: #18241d; border-color: #304038; }
        html[data-theme="dark"] .notice { background: #2a171b; border-color: #5c3036; color: #ffb4ab; }
        html[data-theme="dark"] .table-scroll { border-color: #304038; }
        html[data-theme="dark"] th { background: #213128; color: #c9d8cf; }
        html[data-theme="dark"] th, html[data-theme="dark"] td { border-color: #304038; color: #e8f0eb; }
        html[data-theme="dark"] tbody tr:hover { background: #213128; }
        html[data-theme="dark"] td.id, html[data-theme="dark"] td.candidate, html[data-theme="dark"] dt, html[data-theme="dark"] .muted, html[data-theme="dark"] .change-label, html[data-theme="dark"] td.user small, html[data-theme="dark"] time small { color: #b8c9be; }
        html[data-theme="dark"] .value-preview strong { color: #f3f7f4; }
        html[data-theme="dark"] .event-badge { background: #1f3d2b; color: #9fe0b5; }
        html[data-theme="dark"] input, html[data-theme="dark"] select { background: #243029; border-color: #3b4a42; color: #e8f0eb; }
        html[data-theme="dark"] details { border-color: #304038; }
        html[data-theme="dark"] summary { color: #e8f0eb; }
        html[data-theme="dark"] a { color: #6ee7a8; }
        @media (max-width: 600px) {
            body { padding: 16px 8px; }
            header { padding: 14px; }
            .card { padding: 12px; }
            .change-grid { min-width: 360px; grid-template-columns: minmax(130px, 1fr) minmax(130px, 1fr); gap: 8px; }
        }
    </style>
</head>
<body>
<main>
    <header>
        <div><h1>Audit History</h1><p>Review score changes and account activity, including who made them and when.</p></div>
        <nav><a href="dashboard.php">Dashboard</a> · <a href="results_2.php">Results</a> · <a href="manage_users.php">Manage Users</a></nav>
    </header>
    <section class="card">
        <form method="get" class="filters">
            <div><label for="username">Username</label><input id="username" name="username" maxlength="100" value="<?php echo audit_viewer_h($filters['username']); ?>"></div>
            <div><label for="action">Action</label><select id="action" name="action">
                <option value="">All actions</option>
                <?php foreach (AUDIT_VIEWER_ACTIONS as $action): ?>
                    <option value="<?php echo audit_viewer_h($action); ?>" <?php echo $filters['action'] === $action ? 'selected' : ''; ?>><?php echo audit_viewer_h($action); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div><label for="candidate_id">Candidate ID</label><input id="candidate_id" name="candidate_id" inputmode="numeric" value="<?php echo audit_viewer_h($filters['candidate_id']); ?>"></div>
            <div><label for="date_from">From</label><input type="date" id="date_from" name="date_from" value="<?php echo audit_viewer_h($filters['date_from']); ?>"></div>
            <div><label for="date_to">To</label><input type="date" id="date_to" name="date_to" value="<?php echo audit_viewer_h($filters['date_to']); ?>"></div>
            <div><button type="submit">Filter</button></div>
        </form>
    </section>
    <?php foreach ($errors as $error): ?><div class="notice"><?php echo audit_viewer_h($error); ?></div><?php endforeach; ?>
    <?php if (!$auditTableAvailable): ?>
        <div class="notice">Audit history has not been set up in the database yet. Ask the server administrator to run <code>php setup.php</code> from the application directory.</div>
    <?php elseif (!$errors): ?>
        <section class="card">
            <div class="actions"><span><?php echo $totalRows; ?> matching audit row(s)</span><a href="<?php echo audit_viewer_h($csvUrl); ?>">Export CSV</a></div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>ID</th><th>When</th><th>User / IP</th><th>Candidate</th><th>Event</th><th>Changes</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="id"><?php echo (int)$row['id']; ?></td>
                            <td class="created"><time datetime="<?php echo audit_viewer_h($row['created_at']); ?>"><?php echo audit_viewer_h(substr((string)$row['created_at'], 0, 10)); ?><small><?php echo audit_viewer_h(substr((string)$row['created_at'], 11)); ?></small></time></td>
                            <td class="user"><?php echo audit_viewer_h($row['username'] ?? 'Unknown'); ?><small><?php echo $row['user_id'] === null ? '' : 'User #' . (int)$row['user_id']; ?><?php echo !empty($row['ip']) ? ' · ' . audit_viewer_h($row['ip']) : ''; ?></small></td>
                            <td class="candidate"><?php echo (int)$row['candidate_id'] > 0 ? '#' . (int)$row['candidate_id'] : '—'; ?></td>
                            <td class="action"><span class="event-badge"><?php echo audit_viewer_h(ucwords(str_replace('_', ' ', (string)$row['action']))); ?></span></td>
                            <td><?php echo audit_viewer_change($row['old_value'], $row['new_value']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="6">No audit rows match these filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Audit pagination">
                    <?php if ($page > 1): ?><a href="<?php echo audit_viewer_h($pageUrl($page - 1)); ?>">Previous</a><?php endif; ?>
                    <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?><a href="<?php echo audit_viewer_h($pageUrl($page + 1)); ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>