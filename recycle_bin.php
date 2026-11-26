<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/Raters.php';

require_admin();
ensure_auth_schema($conn);
ensure_schema($conn);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function recycle_bin_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$notice = '';
$noticeType = 'success';
$pageSize = 50;
$search = trim((string)($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 100);
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $requestedPage !== false && $requestedPage > 0 ? $requestedPage : 1;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        http_response_code(403);
        $notice = 'Your session expired. Refresh the page and try again.';
        $noticeType = 'error';
    } else {
        $recoveryId = filter_var($_POST['recovery_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($recoveryId === false) {
            http_response_code(422);
            $notice = 'The selected recovery record is invalid.';
            $noticeType = 'error';
        } else {
            try {
                $restored = restore_candidate_from_recovery($conn, (int)$recoveryId);
                $notice = $restored['candidate_name'] . ' was restored to ' . ($restored['office_name'] ?: 'Regional Office') . '.';
            } catch (RuntimeException $e) {
                $notice = $e->getMessage();
                $noticeType = 'error';
                http_response_code(409);
            } catch (Throwable $e) {
                error_log('recycle_bin restore error: ' . $e->getMessage());
                $notice = score_audit_schema_error($e)
                    ?? 'The applicant could not be restored. The recovery copy is still in the recycle bin.';
                $noticeType = 'error';
                http_response_code(500);
            }
        }
    }
}

