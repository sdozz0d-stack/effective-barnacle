<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/AccessGroups.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/sheets_schema.php';

require_role('admin');
ensure_auth_schema($conn);
ensure_schema($conn);
ensure_sheets_schema($conn);
ensure_drafts_schema($conn);

function rater_progress_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$expectedByType = ['region' => [], 'field' => []];
$raterResult = $conn->query("SELECT account_type, rater_position
    FROM users
    WHERE role = 'rater' AND account_type IN ('region', 'field')
        AND rater_position IS NOT NULL AND TRIM(rater_position) <> ''
    ORDER BY account_type, rater_position");
while ($rater = $raterResult->fetch_assoc()) {
    $accountType = (string)$rater['account_type'];
    $position = canonical_rater_name((string)$rater['rater_position']);
    if ($position !== '' && !in_array($position, $expectedByType[$accountType], true)) {
        $expectedByType[$accountType][] = $position;
    }
}

$scoresByCandidate = [];
$scoreTypesByCandidate = [];
$scoreResult = $conn->query("SELECT cs.candidate_id, cs.rater, cs.psycho, cs.potential, u.account_type
    FROM candidate_scores cs
    LEFT JOIN users u ON u.id = cs.rated_by");
while ($score = $scoreResult->fetch_assoc()) {
    if ($score['psycho'] === null || $score['potential'] === null) continue;
    $candidateId = (int)$score['candidate_id'];
    $scoresByCandidate[$candidateId][canonical_rater_name((string)$score['rater'])] = true;
    $accountType = strtolower(trim((string)($score['account_type'] ?? '')));
    if (!isset($scoreTypesByCandidate[$candidateId])) {
        $scoreTypesByCandidate[$candidateId] = ['types' => [], 'unknown' => false];
    }
    if (isset($expectedByType[$accountType])) {
        $scoreTypesByCandidate[$candidateId]['types'][$accountType] = true;
    } else {
        $scoreTypesByCandidate[$candidateId]['unknown'] = true;
    }
}

$summary = [];
$pendingCandidates = [];
$candidateResult = $conn->query('SELECT id, candidate_name, office_name, position_title, office_type
    FROM candidates ORDER BY office_name, position_title, candidate_name, id');
while ($candidate = $candidateResult->fetch_assoc()) {
    $candidateId = (int)$candidate['id'];
    $officeType = strtoupper(trim((string)($candidate['office_type'] ?? '')));
    $accountType = $officeType === 'RO' ? 'region' : ($officeType === 'FO' ? 'field' : '');
    if ($accountType === '') {
        $inferred = $scoreTypesByCandidate[$candidateId] ?? ['types' => [], 'unknown' => true];
        if (count($inferred['types']) === 1 && !$inferred['unknown']) {
            $accountType = (string)array_key_first($inferred['types']);
        }
    }

    $office = trim((string)($candidate['office_name'] ?? '')) ?: 'Regional Office';
    $scopeKey = $accountType !== '' ? $accountType : 'unassigned';
    $groupKey = strtoupper($office) . '|' . $scopeKey;
    if (!isset($summary[$groupKey])) {
        $summary[$groupKey] = [
            'office' => $office,
            'account_type' => $scopeKey,
            'candidates' => 0,
            'expected' => 0,
            'completed' => 0,
            'pending' => 0,
        ];
    }
    $summary[$groupKey]['candidates']++;
    $expectedRaters = $accountType !== '' ? $expectedByType[$accountType] : [];
    if (!$expectedRaters) continue;

    $completedRaters = $scoresByCandidate[$candidateId] ?? [];
    $missingRaters = [];
    foreach ($expectedRaters as $raterPosition) {
        $summary[$groupKey]['expected']++;
        if (isset($completedRaters[$raterPosition])) {
            $summary[$groupKey]['completed']++;
        } else {
            $summary[$groupKey]['pending']++;
            $missingRaters[] = $raterPosition;
        }
    }
    if ($missingRaters) {
        $pendingCandidates[] = [
            'name' => (string)($candidate['candidate_name'] ?? ''),
            'office' => $office,
            'account_type' => $scopeKey,
            'position' => (string)($candidate['position_title'] ?? ''),
            'missing' => $missingRaters,
        ];
    }
}
uasort($summary, static fn(array $a, array $b): int => [$a['office'], $a['account_type']] <=> [$b['office'], $b['account_type']]);

$incompleteSheets = [];
$sheetResult = $conn->query("SELECT s.title, s.candidate_name, s.office_name, s.updated_at,
        u.username, u.account_type
    FROM sheets s JOIN users u ON u.id = s.user_id
    WHERE s.status = 'draft'
    ORDER BY s.updated_at DESC LIMIT 200");
while ($sheet = $sheetResult->fetch_assoc()) $incompleteSheets[] = $sheet;

$formDrafts = [];
$draftResult = $conn->query("SELECT d.candidate_name, d.position_title, d.office_name, d.updated_at,
        u.username, u.account_type
    FROM form_drafts d JOIN users u ON u.id = d.user_id
    WHERE d.status = 'draft'
    ORDER BY d.updated_at DESC LIMIT 200");
while ($draft = $draftResult->fetch_assoc()) $formDrafts[] = $draft;

$expectedCount = array_sum(array_map(static fn(array $row): int => $row['expected'], $summary));
$completedCount = array_sum(array_map(static fn(array $row): int => $row['completed'], $summary));
$pendingCount = array_sum(array_map(static fn(array $row): int => $row['pending'], $summary));
$completionPercent = $expectedCount > 0 ? (int)round($completedCount * 100 / $expectedCount) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rater Progress</title>
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 28px clamp(14px, 3vw, 40px); background: #f2f5f2; color: #1c2420; font-family: "Public Sans", "Segoe UI", Arial, sans-serif; }
        main { width: min(100%, 1400px); margin: 0 auto; }
        header, .card { padding: clamp(18px, 2.5vw, 28px); border: 1px solid #e1e8e3; border-radius: 16px; background: #fff; box-shadow: 0 7px 24px rgba(26,52,35,.04); }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
        h1, h2 { margin: 0; letter-spacing: -.025em; }
        h1 { font-size: clamp(25px, 4vw, 34px); }
        header p, .card > p { margin: 7px 0 0; color: #68746d; font-size: 13px; line-height: 1.5; }
        a { color: #107c41; font-weight: 700; }
        .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .metric { padding: 18px; border: 1px solid #e1e8e3; border-radius: 14px; background: #fff; }
        .metric span { display: block; color: #68746d; font-size: 12px; }
        .metric strong { display: block; margin-top: 6px; color: #107c41; font-size: 26px; font-variant-numeric: tabular-nums; }
        .card { margin-bottom: 16px; }
        .card h2 { margin-bottom: 14px; font-size: 19px; }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; min-width: 720px; border-collapse: collapse; text-align: left; }
        th, td { padding: 11px 9px; border-bottom: 1px solid #e7ece8; vertical-align: top; font-size: 13px; }
        th { color: #68746d; font-size: 11px; letter-spacing: .05em; text-transform: uppercase; }
        .progress-track { width: 120px; height: 8px; overflow: hidden; border-radius: 99px; background: #e8eee9; }
        .progress-track span { display: block; height: 100%; border-radius: inherit; background: #16834a; }
        .subtle { display: block; margin-top: 3px; color: #68746d; font-size: 11px; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 99px; background: #edf4ef; color: #24613a; font-size: 11px; font-weight: 700; }
        .empty { padding: 22px 10px; color: #68746d; text-align: center; }
        @media (max-width: 600px) { body { padding: 16px 9px; } header { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
<main>
    <header>
        <div><h1>Rater Progress</h1><p>Evaluation completion is measured against the Region or Field rater positions currently assigned in Manage Users.</p></div>
        <nav><a href="dashboard.php">Dashboard</a> · <a href="manage_users.php">Manage Users</a></nav>
    </header>
    <section class="metrics" aria-label="Evaluation summary">
        <div class="metric"><span>Overall completion</span><strong><?php echo $completionPercent === null ? '—' : $completionPercent . '%'; ?></strong></div>
        <div class="metric"><span>Pending evaluations</span><strong><?php echo number_format($pendingCount); ?></strong></div>
        <div class="metric"><span>Incomplete sheets</span><strong><?php echo number_format(count($incompleteSheets)); ?></strong></div>
        <div class="metric"><span>Unsaved form drafts</span><strong><?php echo number_format(count($formDrafts)); ?></strong></div>
    </section>
    <section class="card">
        <h2>Completion by office and account</h2>
        <div class="table-scroll"><table>
            <thead><tr><th>Office</th><th>Account</th><th>Applicants</th><th>Evaluations</th><th>Pending</th><th>Progress</th></tr></thead>
            <tbody>
            <?php foreach ($summary as $row):
                $percent = $row['expected'] > 0 ? (int)round($row['completed'] * 100 / $row['expected']) : null;
                $accountLabel = $row['account_type'] === 'region' ? 'Region' : ($row['account_type'] === 'field' ? 'Field' : 'Unassigned');
            ?>
                <tr>
                    <td><?php echo rater_progress_h($row['office']); ?></td>
                    <td><span class="badge"><?php echo $accountLabel; ?></span></td>
                    <td><?php echo number_format($row['candidates']); ?></td>
                    <td><?php echo number_format($row['completed']); ?> / <?php echo number_format($row['expected']); ?></td>
                    <td><?php echo number_format($row['pending']); ?></td>
                    <td><?php if ($percent === null): ?>No raters assigned<?php else: ?><div class="progress-track" aria-label="<?php echo $percent; ?>% complete"><span style="width:<?php echo $percent; ?>%"></span></div><span class="subtle"><?php echo $percent; ?>%</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$summary): ?><tr><td class="empty" colspan="6">There are no applicant records to summarize.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <section class="card">
        <h2>Applicants awaiting evaluations</h2>
        <div class="table-scroll"><table>
            <thead><tr><th>Applicant</th><th>Office / account</th><th>Position</th><th>Missing rater positions</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($pendingCandidates, 0, 200) as $candidate): ?>
                <tr>
                    <td><?php echo rater_progress_h($candidate['name'] ?: 'Unnamed applicant'); ?></td>
                    <td><?php echo rater_progress_h($candidate['office']); ?><span class="subtle"><?php echo $candidate['account_type'] === 'region' ? 'Region' : 'Field'; ?></span></td>
                    <td><?php echo rater_progress_h($candidate['position'] ?: 'Unassigned'); ?></td>
                    <td><?php echo rater_progress_h(implode(', ', $candidate['missing'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pendingCandidates): ?><tr><td class="empty" colspan="4">No pending evaluations for currently assigned rater positions.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <section class="card">
        <h2>Incomplete sheets</h2>
        <div class="table-scroll"><table>
            <thead><tr><th>Sheet</th><th>Applicant</th><th>Rater</th><th>Account</th><th>Last updated</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($incompleteSheets, 0, 100) as $sheet): ?>
                <tr><td><?php echo rater_progress_h($sheet['title']); ?></td><td><?php echo rater_progress_h($sheet['candidate_name'] ?: 'Not entered'); ?></td><td><?php echo rater_progress_h($sheet['username']); ?></td><td><?php echo rater_progress_h(ucfirst((string)$sheet['account_type'])); ?></td><td><?php echo rater_progress_h($sheet['updated_at']); ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$incompleteSheets): ?><tr><td class="empty" colspan="5">No incomplete sheets.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <section class="card">
        <h2>Unsaved form drafts</h2>
        <div class="table-scroll"><table>
            <thead><tr><th>Applicant</th><th>Office</th><th>Position</th><th>Rater</th><th>Account</th><th>Last updated</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($formDrafts, 0, 100) as $draft): ?>
                <tr><td><?php echo rater_progress_h($draft['candidate_name'] ?: 'Not entered'); ?></td><td><?php echo rater_progress_h($draft['office_name'] ?: 'Not selected'); ?></td><td><?php echo rater_progress_h($draft['position_title'] ?: 'Not selected'); ?></td><td><?php echo rater_progress_h($draft['username']); ?></td><td><?php echo rater_progress_h(ucfirst((string)$draft['account_type'])); ?></td><td><?php echo rater_progress_h($draft['updated_at']); ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$formDrafts): ?><tr><td class="empty" colspan="6">No unsaved form drafts.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
</main>
</body>
</html>
