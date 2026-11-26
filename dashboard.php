<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/SheetsSchema.php';
require_once __DIR__ . '/AccessGroups.php';

require_role(['admin', 'rater']);
ensure_auth_schema($conn);
ensure_schema($conn);
ensure_competency_template_schema($conn);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function dashboard_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$role = (string)current_role();
$username = (string)($_SESSION['username'] ?? '');
$stats = [];
$profile = null;
$profileType = null;
$lastBackup = null;
$backupStatusAvailable = true;
$backupIsStale = false;
$auditActivityAvailable = true;
$activityDays = [];
$recentActivity = [];
$activityTotal = 0;
$activityPeak = 1;

if ($role === 'admin') {
    $result = $conn->query("SELECT
        (SELECT COUNT(*) FROM candidates) AS candidate_count,
        (SELECT COUNT(*) FROM users WHERE role = 'rater') AS rater_count,
        (SELECT COUNT(*) FROM competency_templates WHERE is_shared = 1) AS shared_template_count");
    $stats = $result->fetch_assoc() ?: [];
    try {
        $backupResult = $conn->query('SELECT filename, size_bytes, completed_at FROM database_backups ORDER BY completed_at DESC, id DESC LIMIT 1');
        $lastBackup = $backupResult->fetch_assoc() ?: null;
        if ($lastBackup) {
            $backupTimestamp = strtotime((string)$lastBackup['completed_at']);
            $backupIsStale = $backupTimestamp === false || $backupTimestamp < time() - (7 * 86400);
        }
    } catch (mysqli_sql_exception $exception) {
        if ((int)$exception->getCode() !== 1146 || stripos($exception->getMessage(), 'database_backups') === false) {
            throw $exception;
        }
        error_log('dashboard backup status schema not initialized: ' . $exception->getMessage());
        $backupStatusAvailable = false;
    }
    try {
        $activityResult = $conn->query("SELECT DATE(created_at) AS activity_day, COUNT(*) AS activity_count
            FROM score_audit
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(created_at)
            ORDER BY activity_day ASC");
        $activityByDay = [];
        while ($activityRow = $activityResult->fetch_assoc()) {
            $activityByDay[(string)$activityRow['activity_day']] = (int)$activityRow['activity_count'];
        }
        $activityStmt = $conn->prepare('SELECT `action`, username, candidate_id, created_at
            FROM score_audit ORDER BY created_at DESC, id DESC LIMIT 5');
        $activityStmt->execute();
        $recentActivity = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $activityStmt->close();

        for ($daysBack = 6; $daysBack >= 0; $daysBack--) {
            $day = date('Y-m-d', strtotime("-{$daysBack} days"));
            $activityDays[] = [
                'date' => $day,
                'label' => date('D', strtotime($day)),
                'count' => $activityByDay[$day] ?? 0,
            ];
        }
        $activityTotal = array_sum(array_column($activityDays, 'count'));
        $activityPeak = max(1, ...array_column($activityDays, 'count'));
    } catch (mysqli_sql_exception $exception) {
        if ((int)$exception->getCode() !== 1146 || stripos($exception->getMessage(), 'score_audit') === false) {
            throw $exception;
        }
        error_log('dashboard activity schema not initialized: ' . $exception->getMessage());
        $auditActivityAvailable = false;
    }
} else {
    $profile = find_user_access_profile($conn, (int)current_user_id());
    $profileType = account_type_for_profile($profile);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard</title>
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <script src="ui-enhancement.js?v=<?php echo filemtime(__DIR__ . '/ui-enhancement.js'); ?>" defer></script>
    <style>
        * { box-sizing: border-box; }
        :root { --dash-green: #107c41; --dash-ink: #1c2420; --dash-muted: #68746d; --dash-line: #e0e7e2; }
        body { margin: 0; min-height: 100vh; padding: clamp(16px, 2.5vh, 30px) clamp(16px, 3vw, 44px); background: #f2f5f2; color: var(--dash-ink); font-family: "Public Sans", "Segoe UI", Arial, sans-serif; }
        main { display: flex; width: min(100%, 1560px); min-height: calc(100vh - clamp(32px, 5vh, 60px)); flex-direction: column; gap: 20px; margin: 0 auto; }
        .dashboard-admin, .dashboard-rater { --dash-surface: #fff; --dash-soft: #f5f8f6; }
        .dashboard-hero { position: relative; display: flex; min-height: 208px; align-items: center; justify-content: space-between; gap: 28px; overflow: hidden; padding: clamp(26px, 4vw, 48px); border-radius: 24px; background: linear-gradient(118deg, #0b5c30 0%, #107c41 58%, #239556 100%); color: #fff; box-shadow: 0 18px 44px rgba(17, 91, 48, .18); }
        .dashboard-hero::before, .dashboard-hero::after { position: absolute; border: 1px solid rgba(255,255,255,.11); border-radius: 50%; content: ""; pointer-events: none; }
        .dashboard-hero::before { width: 410px; height: 410px; top: -245px; right: 12%; }
        .dashboard-hero::after { width: 300px; height: 300px; right: -70px; bottom: -218px; }
        .hero-copy, .hero-actions { position: relative; z-index: 1; }
        .kicker { display: inline-flex; align-items: center; gap: 8px; margin: 0 0 14px; color: #c5eed3; font-size: 11px; font-weight: 750; letter-spacing: .14em; text-transform: uppercase; }
        .kicker::before { width: 7px; height: 7px; border-radius: 50%; background: #92e4ad; box-shadow: 0 0 0 4px rgba(146,228,173,.14); content: ""; }
        h1 { margin: 0 0 9px; color: #fff; font-size: clamp(30px, 4.5vw, 48px); font-weight: 700; letter-spacing: -.045em; line-height: 1.08; }
        .intro { max-width: 580px; margin: 0; color: rgba(255,255,255,.8); font-size: 14px; line-height: 1.6; }
        .hero-actions { display: flex; flex: 0 0 auto; flex-wrap: wrap; align-items: center; gap: 10px; }
        .hero-actions form { margin: 0; }
        .hero-actions a,
        .hero-actions button { display: inline-flex; min-height: 46px; align-items: center; justify-content: center; gap: 9px; padding: 0 17px; border: 1px solid rgba(255,255,255,.78); border-radius: 10px; background: #fff; color: #145d35; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; box-shadow: 0 4px 12px rgba(0,0,0,.12); cursor: pointer; transition: background .16s ease, transform .16s ease, box-shadow .16s ease; }
        .hero-actions a:hover,
        .hero-actions button:hover { transform: translateY(-1px); background: #f1f8f3; box-shadow: 0 7px 16px rgba(0,0,0,.16); }
        .hero-actions a:focus-visible,
        .hero-actions button:focus-visible { outline: 3px solid rgba(255,255,255,.55); outline-offset: 3px; }
        .hero-actions .hero-primary { border-color: #fff; background: #fff; color: #0b5c30; }
        .hero-actions .hero-primary:hover { background: #f1f8f3; }
        .hero-actions svg { width: 17px; height: 17px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; }
        .stat-card { position: relative; display: grid; min-height: 132px; grid-template-columns: 48px minmax(0, 1fr); align-content: center; align-items: center; gap: 5px 16px; padding: 20px 23px; overflow: hidden; border: 1px solid #e3eae5; border-radius: 16px; background: var(--dash-surface); box-shadow: 0 6px 20px rgba(26,52,35,.035); }
        .stat-card::after { position: absolute; width: 94px; height: 94px; top: -52px; right: -44px; border-radius: 50%; background: #f0f7f2; content: ""; }
        .stat-icon { z-index: 1; display: grid; width: 46px; height: 46px; grid-row: span 2; place-items: center; border-radius: 13px; background: #eaf5ed; color: var(--dash-green); }
        .stat-icon svg { width: 21px; height: 21px; }
        .stat-label { z-index: 1; align-self: end; color: var(--dash-muted); font-size: 12px; font-weight: 650; }
        .stat-value { z-index: 1; align-self: start; color: var(--dash-green); font-size: clamp(24px, 2.4vw, 32px); font-weight: 750; letter-spacing: -.04em; line-height: 1.2; overflow-wrap: anywhere; }
        .section-card { display: flex; flex: 1; flex-direction: column; min-height: 350px; padding: clamp(21px, 2.5vw, 32px); border: 1px solid #e3eae5; border-radius: 20px; background: var(--dash-surface); box-shadow: 0 8px 28px rgba(26,52,35,.045); }
        .section-heading { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
        .section-heading h2 { margin: 0 0 5px; font-size: 20px; letter-spacing: -.025em; }
        .section-intro { margin: 0; color: var(--dash-muted); font-size: 13px; line-height: 1.5; }
        .section-count { display: inline-flex; flex: 0 0 auto; align-items: center; padding: 6px 10px; border: 1px solid #e1ebe4; border-radius: 99px; background: #f7faf8; color: #53665a; font-size: 11px; font-weight: 700; }
        .actions { display: grid; flex: 1; grid-template-columns: repeat(3, minmax(0, 1fr)); grid-auto-rows: minmax(150px, 1fr); gap: 14px; }
        .dashboard-rater .actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .dashboard-admin .action-card:last-child:nth-child(3n + 2) { grid-column: span 2; }
        .action-card { position: relative; display: flex; min-height: 150px; flex-direction: column; align-items: flex-start; justify-content: space-between; gap: 18px; padding: clamp(18px, 2vw, 25px); overflow: hidden; border: 1px solid #e5ebe7; border-radius: 15px; background: var(--dash-surface); color: inherit; text-decoration: none; transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease, background .18s ease; }
        .action-card:hover { transform: translateY(-3px); border-color: #b6d6c1; background: #fbfefc; box-shadow: 0 12px 25px rgba(27,75,43,.09); }
        .action-card:focus-visible { outline: 3px solid rgba(16,124,65,.28); outline-offset: 3px; }
        .action-top { display: flex; width: 100%; align-items: flex-start; justify-content: space-between; gap: 14px; }
        .action-icon { display: grid; width: 42px; height: 42px; flex: 0 0 42px; place-items: center; border: 1px solid #e1eee5; border-radius: 12px; background: #f0f7f2; color: var(--dash-green); }
        .action-icon svg { width: 20px; height: 20px; }
        .action-arrow { display: grid; width: 30px; height: 30px; place-items: center; border: 1px solid #e5ebe7; border-radius: 50%; color: #637168; font-size: 17px; line-height: 1; transition: color .18s ease, background .18s ease, border-color .18s ease, transform .18s ease; }
        .action-card:hover .action-arrow { transform: translateX(2px); border-color: #c9e2d1; background: #eaf5ed; color: var(--dash-green); }
        .action-copy strong { display: block; margin-bottom: 5px; color: var(--dash-ink); font-size: 15px; font-weight: 700; }
        .action-copy span { display: block; color: var(--dash-muted); font-size: 12px; line-height: 1.55; }
        .notice { padding: 15px 17px; border: 1px solid #f0dca0; border-radius: 12px; background: #fff8e6; color: #705600; font-size: 13px; line-height: 1.5; }
        .backup-status { margin-top: -4px; }
        .backup-status strong { color: #234b32; }
        .backup-status p { margin: 5px 0 0; }
        html[data-theme="dark"] body { background: #121815; color: #f3f7f4; }
        html[data-theme="dark"] .dashboard-hero { background: linear-gradient(118deg, #0f2f20, #145232 60%, #1a6b3f); }
        html[data-theme="dark"] .hero-actions a,
        html[data-theme="dark"] .hero-actions button { border-color: rgba(255,255,255,.3); background: rgba(255,255,255,.1); color: #fff; }
        html[data-theme="dark"] .hero-actions a:hover,
        html[data-theme="dark"] .hero-actions button:hover { background: rgba(255,255,255,.18); }
        html[data-theme="dark"] .stat-card, html[data-theme="dark"] .section-card, html[data-theme="dark"] .action-card { border-color: #304038; background: #18241d; }
        html[data-theme="dark"] .stat-label, html[data-theme="dark"] .section-intro, html[data-theme="dark"] .action-copy span { color: #b8c9be; }
        html[data-theme="dark"] .stat-value, html[data-theme="dark"] .section-heading h2, html[data-theme="dark"] .action-copy strong { color: #f3f7f4; }
        html[data-theme="dark"] .stat-card::after { background: #1f3d2b; }
        html[data-theme="dark"] .stat-icon, html[data-theme="dark"] .action-icon { border-color: #2f4a38; background: #1a3324; color: #6ee7a8; }
        html[data-theme="dark"] .section-count { border-color: #304038; background: #213128; color: #c9d8cf; }
        html[data-theme="dark"] .action-card:hover { border-color: #3f5f4a; background: #1d2c24; }
        html[data-theme="dark"] .action-arrow { border-color: #304038; color: #b8c9be; }
        html[data-theme="dark"] .action-card:hover .action-arrow { border-color: #2f4a38; background: #1a3324; color: #9fe0b5; }
        html[data-theme="dark"] .notice { border-color: #5a4a1f; background: #2a2414; color: #f6d365; }
        html[data-theme="dark"] .backup-status strong { color: #9fe0b5; }
        @media (max-width: 760px) {
            body { padding: 20px 15px; }
            main { min-height: calc(100vh - 40px); gap: 14px; }
            .dashboard-hero { min-height: 190px; align-items: flex-start; flex-direction: column; justify-content: center; gap: 22px; padding: 27px; border-radius: 20px; }
            .hero-actions { width: 100%; }
            .stats { gap: 10px; }
            .stat-card { min-height: 112px; grid-template-columns: 40px minmax(0, 1fr); gap: 5px 11px; padding: 15px; }
            .stat-icon { width: 38px; height: 38px; border-radius: 11px; }
            .stat-icon svg { width: 18px; height: 18px; }
            .stat-value { font-size: 24px; }
            .section-card { min-height: auto; padding: 20px; }
            .actions, .dashboard-rater .actions { grid-template-columns: repeat(2, minmax(0, 1fr)); grid-auto-rows: minmax(138px, auto); }
        }
        @media (max-width: 480px) {
            body { padding: 12px; }
            main { min-height: auto; gap: 12px; }
            .dashboard-hero { min-height: 0; gap: 20px; padding: 24px 20px; border-radius: 17px; }
            h1 { font-size: 31px; }
            .intro { font-size: 13px; }
            .hero-actions a,
            .hero-actions button { flex: 1 1 auto; min-height: 43px; padding: 0 12px; }
            .stats { grid-template-columns: 1fr; gap: 8px; }
            .stat-card { min-height: 78px; grid-template-columns: 38px minmax(0, 1fr); }
            .stat-label { align-self: end; }
            .stat-value { font-size: 22px; }
            .section-card { padding: 17px; border-radius: 16px; }
            .section-heading { align-items: flex-start; flex-direction: column; gap: 8px; margin-bottom: 15px; }
            .actions, .dashboard-rater .actions { grid-template-columns: 1fr; grid-auto-rows: auto; gap: 9px; }
            .dashboard-admin .action-card:last-child:nth-child(3n + 2) { grid-column: auto; }
            .action-card { min-height: 112px; flex-direction: row; align-items: center; justify-content: flex-start; gap: 13px; padding: 15px; }
            .action-top { width: auto; flex: 0 0 auto; align-items: center; gap: 0; }
            .action-arrow { display: none; }
            .action-copy strong { font-size: 14px; }
            .action-copy span { font-size: 12px; }
        }
        body { background: radial-gradient(ellipse at 82% 4%, rgba(35,149,86,.075), transparent 28%), #f2f5f2; }
        .dashboard-hero {
            min-height: 252px;
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 26px;
            background:
                radial-gradient(ellipse at 82% 16%, rgba(127,232,169,.2), transparent 30%),
                radial-gradient(ellipse at 96% 120%, rgba(72,191,119,.25), transparent 38%),
                linear-gradient(118deg, #0b2117 0%, #103c25 48%, #126438 100%);
            box-shadow: 0 24px 60px rgba(13,61,34,.2), inset 0 1px 0 rgba(255,255,255,.12);
        }
        .dashboard-hero::before {
            width: 520px;
            height: 520px;
            top: -350px;
            right: 3%;
            border-color: rgba(209,255,224,.13);
            box-shadow: 0 0 0 38px rgba(209,255,224,.025), 0 0 0 76px rgba(209,255,224,.02);
        }
        .dashboard-hero::after {
            width: 260px;
            height: 260px;
            right: 34%;
            bottom: -222px;
            border-color: rgba(209,255,224,.12);
        }
        .kicker { color: #b6e9c7; letter-spacing: .16em; }
        .kicker::before { background: #8df0ad; box-shadow: 0 0 0 5px rgba(141,240,173,.14), 0 0 18px rgba(141,240,173,.55); }
        .dashboard-hero h1 { font-size: clamp(34px, 4.7vw, 54px); letter-spacing: -.055em; }
        .hero-date { display: inline-flex; align-items: center; gap: 8px; margin-top: 20px; color: rgba(237,255,243,.74); font-size: 12px; font-weight: 600; letter-spacing: .015em; }
        .hero-date svg { width: 15px; height: 15px; opacity: .8; }
        .hero-art { position: absolute; z-index: 0; top: 50%; right: 26%; width: 224px; height: 224px; transform: translateY(-50%); opacity: .78; pointer-events: none; }
        .hero-art-orbit { position: absolute; inset: 9px; border: 1px solid rgba(212,255,226,.18); border-radius: 50%; }
        .hero-art-orbit::before, .hero-art-orbit::after { position: absolute; inset: 19px; border: 1px dashed rgba(212,255,226,.2); border-radius: 50%; content: ""; }
        .hero-art-orbit::before { animation: dashboard-orbit 42s linear infinite; }
        .hero-art-orbit::after { inset: 43px; border-style: solid; border-color: rgba(212,255,226,.12); }
        .hero-art-core { position: absolute; inset: 70px; display: grid; place-items: center; border: 1px solid rgba(228,255,237,.25); border-radius: 50%; background: radial-gradient(circle at 34% 28%, rgba(185,255,207,.24), rgba(20,103,58,.38) 68%); box-shadow: 0 0 48px rgba(107,233,149,.14), inset 0 0 24px rgba(226,255,236,.08); }
        .hero-art-core svg { width: 34px; height: 34px; color: #c5f6d2; }
        .hero-art-dot { position: absolute; top: 30px; right: 36px; width: 10px; height: 10px; border: 2px solid #c9f8d5; border-radius: 50%; background: #51d27e; box-shadow: 0 0 18px rgba(81,210,126,.7); animation: dashboard-pulse 3.2s ease-in-out infinite; }
        .hero-art-caption { position: absolute; right: -30px; bottom: 3px; color: rgba(220,255,232,.55); font-size: 8px; font-weight: 700; letter-spacing: .19em; white-space: nowrap; }
        .stats { gap: 14px; }
        .stat-card { min-height: 124px; border-color: #e0e8e2; border-radius: 17px; box-shadow: 0 9px 24px rgba(23,51,33,.045), inset 0 1px 0 #fff; transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease; }
        .stat-card:hover { transform: translateY(-2px); border-color: #c8ddce; box-shadow: 0 14px 28px rgba(23,51,33,.08), inset 0 1px 0 #fff; }
        .stat-icon { border: 1px solid #dcebe0; background: linear-gradient(145deg, #f1f8f3, #e6f3ea); }
        .stat-label { font-size: 11px; letter-spacing: .055em; text-transform: uppercase; }
        .stat-value { color: #17653a; font-size: clamp(27px, 2.8vw, 35px); font-variant-numeric: tabular-nums; }
        .section-card { border-color: #e0e8e2; border-radius: 21px; box-shadow: 0 10px 32px rgba(23,51,33,.045), inset 0 1px 0 #fff; }
        .section-heading { align-items: center; padding-bottom: 17px; border-bottom: 1px solid #edf1ee; }
        .section-heading h2 { font-size: 21px; letter-spacing: -.035em; }
        .section-count { border-color: #deebe2; background: #f0f7f2; color: #286341; }
        .action-card { min-height: 160px; border-color: #e3ebe5; border-radius: 16px; box-shadow: 0 2px 6px rgba(25,57,36,.02); transform-style: preserve-3d; }
        .action-card::before { position: absolute; z-index: 0; inset: 0; border-radius: inherit; background: radial-gradient(300px circle at var(--spot-x,50%) var(--spot-y,50%),rgba(46,156,83,.12),transparent 72%); content: ""; opacity: 0; pointer-events: none; transition: opacity .22s ease; }
        .action-card > * { position: relative; z-index: 1; }
        .action-card:hover { transform: perspective(900px) rotateX(var(--tilt-x,0deg)) rotateY(var(--tilt-y,0deg)) translateY(-4px); border-color: #b7d7c1; background: linear-gradient(145deg,#fff,#f8fcf9); box-shadow: 0 18px 34px rgba(27,75,43,.1); }
        .action-card:hover::before { opacity: 1; }
        .action-icon { border-color: #dcebe0; background: linear-gradient(145deg, #f2f8f4, #e8f4eb); }
        .action-copy strong { letter-spacing: -.015em; }
        .backup-status { padding: 13px 17px; border-color: #e1e9e3; border-radius: 12px; background: rgba(255,255,255,.76); color: #56665c; box-shadow: 0 5px 16px rgba(23,51,33,.025); }
        .backup-status strong { color: #245c39; }
        .activity-panel { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(320px, .85fr); overflow: hidden; border: 1px solid #e0e8e2; border-radius: 21px; background: #fff; box-shadow: 0 10px 32px rgba(23,51,33,.045), inset 0 1px 0 #fff; }
        .activity-heading { display: flex; grid-column: 1 / -1; align-items: center; justify-content: space-between; gap: 20px; padding: 23px 27px 19px; border-bottom: 1px solid #edf1ee; }
        .activity-kicker { margin: 0 0 5px; color: #43835a; font-size: 10px; font-weight: 750; letter-spacing: .14em; text-transform: uppercase; }
        .activity-heading h2 { margin: 0 0 4px; color: #202a35; font-size: 19px; letter-spacing: -.03em; }
        .activity-heading > div:first-child > p:last-child { margin: 0; color: #78857d; font-size: 12px; }
        .activity-total { display: flex; flex: 0 0 auto; align-items: baseline; gap: 8px; }
        .activity-total strong { color: #17653a; font-size: 30px; font-variant-numeric: tabular-nums; letter-spacing: -.05em; }
        .activity-total span { color: #758279; font-size: 11px; }
        .activity-chart { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: clamp(8px, 1.8vw, 18px); min-height: 194px; margin: 0; padding: 21px 24px 18px; }
        .activity-column { display: grid; min-width: 0; grid-template-rows: 18px minmax(96px, 1fr) 17px; gap: 8px; text-align: center; }
        .activity-count { color: #617269; font-size: 10px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .activity-track { display: flex; min-height: 96px; align-items: flex-end; justify-content: center; overflow: hidden; border-radius: 7px; background: repeating-linear-gradient(to top, transparent 0, transparent 31px, #edf2ee 32px, transparent 33px); }
        .activity-bar { width: min(100%, 24px); min-height: 3px; border-radius: 5px 5px 2px 2px; background: linear-gradient(180deg, #5fd18a, #188149); box-shadow: 0 0 16px rgba(45,166,91,.13); transform-origin: bottom; animation: dashboard-bar-rise .75s cubic-bezier(.2,.7,.2,1) both; animation-delay: calc(var(--bar-index, 0) * 55ms); }
        .activity-column time { color: #819087; font-size: 10px; font-weight: 650; }
        .activity-feed { padding: 20px 21px 18px; border-left: 1px solid #edf1ee; background: linear-gradient(145deg, #fbfdfb, #f7faf8); }
        .activity-feed-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
        .activity-feed-heading h3 { margin: 0; color: #354139; font-size: 12px; font-weight: 750; letter-spacing: .045em; text-transform: uppercase; }
        .activity-feed-heading a { color: #17653a; font-size: 11px; font-weight: 700; text-decoration: none; }
        .activity-feed-heading a:hover { text-decoration: underline; }
        .activity-feed ul { display: grid; gap: 0; margin: 0; padding: 0; list-style: none; }
        .activity-item { display: grid; grid-template-columns: 9px minmax(0, 1fr) auto; align-items: center; gap: 10px; min-height: 49px; border-top: 1px solid #e9efea; }
        .activity-marker { width: 7px; height: 7px; border-radius: 50%; background: #51bd78; box-shadow: 0 0 0 3px rgba(81,189,120,.12); }
        .activity-marker-edit { background: #5b9fd1; box-shadow: 0 0 0 3px rgba(91,159,209,.13); }
        .activity-marker-delete { background: #db776e; box-shadow: 0 0 0 3px rgba(219,119,110,.13); }
        .activity-marker-restore { background: #ad89d8; box-shadow: 0 0 0 3px rgba(173,137,216,.14); }
        .activity-description { display: grid; min-width: 0; gap: 3px; }
        .activity-description strong { overflow: hidden; color: #344139; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
        .activity-description > span { overflow: hidden; color: #829087; font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }
        .activity-item time { color: #86928b; font-size: 9px; white-space: nowrap; }
        .activity-empty { grid-column: 1 / -1; margin: 0; padding: 20px 24px; color: #78857d; font-size: 12px; line-height: 1.6; }
        .activity-empty code { padding: 2px 5px; border-radius: 4px; background: #f0f5f1; color: #315d40; }
        html[data-theme="dark"] body { background: radial-gradient(ellipse at 84% 3%, rgba(23,105,61,.19), transparent 27%), #0e1411; }
        html[data-theme="dark"] .dashboard-hero {
            border-color: rgba(168,237,192,.15);
            background:
                radial-gradient(ellipse at 82% 16%, rgba(74,203,123,.14), transparent 30%),
                radial-gradient(ellipse at 96% 120%, rgba(39,146,83,.19), transparent 38%),
                linear-gradient(118deg, #101b15 0%, #112c1c 48%, #12462a 100%);
            box-shadow: 0 24px 60px rgba(0,0,0,.24), inset 0 1px 0 rgba(255,255,255,.07);
        }
        html[data-theme="dark"] .dashboard-hero::before { border-color: rgba(209,255,224,.1); }
        html[data-theme="dark"] .dashboard-hero::after { border-color: rgba(209,255,224,.09); }
        html[data-theme="dark"] .stat-card,
        html[data-theme="dark"] .section-card,
        html[data-theme="dark"] .action-card { border-color: #2b3930; box-shadow: 0 12px 30px rgba(0,0,0,.16), inset 0 1px 0 rgba(255,255,255,.025); }
        html[data-theme="dark"] .stat-card:hover,
        html[data-theme="dark"] .action-card:hover { border-color: #3b6047; box-shadow: 0 16px 36px rgba(0,0,0,.25), 0 0 0 1px rgba(104,217,143,.05); }
        html[data-theme="dark"] .action-card::before { background: radial-gradient(300px circle at var(--spot-x,50%) var(--spot-y,50%),rgba(100,224,142,.12),transparent 72%); }
        html[data-theme="dark"] .stat-icon,
        html[data-theme="dark"] .action-icon { border-color: #304b38; background: linear-gradient(145deg,#1c3023,#18271e); }
        html[data-theme="dark"] .stat-value { color: #a4e7b8; }
        html[data-theme="dark"] .section-heading { border-color: #29352e; }
        html[data-theme="dark"] .section-count { border-color: #304b38; background: #1b2b20; color: #b5dfc0; }
        html[data-theme="dark"] .action-card:hover { background: linear-gradient(145deg,#1c2a21,#19251e); }
        html[data-theme="dark"] .backup-status { border-color: #34443a; background: rgba(25,35,29,.82); color: #b9c9bf; }
        html[data-theme="dark"] .backup-status strong { color: #a4e7b8; }
        html[data-theme="dark"] .activity-panel { border-color: #2b3930; background: #171d1a; box-shadow: 0 12px 30px rgba(0,0,0,.16), inset 0 1px 0 rgba(255,255,255,.025); }
        html[data-theme="dark"] .activity-heading { border-color: #29352e; }
        html[data-theme="dark"] .activity-kicker { color: #91cfa3; }
        html[data-theme="dark"] .activity-heading h2,
        html[data-theme="dark"] .activity-feed-heading h3,
        html[data-theme="dark"] .activity-description strong { color: #e7f0e9; }
        html[data-theme="dark"] .activity-heading > div:first-child > p:last-child,
        html[data-theme="dark"] .activity-total span,
        html[data-theme="dark"] .activity-count,
        html[data-theme="dark"] .activity-column time,
        html[data-theme="dark"] .activity-item time,
        html[data-theme="dark"] .activity-description > span,
        html[data-theme="dark"] .activity-empty { color: #a1b1a7; }
        html[data-theme="dark"] .activity-total strong { color: #a4e7b8; }
        html[data-theme="dark"] .activity-track { background: repeating-linear-gradient(to top, transparent 0, transparent 31px, #29352e 32px, transparent 33px); }
        html[data-theme="dark"] .activity-bar { background: linear-gradient(180deg, #72e59a, #25844c); box-shadow: 0 0 18px rgba(72,203,123,.2); }
        html[data-theme="dark"] .activity-feed { border-color: #29352e; background: linear-gradient(145deg, #1a221d, #171d1a); }
        html[data-theme="dark"] .activity-feed-heading a { color: #9ee5b2; }
        html[data-theme="dark"] .activity-item { border-color: #29352e; }
        html[data-theme="dark"] .activity-empty code { background: #26332a; color: #b7e5c4; }
        @media (max-width: 760px) {
            .dashboard-hero { min-height: 220px; }
            .hero-art { right: 3%; opacity: .28; }
            .hero-copy, .hero-actions { z-index: 1; }
            .activity-panel { grid-template-columns: 1fr; }
            .activity-feed { border-top: 1px solid #edf1ee; border-left: 0; }
            html[data-theme="dark"] .activity-feed { border-top-color: #29352e; }
        }
        @media (max-width: 480px) {
            .dashboard-hero { min-height: 0; border-radius: 17px; }
            .hero-art { top: 28%; right: -28px; width: 170px; height: 170px; opacity: .2; }
            .hero-art-core { inset: 54px; }
            .hero-art-caption { display: none; }
            .hero-date { margin-top: 15px; }
            .section-heading { border-bottom: 0; padding-bottom: 0; }
            .activity-heading { align-items: flex-start; padding: 18px; }
            .activity-total { flex-direction: column; align-items: flex-end; gap: 0; }
            .activity-chart { gap: 7px; padding: 16px 13px; }
            .activity-feed { padding: 16px 14px; }
            .activity-item { grid-template-columns: 9px minmax(0, 1fr); gap: 9px; }
            .activity-item time { grid-column: 2; margin-top: -7px; padding-bottom: 7px; }
            .stat-card:hover, .action-card:hover { transform: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            .stat-card, .action-card { transition: none; }
            .stat-card:hover, .action-card:hover { transform: none; }
            .hero-art-orbit::before, .hero-art-dot { animation: none; }
            .activity-bar { transition: none; }
            .activity-bar { animation: none; }
            .action-card { transform: none !important; }
            .action-card::before { transition: none; }
        }
        @keyframes dashboard-orbit { to { transform: rotate(360deg); } }
        @keyframes dashboard-pulse { 0%, 100% { transform: scale(.88); opacity: .75; } 50% { transform: scale(1.12); opacity: 1; } }
        @keyframes dashboard-bar-rise { from { transform: scaleY(.02); opacity: .35; } to { transform: scaleY(1); opacity: 1; } }
    </style>
</head>
<body>
<main class="dashboard-<?php echo dashboard_h($role); ?>">
    <header class="dashboard-hero">
        <div class="hero-copy">
            <p class="kicker"><?php echo $role === 'admin' ? 'Administration' : 'Rater workspace'; ?></p>
            <h1>Welcome, <?php echo dashboard_h($username); ?></h1>
            <p class="intro"><?php echo $role === 'admin'
                ? 'Your overview and shortcuts for managing evaluations.'
                : 'Pick up where you left off or start a new evaluation.'; ?></p>
            <span class="hero-date">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M7.5 3.5v3M16.5 3.5v3M3.5 9.5h17"/></svg>
                <?php echo dashboard_h(date('l, F j')); ?>
            </span>
        </div>
        <div class="hero-art" aria-hidden="true">
            <span class="hero-art-orbit"></span>
            <span class="hero-art-core">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 18.5V12m5 6.5V7m5 11.5v-4m5 4V4"/><path d="m3 9 5-4 5 5 7-7"/><circle cx="20" cy="3" r="1"/></svg>
            </span>
            <span class="hero-art-dot"></span>
            <span class="hero-art-caption">PEOPLE · PERFORMANCE · PROGRESS</span>
        </div>
        <nav class="hero-actions" aria-label="Account navigation">
            <?php if ($role === 'admin'): ?>
                <a class="hero-primary" href="results_2.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 19V5m0 14h17M8 15l4-4 3 3 5-6"/></svg>
                    Open results
                </a>
            <?php endif; ?>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 17l5-5-5-5m5 5H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg>
                    Sign out
                </button>
            </form>
        </nav>
    </header>

    <?php if ($role === 'admin'): ?>
        <section class="stats" aria-label="System overview">
            <article class="stat-card">
                <span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg></span>
                <span class="stat-label">Interview records</span>
                <strong class="stat-value"><?php echo number_format((int)($stats['candidate_count'] ?? 0)); ?></strong>
            </article>
            <article class="stat-card">
                <span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0m2-13a3 3 0 0 1 0 6m1 2a4.5 4.5 0 0 1 3.5 4.4"/></svg></span>
                <span class="stat-label">Rater accounts</span>
                <strong class="stat-value"><?php echo number_format((int)($stats['rater_count'] ?? 0)); ?></strong>
            </article>
            <article class="stat-card">
                <span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 4.75A1.75 1.75 0 0 1 7.75 3H20v17H7.75A1.75 1.75 0 0 1 6 18.25z"/><path d="M6 17.5c0-1.1.9-2 2-2h12M10 7h6m-6 4h6"/></svg></span>
                <span class="stat-label">Shared templates</span>
                <strong class="stat-value"><?php echo number_format((int)($stats['shared_template_count'] ?? 0)); ?></strong>
            </article>
        </section>
        <aside class="notice backup-status<?php echo $backupIsStale ? ' is-stale' : ''; ?>" role="status">
            <strong>Database backup:</strong>
            <?php if (!$backupStatusAvailable): ?>
                Backup status is not set up yet. Run <code>php setup.php</code> on the application server.
            <?php elseif (!$lastBackup): ?>
                No successful backup has been recorded.
            <?php else: ?>
                Last successful backup: <?php echo dashboard_h(date('M j, Y · g:i A', strtotime((string)$lastBackup['completed_at']))); ?>
                · <?php echo number_format((int)$lastBackup['size_bytes']); ?> bytes
                <?php if ($backupIsStale): ?>
                    <p><strong>Backup is more than 7 days old. Run or check the scheduled backup job.</strong></p>
                <?php endif; ?>
            <?php endif; ?>
            <p>To automate daily backups, configure the server schedule using the instructions in README.</p>
        </aside>
        <section class="activity-panel" aria-labelledby="activity-heading">
            <header class="activity-heading">
                <div>
                    <p class="activity-kicker">Live system pulse</p>
                    <h2 id="activity-heading">Evaluation activity</h2>
                    <p>Audit events recorded over the last seven days.</p>
                </div>
                <div class="activity-total">
                    <strong><?php echo number_format($activityTotal); ?></strong>
                    <span>events this week</span>
                </div>
            </header>
            <?php if (!$auditActivityAvailable): ?>
                <p class="activity-empty" role="status">Activity history is not available yet. Run <code>php setup.php</code> to initialize audit tracking.</p>
            <?php else: ?>
                <figure class="activity-chart" aria-label="Audit activity by day during the last seven days">
                    <?php foreach ($activityDays as $activityIndex => $activityDay): ?>
                        <?php $activityHeight = $activityDay['count'] > 0 ? max(8, (int)round(100 * $activityDay['count'] / $activityPeak)) : 3; ?>
                        <div class="activity-column" role="img" aria-label="<?php echo dashboard_h($activityDay['label'] . ': ' . $activityDay['count'] . ' events'); ?>">
                            <span class="activity-count"><?php echo $activityDay['count'] > 0 ? number_format($activityDay['count']) : ''; ?></span>
                            <div class="activity-track" aria-hidden="true">
                                <span class="activity-bar" style="height: <?php echo $activityHeight; ?>%; --bar-index: <?php echo (int)$activityIndex; ?>"></span>
                            </div>
                            <time datetime="<?php echo dashboard_h($activityDay['date']); ?>"><?php echo dashboard_h($activityDay['label']); ?></time>
                        </div>
                    <?php endforeach; ?>
                </figure>
                <aside class="activity-feed" aria-label="Latest audit events">
                    <div class="activity-feed-heading">
                        <h3>Latest events</h3>
                        <a href="audit_viewer.php">Full audit log <span aria-hidden="true">↗</span></a>
                    </div>
                    <?php if (!$recentActivity): ?>
                        <p class="activity-empty">No activity recorded yet. New submissions and changes will appear here.</p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($recentActivity as $activity): ?>
                                <?php
                                $activityAction = (string)$activity['action'];
                                $activityActionClass = in_array($activityAction, ['submit', 'edit', 'delete', 'restore'], true)
                                    ? $activityAction
                                    : 'other';
                                $activityLabel = match ($activityAction) {
                                    'submit' => 'Evaluation submitted',
                                    'edit' => 'Evaluation updated',
                                    'delete' => 'Evaluation deleted',
                                    'restore' => 'Evaluation restored',
                                    default => 'Evaluation activity',
                                };
                                $activityTimestamp = strtotime((string)$activity['created_at']);
                                ?>
                                <li class="activity-item">
                                    <span class="activity-marker activity-marker-<?php echo $activityActionClass; ?>" aria-hidden="true"></span>
                                    <span class="activity-description">
                                        <strong><?php echo dashboard_h($activityLabel); ?></strong>
                                        <span><?php echo dashboard_h((string)($activity['username'] ?? '') ?: 'Unknown user'); ?><?php if ($activity['candidate_id'] !== null): ?> · Candidate #<?php echo (int)$activity['candidate_id']; ?><?php endif; ?></span>
                                    </span>
                                    <time datetime="<?php echo dashboard_h($activity['created_at']); ?>"><?php echo dashboard_h($activityTimestamp === false ? '' : date('M j, g:i A', $activityTimestamp)); ?></time>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </aside>
            <?php endif; ?>
        </section>
        <section class="section-card">
            <div class="section-heading">
                <div>
                    <h2>Workspace</h2>
                    <p class="section-intro">Your tools for running the evaluation process.</p>
                </div>
                <span class="section-count">6 tools</span>
            </div>
            <div class="actions">
                <a class="action-card" href="results_2.php">
                    <span class="action-top"><span class="action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V5m0 14h17M8 15l4-4 3 3 5-6"/></svg></span><span class="action-arrow" aria-hidden="true">↗</span></span>
                    <span class="action-copy"><strong>Results &amp; scores</strong><span>Review interview records and scoring progress.</span></span>
                </a>
                <a class="action-card" href="template_manager.php">
                    <span class="action-top"><span class="action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 4h10a2 2 0 0 1 2 2v15H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="M8 8h7M8 12h8M8 16h5M9 4V2h6v2"/></svg></span><span class="action-arrow" aria-hidden="true">↗</span></span>
                    <span class="action-copy"><strong>Competency templates</strong>Create, update, and share scoring forms.</span>
                </a>
                <a class="action-card" href="manage_users.php">
                    <span class="action-top"><span class="action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0m2-12h5m-2.5-2.5v5"/></svg></span><span class="action-arrow" aria-hidden="true">↗</span></span>
                    <span class="action-copy"><strong>User accounts</strong>Update roles, emails, and access profiles.</span>
                </a>
                <a class="action-card" href="bulk_users.php">
                    <span class="action-top"><span class="action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 16V4m-4 4 4-4 4 4"/><path d="M5 14v5a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-5"/></svg></span><span class="action-arrow" aria-hidden="true">↗</span></span>
                    <span class="action-copy"><strong>Bulk users</strong>Import or update multiple accounts at once.</span>
                </a>
                <a class="action-card" href="positions_import.php">
                    <span class="action-top"><span class="action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s7-5.3 7-11a7 7 0 1 0-14 0c0 5.7 7 11 7 11z"/><circle cx="12" cy="10" r="2.3"/></svg></span><span class="action-arrow" aria-hidden="true">↗</span></span>
                    <span class="action-copy"><strong>Position catalog</strong>Import and maintain positions available to raters.</span>
                </a>
                <a class="action-card" href="audit_viewer.php">
                    <span class="action-top"><span class="action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M5.5 5.5l2 2"/></svg></span><span class="action-arrow" aria-hidden="true">↗</span></span>
                    <span class="action-copy"><strong>Audit history</strong>See who submitted, changed, deleted, or restored applicant scores.</span>
                </a>
            </div>
        </section>
    <?php else: ?>
        <section class="stats" aria-label="Your access profile">
            <article class="stat-card">
                <span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7.5 12 3l8 4.5-8 4.5z"/><path d="m4 12 8 4.5 8-4.5M4 16.5l8 4.5 8-4.5"/></svg></span>
                <span class="stat-label">Account type</span>
                <strong class="stat-value"><?php echo dashboard_h($profileType !== null ? ACCESS_ACCOUNT_TYPES[$profileType] : 'Not set'); ?></strong>
            </article>
            <article class="stat-card">
                <span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V5m0 14h17M8 15l4-4 3 3 5-6"/></svg></span>
                <span class="stat-label">Salary grade</span>
                <strong class="stat-value"><?php echo $profile && $profile['salary_grade'] !== null ? 'SG ' . (int)$profile['salary_grade'] : 'Not set'; ?></strong>
            </article>
            <article class="stat-card">
                <span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s7-5.3 7-11a7 7 0 1 0-14 0c0 5.7 7 11 7 11z"/><circle cx="12" cy="10" r="2.3"/></svg></span>
                <span class="stat-label">Region</span>
                <strong class="stat-value"><?php echo dashboard_h(ACCESS_REGIONS[(string)($profile['region'] ?? '')] ?? 'Not set'); ?></strong>
            </article>
        </section>
        <?php if (!$profile): ?>
            <p class="notice">Your account profile is incomplete. Ask an administrator to review your access settings before starting an evaluation.</p>
        <?php endif; ?>
        <section class="section-card">
            <div class="section-heading">
                <div>
                    <h2>Your workspace</h2>
                    <p class="section-intro">Start an evaluation or jump back to your recent work.</p>
                </div>
                <span class="section-count"><?php echo dashboard_h($profileType !== null ? ACCESS_ACCOUNT_TYPES[$profileType] . ' rater' : 'Profile setup'); ?></span>
            </div>
            <div class="actions">
                <a class="action-card" href="sheet_picker.php">
                    <span class="action-top"><span class="action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 4.75A1.75 1.75 0 0 1 6.75 3H19v17H6.75A1.75 1.75 0 0 1 5 18.25z"/><path d="M5 17.5c0-1.1.9-2 2-2h12M9 7h6m-6 4h6"/></svg></span><span class="action-arrow" aria-hidden="true">↗</span></span>
                    <span class="action-copy"><strong>Sheet picker</strong><span>Browse sheets available to your account and open a scoring form.</span></span>
                </a>
                <a class="action-card" href="sheet_picker.php#recentFormsSection">
                    <span class="action-top"><span class="action-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><span class="action-arrow" aria-hidden="true">↗</span></span>
                    <span class="action-copy"><strong>Recent forms</strong><span>Continue or review a saved evaluation.</span></span>
                </a>
            </div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