$deletedCandidates = [];
$recoveryUnavailable = false;
$totalCandidates = 0;
try {
    $count = $conn->prepare("SELECT COUNT(*) AS total FROM deleted_candidates
        WHERE (? = '' OR candidate_name LIKE CONCAT('%', ?, '%')
            OR office_name LIKE CONCAT('%', ?, '%')
            OR position_title LIKE CONCAT('%', ?, '%'))");
    $count->bind_param('ssss', $search, $search, $search, $search);
    $count->execute();
    $totalCandidates = (int)($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();

    $pageCount = max(1, (int)ceil($totalCandidates / $pageSize));
    $page = min($page, $pageCount);
    $offset = ($page - 1) * $pageSize;
    $stmt = $conn->prepare("SELECT id, original_candidate_id, candidate_name, office_name, office_type, position_title, deleted_at
        FROM deleted_candidates
        WHERE (? = '' OR candidate_name LIKE CONCAT('%', ?, '%')
            OR office_name LIKE CONCAT('%', ?, '%')
            OR position_title LIKE CONCAT('%', ?, '%'))
        ORDER BY deleted_at DESC, id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('ssssii', $search, $search, $search, $search, $pageSize, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $deletedCandidates[] = $row;
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    if ((int)$e->getCode() !== 1146 || stripos($e->getMessage(), 'deleted_candidates') === false) {
        throw $e;
    }
    error_log('recycle_bin schema not initialized: ' . $e->getMessage());
    $recoveryUnavailable = true;
    $notice = 'The recycle-bin database table has not been created yet. Ask the server administrator to run `php setup.php` from the application directory, then reload this page.';
    $noticeType = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Deleted Applicants</title>
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <script src="ui-enhancement.js?v=<?php echo filemtime(__DIR__ . '/ui-enhancement.js'); ?>" defer></script>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 28px clamp(16px, 3vw, 40px); background: #f2f5f2; color: #1c2420; font-family: "Public Sans", "Segoe UI", Arial, sans-serif; }
        main { width: min(100%, 1200px); margin: 0 auto; }
        header, .recycle-card { padding: clamp(20px, 3vw, 34px); border: 1px solid #e3eae5; border-radius: 18px; background: #fff; box-shadow: 0 8px 28px rgba(26,52,35,.045); }
        header { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 18px; }
        h1 { margin: 0 0 7px; font-size: clamp(25px, 4vw, 34px); letter-spacing: -.035em; }
        header p { margin: 0; color: #68746d; font-size: 14px; line-height: 1.5; }
        .actions { display: flex; flex-wrap: wrap; gap: 9px; }
        .actions a, .restore-button { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; padding: 0 14px; border: 1px solid #d8e2db; border-radius: 9px; background: #fff; color: #1c5d36; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .restore-button { border-color: #107c41; background: #107c41; color: #fff; }
        .notice { margin: 0 0 16px; padding: 12px 15px; border-radius: 9px; background: #eaf5ed; color: #155d32; font-size: 14px; }
        .notice.error { background: #fff0ef; color: #9c2720; }
        .recycle-card h2 { margin: 0 0 4px; font-size: 19px; }
        .recycle-card > p { margin: 0 0 18px; color: #68746d; font-size: 13px; line-height: 1.5; }
        .recovery-search { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 18px; }
        .recovery-search label { flex-basis: 100%; color: #536159; font-size: 13px; font-weight: 650; }
        .recovery-search input { min-width: min(100%, 280px); min-height: 40px; padding: 8px 10px; border: 1px solid #cbd8ce; border-radius: 8px; font: inherit; }
        .recovery-search button, .recovery-search a, .pagination a { display: inline-flex; min-height: 40px; align-items: center; padding: 0 13px; border: 1px solid #b6d6c1; border-radius: 8px; background: #f4fbf6; color: #14532d; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .pagination { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 14px; margin-top: 18px; color: #536159; font-size: 13px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px 10px; border-bottom: 1px solid #e8ede9; font-size: 13px; vertical-align: middle; }
        th { color: #68746d; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; }
        td small { display: block; margin-top: 3px; color: #68746d; }
        .empty { padding: 32px 10px; color: #68746d; text-align: center; }
        @media (max-width: 700px) {
            header { align-items: flex-start; flex-direction: column; }
            th, td { padding: 10px 8px; }
        }
        html[data-theme="dark"] body {
            background: #121815 !important;
            background-image:
                radial-gradient(ellipse 70% 45% at 10% -10%, rgba(34, 197, 94, 0.12), transparent 55%),
                radial-gradient(ellipse 55% 40% at 100% 0%, rgba(16, 185, 129, 0.08), transparent 50%),
                linear-gradient(180deg, #131a16 0%, #121815 100%) !important;
            color: #f3f7f4 !important;
        }
        html[data-theme="dark"] header,
        html[data-theme="dark"] .recycle-card { background: #18241d; border-color: #304038; box-shadow: 0 8px 28px rgba(0,0,0,.28); }
        html[data-theme="dark"] h1, html[data-theme="dark"] .recycle-card h2 { color: #f3f7f4; }
        html[data-theme="dark"] header p, html[data-theme="dark"] .recycle-card > p, html[data-theme="dark"] .recovery-search label, html[data-theme="dark"] .pagination, html[data-theme="dark"] td small, html[data-theme="dark"] .empty { color: #b8c9be; }
        html[data-theme="dark"] .actions a { background: #243029; border-color: #3b4a42; color: #e8f0eb; }
        html[data-theme="dark"] .actions a:hover { background: #2d3d34; border-color: #4a6a56; }
        html[data-theme="dark"] .restore-button { background: #17653b; border-color: #25834b; color: #fff; }
        html[data-theme="dark"] .restore-button:hover { background: #1f7a48; }
        html[data-theme="dark"] .notice { background: #14261b; color: #9fe0b5; }
        html[data-theme="dark"] .notice.error { background: #2a171b; color: #ffb4ab; }
        html[data-theme="dark"] .recovery-search input { background: #243029; border-color: #3b4a42; color: #e8f0eb; }
        html[data-theme="dark"] .recovery-search button,
        html[data-theme="dark"] .recovery-search a,
        html[data-theme="dark"] .pagination a { background: #243029; border-color: #3b4a42; color: #9fe0b5; }
        html[data-theme="dark"] .recovery-search button:hover,
        html[data-theme="dark"] .recovery-search a:hover,
        html[data-theme="dark"] .pagination a:hover { background: #2d3d34; border-color: #4a6a56; }
        html[data-theme="dark"] th { color: #c9d8cf; border-bottom-color: #304038; }
        html[data-theme="dark"] td { color: #e8f0eb; border-bottom-color: #304038; }
        html[data-theme="dark"] table tbody tr:hover { background: #213128; }
        html[data-theme="dark"] .table-wrap { border-color: #304038; }
        html[data-theme="dark"] a { color: #6ee7a8; }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>Deleted Applicants</h1>
            <p>Deleted applicant records and scores are kept here until restored. Restoring puts the applicant back into the original office and account.</p>
        </div>
        <nav class="actions" aria-label="Results navigation">
            <a href="results_2.php">Back to Results</a>
        </nav>
    </header>

    <?php if ($notice !== ''): ?>
        <div class="notice <?php echo $noticeType === 'error' ? 'error' : ''; ?>" role="status"><?php echo recycle_bin_h($notice); ?></div>
    <?php endif; ?>

    <section class="recycle-card">
        <h2>Recovery bin</h2>
        <p>Showing <?php echo count($deletedCandidates); ?> of <?php echo $totalCandidates; ?> matching record(s). Restoring does not overwrite an existing applicant record.</p>
        <form class="recovery-search" method="get" role="search" aria-label="Search deleted applicants">
            <label for="recoverySearch">Search applicant, office, or position</label>
            <input id="recoverySearch" name="q" type="search" maxlength="100" value="<?php echo recycle_bin_h($search); ?>">
            <button type="submit">Search</button>
            <?php if ($search !== ''): ?><a href="recycle_bin.php">Clear search</a><?php endif; ?>
        </form>
        <?php if ($recoveryUnavailable): ?>
            <div class="empty">Recovery is temporarily unavailable until the database setup is run.</div>
        <?php elseif ($totalCandidates === 0): ?>
            <div class="empty">There are no deleted applicants to restore.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>Applicant</th><th>Office / Account</th><th>Position</th><th>Deleted</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deletedCandidates as $candidate): ?>
                        <tr>
                            <td>
                                <?php echo recycle_bin_h($candidate['candidate_name'] ?: 'Unnamed applicant'); ?>
                                <small>Record #<?php echo (int)$candidate['original_candidate_id']; ?></small>
                            </td>
                            <td>
                                <?php echo recycle_bin_h($candidate['office_name'] ?: 'Regional Office'); ?>
                                <small><?php echo $candidate['office_type'] === 'FO' ? 'Field' : ($candidate['office_type'] === 'RO' ? 'Region' : 'Account not recorded'); ?></small>
                            </td>
                            <td><?php echo recycle_bin_h($candidate['position_title'] ?: 'Unassigned'); ?></td>
                            <td><?php echo recycle_bin_h($candidate['deleted_at']); ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo recycle_bin_h(csrf_token()); ?>">
                                    <input type="hidden" name="recovery_id" value="<?php echo (int)$candidate['id']; ?>">
                                    <button class="restore-button" type="submit">Restore</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pageCount > 1): ?>
                <nav class="pagination" aria-label="Deleted applicant pages">
                    <?php if ($page > 1): ?>
                        <a href="?q=<?php echo rawurlencode($search); ?>&amp;page=<?php echo $page - 1; ?>">Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $page; ?> of <?php echo $pageCount; ?></span>
                    <?php if ($page < $pageCount): ?>
                        <a href="?q=<?php echo rawurlencode($search); ?>&amp;page=<?php echo $page + 1; ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
