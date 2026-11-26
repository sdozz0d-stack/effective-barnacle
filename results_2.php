<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';

require_admin();
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/sheets_schema.php';
ensure_auth_schema($conn);
ensure_schema($conn);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$storedOrders = [];
try {
    $storedOrders = all_position_rater_orders($conn);
} catch (Exception $e) {
    error_log('results_2 rater order load error: ' . $e->getMessage());
}

$canonicalStoredOrders = [];
foreach ($storedOrders as $off => $positions) {
    foreach ($positions as $pos => $raters) {
        $canonicalPos = canonical_position_title($pos);
        if (!isset($canonicalStoredOrders[$off][$canonicalPos])) {
            $canonicalStoredOrders[$off][$canonicalPos] = [];
        }
        $canonicalRaters = [];
        foreach ($canonicalStoredOrders[$off][$canonicalPos] as $existingRater) {
            $canonicalRaters[$existingRater] = true;
        }
        foreach ($raters as $r) {
            if (!isset($canonicalRaters[$r])) {
                $canonicalStoredOrders[$off][$canonicalPos][] = $r;
                $canonicalRaters[$r] = true;
            }
        }
    }
}
$storedOrders = $canonicalStoredOrders;

$scoreMap = [];
$scoreAccountTypes = [];
$sr = $conn->query("SELECT cs.candidate_id, cs.rater, cs.psycho, cs.potential, u.account_type
    FROM candidate_scores cs
    LEFT JOIN users u ON u.id = cs.rated_by");
if ($sr) {
    while ($sc = $sr->fetch_assoc()) {
        $candidateId = (int)$sc['candidate_id'];
        $scoreMap[$candidateId][$sc['rater']] = [
            'p' => $sc['psycho'] !== null ? (float)$sc['psycho'] : null,
            't' => $sc['potential'] !== null ? (float)$sc['potential'] : null,
        ];
        $accountType = strtolower(trim((string)($sc['account_type'] ?? '')));
        if (!isset($scoreAccountTypes[$candidateId])) $scoreAccountTypes[$candidateId] = ['types' => [], 'unknown' => false];
        if ($accountType === 'region') {
            $scoreAccountTypes[$candidateId]['types']['RO'] = true;
        } elseif ($accountType === 'field') {
            $scoreAccountTypes[$candidateId]['types']['FO'] = true;
        } else {
            $scoreAccountTypes[$candidateId]['unknown'] = true;
        }
    }
    $sr->free();
}

$result = $conn->query(
    "SELECT id, candidate_name, position_title, office_name, office_type, interview_date,
            salary_grade, custom_competencies_json,
            core_avg, org_avg, psycho_extra1, psycho_extra2, psycho_extra3, psycho_extra4,
            lead_avg, func_avg, potential_extra1, potential_extra2, potential_extra3, potential_extra4
     FROM candidates
     ORDER BY office_name ASC, interview_date ASC, id ASC"
);
$groupedCandidates = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $storedOffice = trim((string)($row['office_name'] ?? ''));
        $office = $storedOffice !== '' ? strtoupper($storedOffice) : 'REGIONAL OFFICE';
        $position = canonical_position_title((string)($row['position_title'] ?? 'LMO I'));
        if (!isset($groupedCandidates[$office])) $groupedCandidates[$office] = [];
        $id = (int)$row['id'];
        $legacyP = []; $legacyT = [];
        foreach (['core_avg', 'org_avg', 'psycho_extra1', 'psycho_extra2', 'psycho_extra3', 'psycho_extra4'] as $col) {
            $legacyP[] = isset($row[$col]) && $row[$col] !== null ? (float)$row[$col] : null;
        }
        foreach (['lead_avg', 'func_avg', 'potential_extra1', 'potential_extra2', 'potential_extra3', 'potential_extra4'] as $col) {
            $legacyT[] = isset($row[$col]) && $row[$col] !== null ? (float)$row[$col] : null;
        }
        $customCompetencies = json_decode((string)($row['custom_competencies_json'] ?? '[]'), true);
        if (!is_array($customCompetencies)) $customCompetencies = [];
        $storedOfficeType = strtoupper(trim((string)($row['office_type'] ?? '')));
        $scoreTypeData = $scoreAccountTypes[$id] ?? ['types' => [], 'unknown' => true];
        $resolvedOfficeType = $office === 'REGIONAL OFFICE'
            ? 'RO'
            : (in_array($storedOfficeType, ['RO', 'FO'], true)
                ? $storedOfficeType
                : (count($scoreTypeData['types']) === 1 && !$scoreTypeData['unknown']
                    ? (string)array_key_first($scoreTypeData['types'])
                    : ''));
        $groupedCandidates[$office][] = [
            'id' => $id,
            'name' => $row['candidate_name'],
            'position' => $position,
            'office' => $office,
            'officeMissing' => $storedOffice === '',
            'salaryGrade' => isset($row['salary_grade']) && $row['salary_grade'] !== null ? (int)$row['salary_grade'] : null,
            'accountType' => $resolvedOfficeType,
            'date' => (string)($row['interview_date'] ?? ''),
            'scores' => isset($scoreMap[$id]) ? $scoreMap[$id] : [],
            'customCompetencies' => $customCompetencies,
            '_legacy' => ['p' => $legacyP, 't' => $legacyT],
        ];
    }
}
if ($result) $result->free();

$storedScopedOfficeTabs = all_scoped_office_tabs($conn);
foreach ($storedScopedOfficeTabs as $offices) {
    foreach ($offices as $office) {
        if (!isset($groupedCandidates[$office])) $groupedCandidates[$office] = [];
    }
}

$defaultOffices = ['REGIONAL OFFICE', 'PENRO ILOILO', 'PENRO CAPIZ', 'PENRO AKLAN', 'PENRO ANTIQUE', 'PENRO GUIMARAS', 'PENRO NEGROS OCCIDENTAL'];
$orderedOffices = [];
foreach ($defaultOffices as $defOffice) $orderedOffices[$defOffice] = $groupedCandidates[$defOffice] ?? [];
foreach ($groupedCandidates as $office => $list) {
    if (!isset($orderedOffices[$office])) $orderedOffices[$office] = $list;
}
$groupedCandidates = $orderedOffices;
foreach (array_keys($storedOrders) as $storedOffice) {
    if (!isset($groupedCandidates[$storedOffice])) $groupedCandidates[$storedOffice] = [];
}
$recycleBinCandidates = [];
$recycleBinDuplicateCheckAvailable = true;
try {
    $recycleResult = $conn->query('SELECT candidate_name, office_name, position_title, office_type
        FROM deleted_candidates ORDER BY deleted_at DESC, id DESC LIMIT 500');
    while ($recycleCandidate = $recycleResult->fetch_assoc()) {
        $recycleBinCandidates[] = [
            'name' => (string)($recycleCandidate['candidate_name'] ?? ''),
            'office' => (string)($recycleCandidate['office_name'] ?? ''),
            'position' => (string)($recycleCandidate['position_title'] ?? ''),
            'accountType' => strtoupper((string)($recycleCandidate['office_type'] ?? '')),
        ];
    }
} catch (mysqli_sql_exception $exception) {
    if ((int)$exception->getCode() !== 1146 || stripos($exception->getMessage(), 'deleted_candidates') === false) {
        throw $exception;
    }
    error_log('results_2 recycle-bin duplicate check unavailable: ' . $exception->getMessage());
    $recycleBinDuplicateCheckAvailable = false;
}
try {
    foreach (removed_tab_list($conn) as $removedOffice) {
        if ($removedOffice === 'REGIONAL OFFICE') continue;
        $hasSavedOfficeTab = in_array($removedOffice, $storedScopedOfficeTabs['RO'], true)
            || in_array($removedOffice, $storedScopedOfficeTabs['FO'], true);
        if (!$hasSavedOfficeTab && isset($groupedCandidates[$removedOffice]) && count($groupedCandidates[$removedOffice]) === 0) {
            unset($groupedCandidates[$removedOffice]);
        }
    }
} catch (Exception $e) {
    error_log('results_2 removed tabs load error: ' . $e->getMessage());
}

$headerLabels = [
    'hash' => 'NO.', 'name' => 'NAME OF APPLICANT / CANDIDATE', 'psychoGroup' => 'PSYCHO-SOCIAL ATTRIBUTES', 'potentialGroup' => 'POTENTIAL ATTRIBUTES',
    'core' => 'CORE', 'org' => 'ORG', 'psychoExtra1' => 'R1', 'psychoExtra2' => 'R2', 'psychoExtra3' => 'R3', 'psychoExtra4' => 'R4',
    'psychoAve' => 'AVE.', 'func' => 'FUNC', 'lead' => 'LEAD',
    'potentialExtra1' => 'R1', 'potentialExtra2' => 'R2', 'potentialExtra3' => 'R3', 'potentialExtra4' => 'R4', 'potentialAve' => 'AVE.'
];
$settingsResult = $conn->query("SELECT hdr_hash, hdr_name, hdr_psycho_group, hdr_potential_group,
    hdr_core, hdr_org, hdr_psycho_extra1, hdr_psycho_extra2, hdr_psycho_extra3, hdr_psycho_extra4,
    hdr_psycho_ave, hdr_func, hdr_lead, hdr_potential_extra1, hdr_potential_extra2,
    hdr_potential_extra3, hdr_potential_extra4, hdr_potential_ave
    FROM sheet_settings WHERE id = 1 LIMIT 1");
$settingsRow = $settingsResult->fetch_assoc();
if ($settingsRow) {
    $headerLabels = [
        'hash' => $settingsRow['hdr_hash'] ?? 'NO.',
        'name' => $settingsRow['hdr_name'] ?? 'NAME OF APPLICANT / CANDIDATE',
        'psychoGroup' => $settingsRow['hdr_psycho_group'] ?? 'PSYCHO-SOCIAL ATTRIBUTES',
        'potentialGroup' => $settingsRow['hdr_potential_group'] ?? 'POTENTIAL ATTRIBUTES',
        'core' => $settingsRow['hdr_core'] ?? 'CORE',
        'org' => $settingsRow['hdr_org'] ?? 'ORG',
        'psychoExtra1' => $settingsRow['hdr_psycho_extra1'] ?? 'R1',
        'psychoExtra2' => $settingsRow['hdr_psycho_extra2'] ?? 'R2',
        'psychoExtra3' => $settingsRow['hdr_psycho_extra3'] ?? 'R3',
        'psychoExtra4' => $settingsRow['hdr_psycho_extra4'] ?? 'R4',
        'psychoAve' => $settingsRow['hdr_psycho_ave'] ?? 'AVE.',
        'func' => $settingsRow['hdr_func'] ?? 'FUNC',
        'lead' => $settingsRow['hdr_lead'] ?? 'LEAD',
        'potentialExtra1' => $settingsRow['hdr_potential_extra1'] ?? 'R1',
        'potentialExtra2' => $settingsRow['hdr_potential_extra2'] ?? 'R2',
        'potentialExtra3' => $settingsRow['hdr_potential_extra3'] ?? 'R3',
        'potentialExtra4' => $settingsRow['hdr_potential_extra4'] ?? 'R4',
        'potentialAve' => $settingsRow['hdr_potential_ave'] ?? 'AVE.',
    ];
}

function normalize_rater(string $label): ?string {
    static $knownRaters = null;
    static $knownByCompactName = null;
    static $normalizedLabels = [];
    if (isset($normalizedLabels[$label])) return $normalizedLabels[$label];
    if ($knownRaters === null) {
        $knownRaters = rater_list();
        $knownByCompactName = [];
        foreach ($knownRaters as $rater) {
            $compact = preg_replace('/[\s\-_]+/', '', strtoupper($rater));
            $knownByCompactName[$compact] = $rater;
        }
    }
    $trimmed = trim($label);
    if ($trimmed === '') {
        $normalizedLabels[$label] = null;
        return null;
    }
    $compact = preg_replace('/[\s\-_]+/', '', strtoupper($trimmed));
    $normalized = $knownByCompactName[$compact]
        ?? preg_replace('/\s+/', ' ', strtoupper($trimmed));
    $normalizedLabels[$label] = $normalized;
    return $normalized;
}

$legacyPLabels = [$headerLabels['core'], $headerLabels['org'], $headerLabels['psychoExtra1'], $headerLabels['psychoExtra2'], $headerLabels['psychoExtra3'], $headerLabels['psychoExtra4']];
$legacyTLabels = [$headerLabels['func'], $headerLabels['lead'], $headerLabels['potentialExtra1'], $headerLabels['potentialExtra2'], $headerLabels['potentialExtra3'], $headerLabels['potentialExtra4']];
foreach ($groupedCandidates as $officeKey => $list) {
    foreach ($list as $i => $candidate) {
        if (empty($candidate['scores'])) {
            $scores = [];
            for ($index = 0; $index < 6; $index++) {
                $psychoRater = normalize_rater((string)$legacyPLabels[$index]);
                $potentialRater = normalize_rater((string)$legacyTLabels[$index]);
                if ($psychoRater !== null && $candidate['_legacy']['p'][$index] !== null) $scores[$psychoRater]['p'] = $candidate['_legacy']['p'][$index];
                if ($potentialRater !== null && $candidate['_legacy']['t'][$index] !== null) $scores[$potentialRater]['t'] = $candidate['_legacy']['t'][$index];
            }
            foreach ($scores as $rater => $score) {
                $scores[$rater] = ['p' => $score['p'] ?? null, 't' => $score['t'] ?? null];
            }
            $groupedCandidates[$officeKey][$i]['scores'] = $scores;
        }
        unset($groupedCandidates[$officeKey][$i]['_legacy']);
    }
}
$initialResultsAccountType = strtoupper(trim((string)($_SESSION['results_account_type'] ?? '')));
if (!in_array($initialResultsAccountType, ['RO', 'FO'], true)) $initialResultsAccountType = '';
$initialResultsOffice = strtoupper(trim((string)($_SESSION['results_office'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="chrome" content="nofill">
    <title>Master Interview Summary Rating Sheet</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="mockup.css?v=<?php echo filemtime(__DIR__ . '/mockup.css'); ?>">
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <script src="ui-enhancement.js?v=<?php echo filemtime(__DIR__ . '/ui-enhancement.js'); ?>" defer></script>
    <style>
        .results-alert-backdrop { position: fixed; z-index: 10000; inset: 0; display: grid; place-items: center; padding: 20px; background: rgba(15, 23, 42, .48); }
        .results-alert-dialog { width: min(100%, 440px); padding: 24px; border: 1px solid #d6ddd8; border-radius: 14px; background: #fff; color: #202a35; box-shadow: 0 18px 56px rgba(15, 23, 42, .28); }
        .results-alert-dialog h2 { margin: 0 0 10px; font-size: 19px; }
        .results-alert-dialog p { margin: 0; color: #526158; line-height: 1.55; white-space: pre-line; overflow-wrap: anywhere; }
        .results-alert-actions { display: flex; justify-content: flex-end; margin-top: 24px; }
        .results-alert-actions button { min-width: 84px; min-height: 40px; padding: 8px 16px; border: 1px solid #107c41; border-radius: 7px; background: #107c41; color: #fff; font: inherit; font-weight: 650; cursor: pointer; }
        .results-confirm-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 24px; }
        .results-confirm-actions button { min-width: 84px; min-height: 42px; padding: 8px 16px; border: 1px solid #d5ddd7; border-radius: 7px; background: #fff; color: #344139; font: inherit; font-weight: 650; cursor: pointer; }
        .results-confirm-actions .confirm-delete { border-color: #b3261e; background: #b3261e; color: #fff; }
        .results-confirm-actions .prompt-submit { border-color: #107c41; background: #107c41; color: #fff; }
        .results-prompt-input { display: block; width: 100%; min-width: 0; min-height: 44px; margin-top: 14px; padding: 9px 11px; border: 1px solid #b8c2cb; border-radius: 7px; background: #fff; color: #202a35; font: inherit; }
        .results-prompt-input:focus { outline: 3px solid rgba(16, 124, 65, .2); border-color: #107c41; }
        .account-shortcuts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; padding: 10px 12px; background: #f5f8f6; border: 1px solid #dce5df; border-radius: 8px; }
        .account-shortcuts button { min-height: 38px; padding: 7px 14px; border: 1px solid #b9c9bf; border-radius: 7px; background: #fff; color: #174b30; font: inherit; font-weight: 650; cursor: pointer; }
        .account-shortcuts button:hover:not(:disabled), .account-shortcuts button:focus-visible { background: #e8f3ec; border-color: #26734a; }
        .account-shortcuts button.active { background: #17653b; border-color: #17653b; color: #fff; }
        .account-shortcuts button:disabled { opacity: .55; cursor: not-allowed; }
        .results-save-status { align-self: center; color: #52635a; font-size: 12px; }
        .results-save-status[data-state="saving"] { color: #0b6bcb; font-weight: 650; }
        .results-save-status[data-state="dirty"], .results-save-status[data-state="error"] { color: #9a3412; font-weight: 650; }
        .results-save-status[data-state="saved"] { color: #17653b; font-weight: 650; }
        .recycle-bin-action { display: inline-flex; min-height: 40px; align-items: center; padding: 8px 13px; border: 1px solid #b9c9bf; border-radius: 7px; background: #fff; color: #174b30; font-size: 13px; font-weight: 650; text-decoration: none; }
        .recycle-bin-action:hover, .recycle-bin-action:focus-visible { background: #e8f3ec; border-color: #26734a; }
        .import-preview-backdrop { position: fixed; z-index: 10020; inset: 0; display: grid; place-items: center; padding: 18px; background: rgba(15, 23, 42, .55); }
        .import-preview-dialog { display: flex; width: min(100%, 900px); max-height: min(90vh, 760px); flex-direction: column; overflow: hidden; border: 1px solid #d6ddd8; border-radius: 14px; background: #fff; color: #202a35; box-shadow: 0 18px 56px rgba(15, 23, 42, .3); }
        .import-preview-header, .import-preview-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 17px 20px; }
        .import-preview-header { border-bottom: 1px solid #e3eae5; }
        .import-preview-header h2 { margin: 0; font-size: 19px; }
        .import-preview-header p { margin: 4px 0 0; color: #68746d; font-size: 13px; overflow-wrap: anywhere; }
        .import-preview-summary { display: flex; flex-wrap: wrap; gap: 8px; padding: 12px 20px; background: #f5f8f6; color: #405248; font-size: 12px; }
        .import-preview-summary span { padding: 5px 9px; border: 1px solid #dce5df; border-radius: 99px; background: #fff; }
        .import-preview-warning { padding: 10px 20px; background: #fff8e6; color: #705600; font-size: 13px; line-height: 1.45; }
        .import-preview-table-wrap { min-height: 0; overflow: auto; }
        .import-preview-table { width: 100%; border-collapse: collapse; text-align: left; }
        .import-preview-table th, .import-preview-table td { padding: 10px 12px; border-bottom: 1px solid #e8ede9; font-size: 13px; vertical-align: middle; }
        .import-preview-table th { position: sticky; top: 0; background: #fff; color: #68746d; font-size: 11px; letter-spacing: .05em; text-transform: uppercase; }
        .import-preview-table td:first-child { width: 42px; text-align: center; }
        .import-preview-table input { width: 17px; height: 17px; accent-color: #107c41; }
        .import-preview-type { display: inline-block; padding: 3px 7px; border-radius: 99px; background: #eaf5ed; color: #155d32; font-size: 11px; font-weight: 700; }
        .import-preview-type.update { background: #eaf1fb; color: #28558b; }
        .import-preview-type.duplicate { background: #fff0cf; color: #725300; }
        .import-preview-footer { justify-content: flex-end; border-top: 1px solid #e3eae5; }
        .import-preview-footer button { min-height: 40px; padding: 8px 14px; border: 1px solid #d5ddd7; border-radius: 8px; background: #fff; color: #344139; font: inherit; font-weight: 650; cursor: pointer; }
        .import-preview-footer .import-preview-apply { border-color: #107c41; background: #107c41; color: #fff; }
        .import-preview-footer .import-preview-apply:disabled { border-color: #cbd5ce; background: #e6ebe7; color: #728078; cursor: not-allowed; }
        @media (max-width: 600px) {
            .import-preview-backdrop { padding: 8px; }
            .import-preview-dialog { max-height: 94vh; }
            .import-preview-header, .import-preview-footer { padding: 14px; }
            .import-preview-summary, .import-preview-warning { padding-right: 14px; padding-left: 14px; }
            .import-preview-table th, .import-preview-table td { padding: 9px 8px; }
        }
        .account-scope { margin-top: 5px; font-size: 12px; font-weight: 700; color: #285d3e; letter-spacing: .04em; }
        .account-scope-label { display: inline-block; margin-left: 8px; padding: 2px 6px; border-radius: 4px; background: #e8f3ec; color: #285d3e; font-size: 10px; font-weight: 700; vertical-align: middle; }
        .results-navigation { position: sticky; top: env(safe-area-inset-top, 0px); z-index: 20; padding-top: 6px; background: #f3f5f4; }
        .mobile-office-picker { display: none; }
        .account-result-section { display: none; }
        #results[data-account-view="RO"] .account-result-section[data-account-type="RO"],
        #results[data-account-view="FO"] .account-result-section[data-account-type="FO"],
        #results[data-account-view="RO"] .account-result-section[data-account-type="UNCLASSIFIED"],
        #results[data-account-view="FO"] .account-result-section[data-account-type="UNCLASSIFIED"] { display: block; }
        .account-empty-state { margin: 14px 0; padding: 16px; border: 1px dashed #cbd8cf; border-radius: 8px; color: #53645a; text-align: center; }
        @media (max-width: 520px) { .results-confirm-actions { flex-direction: column-reverse; } .results-confirm-actions button { width: 100%; } }
        #tables-wrapper > .table-container { display: none; }
        #tables-wrapper > .table-container.active { display: block; }
        #tabs .ind { z-index: 5; transition: left .42s cubic-bezier(.22, .61, .36, 1), width .42s cubic-bezier(.22, .61, .36, 1); }
        .excel-table th, .excel-table td { padding: 8px 6px; }
        .excel-table tbody tr:nth-child(even) td:not(.g):not(.y) { background-color: #f8faf9; }
        .excel-table tbody tr:hover td:not(.g):not(.y) { background-color: #eef6f0; }
        ::view-transition-old(active-sheet) { animation: ui-sheet-out-left .42s cubic-bezier(.22, .61, .36, 1) both; }
        ::view-transition-new(active-sheet) { animation: ui-sheet-in-right .42s cubic-bezier(.22, .61, .36, 1) both; }
        html[data-ui-transition="sheet-reverse"]::view-transition-old(active-sheet) { animation: ui-sheet-out-right .42s cubic-bezier(.22, .61, .36, 1) both; }
        html[data-ui-transition="sheet-reverse"]::view-transition-new(active-sheet) { animation: ui-sheet-in-left .42s cubic-bezier(.22, .61, .36, 1) both; }
        .table-container.sheet.sl { animation: ui-sheet-in-right .42s cubic-bezier(.22, .61, .36, 1) both; }
        .table-container.sheet.sr { animation: ui-sheet-in-left .42s cubic-bezier(.22, .61, .36, 1) both; }
        .table-container.sheet.sl .blk, .table-container.sheet.sr .blk { animation: none; }
        .tab-close-btn { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; margin-left: 6px; border: 0; padding: 0; border-radius: 50%; background: rgba(179, 38, 30, .12); color: #b3261e; font-size: 13px; line-height: 1; cursor: pointer; }
        .tab-close-btn:hover { background: #b3261e; color: #fff; }
        .tab:focus-visible, .tab-close-btn:focus-visible, .account-shortcuts button:focus-visible, .btn:focus-visible, .tabsbar .add:focus-visible, .tab-search input:focus-visible, .recycle-bin-action:focus-visible, .pos-delete-btn:focus-visible, .rater-remove:focus-visible, .rater-add:focus-visible {
            outline: 3px solid rgba(16, 124, 65, 0.32);
            outline-offset: 2px;
        }
        html[data-theme="dark"] .tab:focus-visible, html[data-theme="dark"] .tab-close-btn:focus-visible, html[data-theme="dark"] .account-shortcuts button:focus-visible, html[data-theme="dark"] .btn:focus-visible, html[data-theme="dark"] .tabsbar .add:focus-visible, html[data-theme="dark"] .tab-search input:focus-visible, html[data-theme="dark"] .recycle-bin-action:focus-visible, html[data-theme="dark"] .pos-delete-btn:focus-visible, html[data-theme="dark"] .rater-remove:focus-visible, html[data-theme="dark"] .rater-add:focus-visible {
            outline-color: rgba(110, 231, 168, 0.55);
        }
        .pos-delete-btn { float: right; width: 20px; height: 20px; padding: 0; border: 1px solid var(--ln); border-radius: 50%; background: #fff; cursor: pointer; }
        .excel-table .candidate-name { display: block; min-height: 1em; }
        .account-type-select { display: block; width: 100%; max-width: 170px; min-height: 30px; margin-top: 5px; padding: 3px 6px; border: 1px solid #b9c9bf; border-radius: 5px; background: #fff; color: #174b30; font: 11px Arial, sans-serif; }
        .account-type-select:focus-visible { outline: 2px solid #107c41; outline-offset: 2px; }
        .row-number { display: inline; }
        .delete-icon { display: none; color: #b3261e; font-weight: 700; }
        .row-num-cell:hover .row-number { display: none; }
        .row-num-cell:hover .delete-icon { display: inline; }
        .mobile-sheet-scroll { display: none; }
        @media (max-width: 600px) {
            #results { width: 100%; min-width: 0; padding: 8px 6px; }
            .tabsbar { align-items: center; gap: 4px; padding: 6px 6px 0; border-radius: 8px 8px 0 0; }
            #tabs { min-width: 0; }
            .tab { padding: 9px 10px; font-size: 12px; white-space: nowrap; }
            .tab-close-btn { width: 16px; height: 16px; margin-left: 4px; }
            .tabsbar .add { min-width: 82px; height: 34px; padding: 0 9px; margin: 0 0 2px; }
            .srch { min-width: 0; padding: 6px 0; }
            .srch input { min-width: 0; }
            #tables-wrapper, .table-container.sheet { width: 100%; min-width: 0; max-width: 100%; }
            .table-container.sheet { overflow: hidden; padding: 14px 8px; border-radius: 0 0 8px 8px; }
            .dh { margin: 0 2px 14px; overflow-wrap: anywhere; }
            .dh .a, .dh .c { font-size: 10px; }
            .dh .b { font-size: 12px; }
            .dh .d { font-size: 13px; }
            .blk, .table-scroll.sc { width: 100%; min-width: 0; max-width: 100%; }
            .table-scroll.sc { overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; scrollbar-width: thin; touch-action: pan-x pan-y; }
            .mobile-sheet-scroll { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin: 0 0 7px; color: #52635a; font-size: 11px; }
            .mobile-sheet-scroll button { flex: 0 0 44px; width: 44px; height: 44px; border: 1px solid #cbd5ce; border-radius: 9px; background: #fff; color: #145d35; font-size: 21px; line-height: 1; cursor: pointer; touch-action: manipulation; }
            .mobile-sheet-scroll button:disabled { color: #a7b0aa; background: #f0f3f1; cursor: default; }
            .mobile-sheet-scroll span { flex: 1; text-align: center; }
            .mobile-office-picker { display: grid; gap: 5px; padding: 8px 0; color: #52635a; font-size: 12px; font-weight: 650; }
            .mobile-office-picker select { width: 100%; min-height: 44px; padding: 8px 10px; border: 1px solid #cbd5ce; border-radius: 8px; background: #fff; color: #174b30; font: inherit; }
            #tabs { overflow-x: auto; scroll-snap-type: x proximity; -webkit-overflow-scrolling: touch; }
            .tab { scroll-snap-align: start; }
            .table-block.no-horizontal-overflow .mobile-sheet-scroll { display: none; }
            .table-scroll.sc::-webkit-scrollbar { height: 7px; }
            .table-scroll.sc::-webkit-scrollbar-thumb { background: #84938a; border-radius: 8px; }
            .table-scroll.sc:focus-visible { outline: 3px solid rgba(16,124,65,.35); outline-offset: 2px; }
            .excel-table th, .excel-table td { padding: 6px 4px; }
            .excel-table .nm { min-width: 170px; }
            .row-num-cell:hover .row-number { display: inline; }
            .row-num-cell:hover .delete-icon { display: none; }
            .sg { gap: 22px; }
            .ab { gap: 8px; padding: 9px; }
            .ag { gap: 6px; }
            .ag .btn { min-width: 0; padding: 8px 9px; font-size: 12px; }
        }
        @media print {
            @page { size: landscape; margin: 0.4in; }
            body { background: #fff; padding: 0; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            #results, #results * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .no-print, .excel-tabs-container, .action-bar, .rater-add, .pos-delete-btn, .tab-close-btn, .rater-remove { display: none !important; }
            .table-container { display: block !important; border: none; box-shadow: none; padding: 0; page-break-after: always; }
            .table-block { page-break-inside: avoid; }
            .table-scroll { overflow: visible !important; }
            .excel-table { min-width: 0 !important; }
            .excel-table th, .excel-table td { background-color: #fff !important; }
            .excel-table thead th:not(.g):not(.y) { background-color: #f4f7f5 !important; }
            .excel-table .dt { background-color: #f4f7f5 !important; }
            .excel-table .pc { background-color: #fbd3e5 !important; }
            .excel-table .g { background-color: #e3f1d9 !important; }
            .excel-table thead th.g { background-color: #cfe7bf !important; }
            .excel-table .y { background-color: #fff2c9 !important; }
            .excel-table thead th.y { background-color: #ffe79a !important; }
            .row-num-cell:hover .row-number { display: inline !important; }
            .row-num-cell:hover .delete-icon { display: none !important; }
        }
        @media screen {
            body { min-height: 100vh; background: #f2f5f2; }
            #results { width: min(100%, 1720px); min-height: 100vh; margin: 0 auto; padding: clamp(14px, 2.4vw, 32px); }
            .results-hero { display: flex; min-height: 172px; align-items: center; padding: clamp(24px, 3.5vw, 40px); margin-bottom: 16px; border-radius: 20px; background: linear-gradient(118deg, #0b5c30 0%, #107c41 58%, #239556 100%); box-shadow: 0 16px 38px rgba(17, 91, 48, .16); color: #fff; }
            .results-hero .eyebrow { margin: 0 0 10px; color: #c5eed3; font-size: 11px; font-weight: 750; letter-spacing: .14em; text-transform: uppercase; }
            .results-hero h1 { margin: 0 0 8px; color: #fff; font-size: clamp(29px, 4vw, 42px); letter-spacing: -.04em; line-height: 1.1; }
            .results-hero p:last-child { max-width: 670px; margin: 0; color: rgba(255,255,255,.82); font-size: 13px; line-height: 1.6; }
            .results-navigation { top: 0; padding: 8px; border: 1px solid #e0e7e2; border-radius: 14px; background: rgba(248, 250, 248, .96); box-shadow: 0 8px 24px rgba(26,52,35,.07); backdrop-filter: blur(12px); }
            .account-shortcuts { gap: 7px; padding: 0 0 9px; margin: 0 0 8px; border: 0; border-bottom: 1px solid #e2e9e4; border-radius: 0; background: transparent; }
            .account-shortcuts button { min-height: 40px; padding: 7px 17px; border-color: #d7e2da; border-radius: 9px; color: #405248; }
            .account-shortcuts button.active { border-color: #107c41; background: #107c41; color: #fff; box-shadow: 0 3px 9px rgba(16,124,65,.16); }
            .tabsbar { gap: 10px; padding: 5px 6px 0; border: 0; border-radius: 0; background: transparent; }
            #tabs { min-height: 42px; align-items: stretch; gap: 4px; }
            .tab { padding: 10px 15px; border-radius: 9px 9px 0 0; }
            .tab[aria-selected="true"] { box-shadow: inset 0 -3px #107c41; }
            .tab-close-btn { display: none; }
            .tab[aria-selected="true"] .tab-close-btn { display: inline-flex; }
            .tabsbar .add { display: inline-flex; width: auto; min-width: 88px; height: 36px; align-items: center; justify-content: center; padding: 0 12px; margin: 0 0 3px; border: 1px solid #cfe1d4; border-radius: 9px; background: #fff; color: #107c41; font-size: 12px; font-weight: 700; white-space: nowrap; transition: background-color .16s ease, border-color .16s ease, transform .16s ease; }
            .tabsbar .add:hover { transform: translateY(-1px); border-color: #9dc9aa; background: #f0f8f2; }
            .tabsbar .add:focus-visible { outline: 3px solid rgba(16,124,65,.2); outline-offset: 2px; }
            .srch { align-items: center; padding: 0 0 5px; }
            .srch input { min-height: 40px; width: clamp(180px, 22vw, 290px); border-radius: 9px; }
            .srch .btn { min-height: 40px; padding: 8px 13px; border-radius: 9px; }
            .table-container.sheet { padding: clamp(16px, 2.2vw, 28px); border: 1px solid #e0e7e2; border-radius: 0 0 16px 16px; box-shadow: 0 8px 26px rgba(26,52,35,.05); }
            .dh { margin: 2px 0 22px; }
            .dh .d { font-size: 15px; letter-spacing: .015em; }
            .blk { margin-bottom: 30px; }
            .sc { border-color: #dbe4dd; border-radius: 11px; box-shadow: 0 4px 15px rgba(26,52,35,.035); }
            .excel-table th, .excel-table td { padding: 9px 7px; }
            .excel-table thead th { padding-top: 11px; padding-bottom: 11px; }
            .ab { gap: 10px 14px; padding: 14px; margin-top: 16px; border-color: #dfe7e1; border-radius: 13px; box-shadow: 0 7px 22px rgba(26,52,35,.045); }
            .ag { gap: 8px; }
            .ag .btn { min-height: 40px; padding: 8px 13px; border-radius: 9px; }
            .ag+.ag { padding-left: 14px; border-color: #e2e9e4; }
            .results-save-status { min-width: 110px; }
            html[data-theme="dark"] .results-hero { background: linear-gradient(118deg, #0f2f20, #145232 60%, #1a6b3f); }
            html[data-theme="dark"] .results-navigation { border-color: rgba(52,211,153,.18); }
            html[data-theme="dark"] .account-shortcuts { border-color: #304038; background: transparent; }
            html[data-theme="dark"] .account-shortcuts button { border-color: #3b4a42; background: #243029; color: #e8f0eb; }
            html[data-theme="dark"] .account-shortcuts button:hover { border-color: #4a6a56; background: #2d3d34; }
            html[data-theme="dark"] .account-shortcuts button.active { border-color: #25834b; background: #17653b; color: #fff; }
            html[data-theme="dark"] .mobile-office-picker { color: #c9d8cf; }
            html[data-theme="dark"] .mobile-office-picker select { border-color: #3b4a42; background: #243029; color: #e8f0eb; }
            html[data-theme="dark"] .tabsbar .add { border-color: #3b4a42; background: #243029; color: #b8e4c4; }
            html[data-theme="dark"] .tabsbar .add:hover { border-color: #527a5d; background: #213128; }
            html[data-theme="dark"] .sc { border-color: #304038; }
            html[data-theme="dark"] .table-container.sheet { background: #18241d; border-color: #304038; }
            html[data-theme="dark"] .tab { color: #b8c9be; }
            html[data-theme="dark"] .tab:hover { background: #213128; color: #e8f0eb; }
            html[data-theme="dark"] .tab[aria-selected="true"] { background: #213128; color: #6ee7a8; }
            html[data-theme="dark"] .srch input { border-color: #3b4a42; background: #243029; color: #e8f0eb; }
            html[data-theme="dark"] .dh .a, html[data-theme="dark"] .dh .b, html[data-theme="dark"] .dh .c { color: #c9d8cf; }
            html[data-theme="dark"] .dh .d { color: #6ee7a8; }
            html[data-theme="dark"] .ab { border-color: #304038; background: #18241d; }
            html[data-theme="dark"] .ag+.ag { border-color: #304038; }
            html[data-theme="dark"] .recycle-bin-action { border-color: #3b4a42; background: #243029; color: #b8e4c4; }
            html[data-theme="dark"] .recycle-bin-action:hover,
            html[data-theme="dark"] .recycle-bin-action:focus-visible { border-color: #527a5d; background: #213128; color: #d9fbe6; }
            html[data-theme="dark"] .btn.d { border-color: #5c3036; background: #2a171b; color: #ffb4ab; }
            html[data-theme="dark"] .btn.d:hover { border-color: #7a3a42; background: #3a1d22; color: #ffd6d1; }
            html[data-theme="dark"] .results-save-status { color: #b8c9be; }
            html[data-theme="dark"] .results-save-status[data-state="saving"] { color: #93c5fd; }
            html[data-theme="dark"] .results-save-status[data-state="dirty"],
            html[data-theme="dark"] .results-save-status[data-state="error"] { color: #f6d365; }
            html[data-theme="dark"] .results-save-status[data-state="saved"] { color: #9fe0b5; }
            html[data-theme="dark"] .ab .btn:disabled,
            html[data-theme="dark"] .ab .btn[disabled] { opacity: .55; cursor: not-allowed; }
            html[data-theme="dark"] .results-navigation { background: transparent; }
            html[data-theme="dark"] .account-scope { color: #9fe0b5; }
            html[data-theme="dark"] .account-scope-label { background: #213128; color: #9fe0b5; border: 1px solid #304038; }
            html[data-theme="dark"] .account-empty-state { border-color: #304038; color: #b8c9be; background: #18241d; }
            html[data-theme="dark"] .results-alert-backdrop,
            html[data-theme="dark"] .import-preview-backdrop { background: rgba(8, 12, 10, .7); }
            html[data-theme="dark"] .results-alert-dialog,
            html[data-theme="dark"] .import-preview-dialog { background: #18241d; border-color: #304038; color: #e8f0eb; box-shadow: 0 18px 56px rgba(0,0,0,.55); }
            html[data-theme="dark"] .results-alert-dialog h2,
            html[data-theme="dark"] .import-preview-header h2 { color: #f3f7f4; }
            html[data-theme="dark"] .results-alert-dialog p,
            html[data-theme="dark"] .import-preview-header p { color: #c9d8cf; }
            html[data-theme="dark"] .results-confirm-actions button { background: #243029; border-color: #3b4a42; color: #e8f0eb; }
            html[data-theme="dark"] .results-confirm-actions button:hover { background: #2d3d34; border-color: #4a6a56; }
            html[data-theme="dark"] .results-confirm-actions .confirm-delete { border-color: #5c3036; background: #2a171b; color: #ffb4ab; }
            html[data-theme="dark"] .results-confirm-actions .confirm-delete:hover { border-color: #7a3a42; background: #3a1d22; color: #ffd6d1; }
            html[data-theme="dark"] .results-confirm-actions .prompt-submit,
            html[data-theme="dark"] .results-alert-actions button { border-color: #25834b; background: #17653b; color: #fff; }
            html[data-theme="dark"] .results-prompt-input { background: #243029; border-color: #3b4a42; color: #e8f0eb; }
            html[data-theme="dark"] .results-prompt-input:focus { border-color: #34d399; outline-color: rgba(52,211,153,.18); }
            html[data-theme="dark"] .import-preview-header,
            html[data-theme="dark"] .import-preview-footer { border-color: #304038; }
            html[data-theme="dark"] .import-preview-summary { background: #213128; color: #c9d8cf; }
            html[data-theme="dark"] .import-preview-summary span { background: #243029; border-color: #3b4a42; color: #e8f0eb; }
            html[data-theme="dark"] .import-preview-warning { background: #262010; color: #f6d365; }
            html[data-theme="dark"] .import-preview-table th { background: #213128; color: #c9d8cf; }
            html[data-theme="dark"] .import-preview-table th,
            html[data-theme="dark"] .import-preview-table td { border-color: #304038; color: #e8f0eb; }
            html[data-theme="dark"] .import-preview-type { background: #14261b; color: #9fe0b5; }
            html[data-theme="dark"] .import-preview-type.update { background: #162535; color: #93c5fd; }
            html[data-theme="dark"] .import-preview-type.duplicate { background: #2a2414; color: #f6d365; }
            html[data-theme="dark"] .import-preview-footer button { background: #243029; border-color: #3b4a42; color: #e8f0eb; }
            html[data-theme="dark"] .import-preview-footer button:hover { background: #2d3d34; border-color: #4a6a56; }
            html[data-theme="dark"] .import-preview-footer .import-preview-apply { border-color: #25834b; background: #17653b; color: #fff; }
            html[data-theme="dark"] .import-preview-footer .import-preview-apply:disabled { border-color: #304038; background: #213128; color: #7f9688; }
            html[data-theme="dark"] .mobile-sheet-scroll button { background: #243029; border-color: #3b4a42; color: #9fe0b5; }
            html[data-theme="dark"] .mobile-sheet-scroll button:hover { background: #2d3d34; border-color: #4a6a56; }
            html[data-theme="dark"] .mobile-sheet-scroll button:disabled { opacity: .45; }
            html[data-theme="dark"] .account-type-select { background: #243029; border-color: #3b4a42; color: #e8f0eb; }
            html[data-theme="dark"] .pos-delete-btn { background: #243029; border-color: #3b4a42; color: #e8f0eb; }
            html[data-theme="dark"] .pos-delete-btn:hover { background: #5c3036; border-color: #7a3a42; color: #fff; }
            html[data-theme="dark"] .excel-table tbody tr:nth-child(even) td:not(.g):not(.y) { background-color: #1d2c24; }
            html[data-theme="dark"] .excel-table tbody tr:hover td:not(.g):not(.y) { background-color: #213128; }
            @media (max-width: 760px) {
                #results { min-height: 100vh; padding: 16px 12px; }
                .results-hero { min-height: 0; padding: 25px; border-radius: 17px; }
                .results-navigation { padding: 7px; }
                .tabsbar { gap: 6px; }
                .srch { gap: 7px; }
                .table-container.sheet { padding: 16px 12px; }
                .ab { padding: 12px; }
                .ag+.ag { padding: 8px 0 0; border: 0; border-top: 1px solid #e2e9e4; }
                .results-save-status { min-width: 0; }
            }
            @media (max-width: 600px) {
                #results { padding: 12px 9px; }
                .results-hero { padding: 22px 18px; margin-bottom: 11px; border-radius: 15px; }
                .results-hero p:last-child { font-size: 12px; }
                .results-navigation { padding: 6px; border-radius: 11px; }
                .account-shortcuts { gap: 6px; padding-bottom: 7px; margin-bottom: 6px; }
                .account-shortcuts button { flex: 1; min-height: 40px; padding: 7px 10px; }
                .tabsbar { gap: 4px; }
                .tab { padding: 9px; }
                .tabsbar .add { min-width: 82px; height: 34px; padding: 0 9px; margin: 0 0 2px; }
                .table-container.sheet { padding: 13px 8px; }
                .ab { border-radius: 11px; }
            }
        }
    </style>
</head>
<body>

<section id="results">
<form id="resultsForm" autocomplete="off" onsubmit="return false;">
    <header class="results-hero no-print">
        <div>
            <p class="eyebrow">Administration workspace</p>
            <h1>Interview Results</h1>
            <p>Review scoring sheets, update records, and save changes to the evaluation results.</p>
        </div>
    </header>
    <div class="results-navigation no-print">
        <div class="account-shortcuts" aria-label="Results by account type">
            <button type="button" id="regionResultsButton" onclick="switchAccountType('RO')">Region</button>
            <button type="button" id="fieldResultsButton" onclick="switchAccountType('FO')">Field</button>
        </div>
        <label class="mobile-office-picker" for="officeTabSelect">
            Office / Branch
            <select id="officeTabSelect" aria-label="Select office or branch"></select>
        </label>
        <div class="excel-tabs-container tabsbar">
            <div id="tabs" role="tablist" aria-label="Office result tabs"></div>
            <button type="button" class="add" onclick="promptNewTab()" aria-label="Add office tab">+ Add Tab</button>
            <div class="tab-search srch">
                <input type="search" id="candidateSearch" placeholder="Search name, position, or office..." aria-label="Search candidate name, position, or office" autocomplete="off">
                <button type="button" class="btn" onclick="searchCandidate()">🔍 Find</button>
            </div>
        </div>
    </div>

    <div id="tables-wrapper"></div>

    <div class="action-bar ab no-print">
        <div class="action-group ag">
            <button type="button" id="saveResultsButton" class="btn p btn-primary" onclick="saveWholeTable(event)">💾 Save All Changes</button>
            <span id="resultsSaveStatus" class="results-save-status" role="status" aria-live="polite"></span>
            <button type="button" id="undoDeleteButton" class="btn" onclick="undoLastDelete()" disabled title="Undo is available after a deletion and before saving.">↶ Undo</button>
            <button type="button" id="redoDeleteButton" class="btn" onclick="redoLastDelete()" disabled title="Redo is available after you undo a deletion and before saving.">↷ Redo</button>
            <span class="results-save-status">Undo/Redo apply to deletions not yet saved.</span>
            <button type="button" class="btn" id="importExcelButton">Import Excel</button>
            <input type="file" id="excelImportFile" accept=".xlsx,.xls,.csv" hidden>
            <button type="button" class="btn" id="exportExcelButton">Export Excel</button>
            <a class="recycle-bin-action no-print" href="recycle_bin.php">Deleted Applicants</a>
            <button type="button" class="btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
        </div>
        <div class="action-group ag">
            <a href="dashboard.php" class="btn">Dashboard</a>
        </div>
        <div class="action-group ag action-group-logout">
            <form method="post" action="logout.php" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn d">🚪 Logout</button>
            </form>
        </div>
    </div>
</form>
</section>

<script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
<script src="results_excel_export.js?v=<?php echo (int)filemtime(__DIR__ . '/results_excel_export.js'); ?>"></script>
<script>
    <?php
    $excelImportModule = __DIR__ . '/results_excel_import.js';
    if (is_readable($excelImportModule)) {
        readfile($excelImportModule);
    } else {
        echo 'window.ResultsExcelImportLoadError = "The results_excel_import.js file is missing or unreadable on the server.";';
    }
    ?>
</script>
<script>
    if (typeof window.showSiteAlert !== 'function') {
        const resultsAlertQueue = [];
        let resultsAlertOpen = false;
        window.showSiteAlert = function (message) {
            return new Promise(resolve => {
                resultsAlertQueue.push({ message: String(message ?? ''), resolve });
                showNextResultsAlert();
            });
        };
        function showNextResultsAlert() {
            if (resultsAlertOpen || !resultsAlertQueue.length) return;
            resultsAlertOpen = true;
            const request = resultsAlertQueue.shift();
            const previousFocus = document.activeElement;
            const backdrop = document.createElement('div');
            backdrop.className = 'results-alert-backdrop';
            const dialog = document.createElement('section');
            dialog.className = 'results-alert-dialog';
            dialog.setAttribute('role', 'alertdialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.setAttribute('aria-labelledby', 'resultsAlertTitle');
            dialog.setAttribute('aria-describedby', 'resultsAlertMessage');
            const title = document.createElement('h2');
            title.id = 'resultsAlertTitle';
            title.textContent = 'Notice';
            const body = document.createElement('p');
            body.id = 'resultsAlertMessage';
            body.textContent = request.message;
            const actions = document.createElement('div');
            actions.className = 'results-alert-actions';
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = 'OK';
            actions.appendChild(button);
            dialog.append(title, body, actions);
            backdrop.appendChild(dialog);
            document.body.appendChild(backdrop);
            const close = () => {
                if (!resultsAlertOpen) return;
                backdrop.remove();
                resultsAlertOpen = false;
                request.resolve();
                if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus();
                showNextResultsAlert();
            };
            button.addEventListener('click', close);
            backdrop.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    close();
                }
            });
            button.focus();
        }
    }
    if (typeof window.showSiteConfirm !== 'function') {
        window.showSiteConfirm = function (message, options = {}) {
            return new Promise(resolve => {
                const previousFocus = document.activeElement;
                const backdrop = document.createElement('div');
                backdrop.className = 'results-alert-backdrop';
                const dialog = document.createElement('section');
                dialog.className = 'results-alert-dialog';
                dialog.setAttribute('role', 'alertdialog');
                dialog.setAttribute('aria-modal', 'true');
                const title = document.createElement('h2');
                title.textContent = options.title || 'Please confirm';
                const body = document.createElement('p');
                body.textContent = String(message ?? '');
                const actions = document.createElement('div');
                actions.className = 'results-confirm-actions';
                const cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.textContent = options.cancelText || 'Cancel';
                const confirmButton = document.createElement('button');
                confirmButton.type = 'button';
                confirmButton.className = 'confirm-delete';
                confirmButton.textContent = options.confirmText || 'Delete';
                actions.append(cancelButton, confirmButton);
                dialog.append(title, body, actions);
                backdrop.appendChild(dialog);
                document.body.appendChild(backdrop);

                let finished = false;
                const close = confirmed => {
                    if (finished) return;
                    finished = true;
                    backdrop.remove();
                    resolve(confirmed);
                    if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus();
                };
                cancelButton.addEventListener('click', () => close(false));
                confirmButton.addEventListener('click', () => close(true));
                backdrop.addEventListener('keydown', event => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        close(false);
                    } else if (event.key === 'Tab') {
                        event.preventDefault();
                        const nextButton = document.activeElement === cancelButton
                            ? confirmButton
                            : cancelButton;
                        nextButton.focus();
                    }
                });
                cancelButton.focus();
            });
        };
    }
    if (typeof window.showSitePrompt !== 'function') {
        window.showSitePrompt = function (message, options = {}) {
            return new Promise(resolve => {
                const previousFocus = document.activeElement;
                const backdrop = document.createElement('div');
                backdrop.className = 'results-alert-backdrop';
                const dialog = document.createElement('section');
                dialog.className = 'results-alert-dialog';
                dialog.setAttribute('role', 'dialog');
                dialog.setAttribute('aria-modal', 'true');
                const title = document.createElement('h2');
                title.textContent = options.title || 'Enter a name';
                const body = document.createElement('p');
                body.textContent = String(message ?? '');
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'results-prompt-input';
                input.maxLength = Number.isInteger(options.maxLength) ? options.maxLength : 100;
                input.setAttribute('aria-label', options.inputLabel || 'Name');
                input.value = options.defaultValue || '';
                const actions = document.createElement('div');
                actions.className = 'results-confirm-actions';
                const cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.textContent = 'Cancel';
                const submitButton = document.createElement('button');
                submitButton.type = 'button';
                submitButton.className = 'prompt-submit';
                submitButton.textContent = options.confirmText || 'Add';
                actions.append(cancelButton, submitButton);
                dialog.append(title, body, input, actions);
                backdrop.appendChild(dialog);
                document.body.appendChild(backdrop);

                let finished = false;
                const close = value => {
                    if (finished) return;
                    finished = true;
                    backdrop.remove();
                    resolve(value);
                    if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus();
                };
                cancelButton.addEventListener('click', () => close(null));
                submitButton.addEventListener('click', () => close(input.value));
                input.addEventListener('keydown', event => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        close(input.value);
                    }
                });
                backdrop.addEventListener('keydown', event => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        close(null);
                    } else if (event.key === 'Tab') {
                        const focusable = [input, cancelButton, submitButton];
                        const index = focusable.indexOf(document.activeElement);
                        const nextIndex = (index + (event.shiftKey ? -1 : 1) + focusable.length) % focusable.length;
                        event.preventDefault();
                        focusable[nextIndex].focus();
                    }
                });
                input.focus();
            });
        };
    }

    const groupedData = <?php echo json_encode($groupedCandidates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const RECYCLE_BIN_CANDIDATES = <?php echo json_encode($recycleBinCandidates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const RECYCLE_BIN_DUPLICATE_CHECK_AVAILABLE = <?php echo $recycleBinDuplicateCheckAvailable ? 'true' : 'false'; ?>;
    let headerLabels = <?php echo json_encode($headerLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const savedResultsOffice = <?php echo json_encode($initialResultsOffice, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const savedResultsAccountType = <?php echo json_encode($initialResultsAccountType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    let activeTabOffice = savedResultsOffice || 'REGIONAL OFFICE';
    const allCandidates = Object.values(groupedData).flat();
    let activeAccountType = ['RO', 'FO'].includes(savedResultsAccountType)
        ? savedResultsAccountType
        : allCandidates.some(candidate => candidate.accountType === 'RO') ? 'RO'
            : allCandidates.some(candidate => candidate.accountType === 'FO') ? 'FO' : 'RO';
    let sheetTransition = '';
    const RATERS = <?php echo json_encode(rater_list(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const KNOWN_RATERS = new Set(RATERS);
    const MIN_SLOTS = 7;
    const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
    const hiddenTabs = new Set();
    const pendingRenames = [];
    let skipNextSync = false;
    let resultsDirty = false;
    let resultsChangeVersion = 0;
    let resultsSaveBusy = false;
    const addedRaters = {};
    const storedOrders = <?php echo json_encode($storedOrders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const DEFAULT_OFFICES = new Set(<?php echo json_encode($defaultOffices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
    const storedScopedOfficeTabs = <?php echo json_encode($storedScopedOfficeTabs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const deleteUndoStack = [];
    const deleteRedoStack = [];
    let pendingCandidateDeletes = [];
    const scopedOfficeTabs = {
        RO: new Set(storedScopedOfficeTabs.RO || []),
        FO: new Set(storedScopedOfficeTabs.FO || [])
    };

    window.__positionsToDelete = window.__positionsToDelete || [];

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    }

    function markResultsDirty() {
        resultsDirty = true;
        resultsChangeVersion++;
        const status = document.getElementById('resultsSaveStatus');
        if (status) {
            status.dataset.state = 'dirty';
            status.textContent = 'Unsaved changes — select Save All Changes.';
        }
    }

    function updateDeleteHistoryButtons() {
        const undoButton = document.getElementById('undoDeleteButton');
        const redoButton = document.getElementById('redoDeleteButton');
        if (undoButton) {
            undoButton.disabled = resultsSaveBusy || deleteUndoStack.length === 0;
            undoButton.textContent = deleteUndoStack.length > 1
                ? `↶ Undo (${deleteUndoStack.length})`
                : '↶ Undo';
            undoButton.title = undoButton.disabled
                ? 'Undo is available after a deletion and before saving.'
                : 'Undo the most recent deletion.';
        }
        if (redoButton) {
            redoButton.disabled = resultsSaveBusy || deleteRedoStack.length === 0;
            redoButton.textContent = deleteRedoStack.length > 1
                ? `↷ Redo (${deleteRedoStack.length})`
                : '↷ Redo';
            redoButton.title = redoButton.disabled
                ? 'Redo is available after you undo a deletion and before saving.'
                : 'Redo the most recently undone deletion.';
        }
    }

    function restoreRemovedCandidates(office, removedCandidates) {
        if (!Array.isArray(groupedData[office])) groupedData[office] = [];
        removedCandidates
            .slice()
            .sort((a, b) => a.index - b.index)
            .forEach(({ candidate, index }) => {
                groupedData[office].splice(Math.min(index, groupedData[office].length), 0, candidate);
            });
    }

    function undoLastDelete() {
        if (resultsSaveBusy || deleteUndoStack.length === 0) return;
        syncFromDom();
        const action = deleteUndoStack.pop();
        restoreRemovedCandidates(action.office, action.removedCandidates);

        if (action.type === 'candidate') {
            const restoredIds = action.removedCandidates
                .map(({ candidate }) => Number(candidate.id))
                .filter(id => id > 0);
            pendingCandidateDeletes = pendingCandidateDeletes.filter(item =>
                !restoredIds.includes(Number(item.id))
            );
        } else {
            window.__positionsToDelete = window.__positionsToDelete.filter(item => item !== action.positionDelete);
            if (action.storedRaterOrder) {
                if (!storedOrders[action.office]) storedOrders[action.office] = {};
                storedOrders[action.office][action.position] = action.storedRaterOrder;
            }
            Object.entries(action.addedRaterOrders).forEach(([key, raters]) => {
                addedRaters[key] = raters;
            });
        }

        const restoredType = action.removedCandidates.find(({ candidate }) =>
            candidate.accountType === 'RO' || candidate.accountType === 'FO'
        )?.candidate.accountType;
        if (restoredType) activeAccountType = restoredType;
        activeTabOffice = action.office;
        deleteRedoStack.push(action);
        markResultsDirty();
        skipNextSync = true;
        updateDeleteHistoryButtons();
        renderAll();
    }

    function redoLastDelete() {
        if (resultsSaveBusy || deleteRedoStack.length === 0) return;
        syncFromDom();
        const action = deleteRedoStack.pop();
        const list = groupedData[action.office] || [];

        if (action.type === 'candidate') {
            const availableIndexes = new Set(list.map((_, index) => index));
            const removedAgain = [];
            for (const item of action.removedCandidates) {
                const candidateId = Number(item.candidate.id) || 0;
                const index = candidateId > 0
                    ? list.findIndex((candidate, candidateIndex) =>
                        availableIndexes.has(candidateIndex) && Number(candidate.id) === candidateId
                    )
                    : list.findIndex((candidate, candidateIndex) =>
                        availableIndexes.has(candidateIndex)
                        && !Number(candidate.id)
                        && candidate.name === item.candidate.name
                        && candidate.position === item.candidate.position
                        && candidate.date === item.candidate.date
                        && candidate.accountType === item.candidate.accountType
                    );
                if (index < 0) break;
                availableIndexes.delete(index);
                removedAgain.push({ candidate: list[index], index });
            }
            if (removedAgain.length !== action.removedCandidates.length) {
                window.showSiteAlert('The applicant is no longer available to delete. The redo action was cancelled.');
                deleteUndoStack.push(action);
                updateDeleteHistoryButtons();
                return;
            }
            action.removedCandidates = removedAgain;
            const removedIndexes = new Set(removedAgain.map(({ index }) => index));
            groupedData[action.office] = list.filter((candidate, index) => !removedIndexes.has(index));
            removedAgain
                .map(({ candidate }) => Number(candidate.id))
                .filter(candidateId => candidateId > 0)
                .forEach(candidateId => {
                    if (!pendingCandidateDeletes.some(item => Number(item.id) === candidateId)) {
                        const candidate = removedAgain.find(item => Number(item.candidate.id) === candidateId)?.candidate;
                        pendingCandidateDeletes.push({
                            id: candidateId,
                            office: action.office,
                            accountType: candidate?.accountType || '',
                        });
                    }
                });
        } else {
            const matchesPosition = candidate =>
                (candidate.position?.trim() || 'UNASSIGNED') === action.position
                && (candidate.accountType || '') === action.positionDelete.accountType
                && (candidate.salaryGrade == null ? null : Number(candidate.salaryGrade)) === action.positionDelete.salaryGrade;
            action.removedCandidates = list
                .map((candidate, index) => ({ candidate, index }))
                .filter(({ candidate }) => matchesPosition(candidate));
            action.storedRaterOrder = storedOrders[action.office]?.[action.position]
                ? [...storedOrders[action.office][action.position]]
                : null;
            action.addedRaterOrders = Object.fromEntries(
                Object.entries(addedRaters)
                    .filter(([key]) => key.startsWith(action.office + '|' + action.position + '|'))
                    .map(([key, raters]) => [key, [...raters]])
            );
            groupedData[action.office] = list.filter(candidate => !matchesPosition(candidate));
            const hasOtherPositionCandidates = groupedData[action.office].some(candidate =>
                (candidate.position?.trim() || 'UNASSIGNED') === action.position
            );
            if (!hasOtherPositionCandidates && storedOrders[action.office]) {
                delete storedOrders[action.office][action.position];
            }
            if (!hasOtherPositionCandidates) {
                Object.keys(addedRaters).forEach(key => {
                    if (key.startsWith(action.office + '|' + action.position + '|')) delete addedRaters[key];
                });
            }
            if (!window.__positionsToDelete.includes(action.positionDelete)) {
                window.__positionsToDelete.push(action.positionDelete);
            }
        }

        deleteUndoStack.push(action);
        activeTabOffice = action.office;
        markResultsDirty();
        skipNextSync = true;
        updateDeleteHistoryButtons();
        renderAll();
    }

    function calculateAverage(...scores) {
        const validScores = scores.filter(score => score !== null && !isNaN(score) && score !== '' && score !== undefined);
        if (!validScores.length) return '#DIV/0!';
        return (validScores.reduce((total, score) => total + parseFloat(score), 0) / validScores.length).toFixed(2);
    }

    function formatVal(value) {
        if (value === null || value === undefined || isNaN(value) || value === '') return '';
        return Number(value).toFixed(2);
    }

    function updateHeaderLabel(element, key) { headerLabels[key] = element.innerText.replace(/[\r\n\t]/g, '').trim(); renderAll(); }
    function blockKey(office, position, date) { return office + '|' + position + '|' + date; }

    function raterColumns(candidates, key, office, position) {
        const stored = ((storedOrders[office] || {})[position]) || [];
        const ordered = [];
        const orderedSet = new Set();
        stored.concat(addedRaters[key] || []).forEach(rater => {
            if (!orderedSet.has(rater)) {
                orderedSet.add(rater);
                ordered.push(rater);
            }
        });
        const scored = new Set();
        candidates.forEach(candidate => Object.keys(candidate.scores || {}).forEach(rater => scored.add(rater)));
        const rest = Array.from(scored).filter(rater => !orderedSet.has(rater));
        const restSet = new Set(rest);
        return ordered.concat(
            RATERS.filter(rater => restSet.has(rater)),
            rest.filter(rater => !KNOWN_RATERS.has(rater))
        );
    }

    function buildRowHtml(candidate = null, rowNumber = 1, cols = [], slots = MIN_SLOTS, accountType = '', salaryGrade = null, office = '') {
        const id = candidate ? candidate.id : '';
        const name = candidate ? escapeHtml(candidate.name) : '';
        const regionalOffice = String(office || candidate?.office || '').trim().toUpperCase() === 'REGIONAL OFFICE';
        const rowAccountType = regionalOffice ? 'RO' : (candidate ? candidate.accountType : accountType);
        const rowSalaryGrade = candidate ? candidate.salaryGrade : salaryGrade;
        const showAccountTypeControl = candidate && candidate.id > 0 && !rowAccountType;
        const accountTypeControl = showAccountTypeControl
            ? `<select class="account-type-select no-print" aria-label="Assign account type for ${name}">
                ${rowAccountType ? '' : '<option value="" selected disabled>Assign account type</option>'}
                <option value="RO" ${rowAccountType === 'RO' ? 'selected' : ''}>Region</option>
                <option value="FO" ${rowAccountType === 'FO' ? 'selected' : ''}>Field</option>
            </select>`
            : '';
        let psychoCells = '';
        let potentialCells = '';
        const psychoValues = [];
        const potentialValues = [];
        for (let index = 0; index < slots; index++) {
            const rater = cols[index];
            if (rater) {
                const score = (candidate && candidate.scores && candidate.scores[rater]) || {};
                psychoValues.push(score.p);
                potentialValues.push(score.t);
                psychoCells += `<td contenteditable="true" data-kind="p" data-rater="${escapeHtml(rater)}">${formatVal(score.p)}</td>`;
                potentialCells += `<td contenteditable="true" data-kind="t" data-rater="${escapeHtml(rater)}">${formatVal(score.t)}</td>`;
            } else {
                psychoCells += '<td></td>';
                potentialCells += '<td></td>';
            }
        }
        const psychoAverage = candidate ? calculateAverage(...psychoValues) : '#DIV/0!';
        const potentialAverage = candidate ? calculateAverage(...potentialValues) : '#DIV/0!';
        return `
            <tr class="in" style="--i:${rowNumber - 1}" data-id="${id}" data-account-type="${escapeHtml(rowAccountType || '')}" data-salary-grade="${rowSalaryGrade ?? ''}" data-office-missing="${candidate && candidate.officeMissing ? '1' : '0'}">
                <td class="row-num-cell n" role="button" tabindex="0" aria-label="Delete row ${rowNumber}" onclick="handleRowDelete(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();handleRowDelete(this);}"><span class="row-number">${rowNumber}</span><span class="delete-icon" aria-hidden="true" title="Delete Row">×</span></td>
                <td class="text-left nm"><span class="candidate-name" contenteditable="true" data-field="name">${name}</span>${accountTypeControl}</td>
                ${psychoCells}
                <td class="col-green-ave g psycho-ave">${psychoAverage}</td>
                ${potentialCells}
                <td class="col-yellow-ave y potential-ave">${potentialAverage}</td>
            </tr>
        `;
    }

    function buildTableHeaderHtml(currentDate, branch, position, cols, slots, accountType = '', salaryGrade = null) {
        const raterHeaders = () => {
            let html = '';
            for (let index = 0; index < slots; index++) {
                if (cols[index]) html += `<th style="width: 62px;">${escapeHtml(cols[index])}<span class="rater-remove rx" role="button" tabindex="0" aria-label="Remove rater column ${escapeHtml(cols[index])}" data-rater="${escapeHtml(cols[index])}" title="Remove this column" onclick="removeRater(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();removeRater(this);}">×</span></th>`;
                else html += '<th style="width: 62px;"></th>';
            }
            return html;
        };
        const totalCols = 2 + (slots + 1) * 2;
        const escapedOffice = escapeHtml(branch).replace(/'/g, "\\'").replace(/"/g, '&quot;');
        const escapedPosition = escapeHtml(position).replace(/'/g, "\\'").replace(/"/g, '&quot;');
        return `
            <tr>
                <td colspan="3" class="text-left meta-row dt" contenteditable="true" data-field="interview_date" style="border-right: none;"><b>DATE OF INTERVIEW:</b> ${escapeHtml(currentDate)}</td>
                <td colspan="${totalCols - 3}" class="position-cell pc" style="border-left: none;">POSITION TITLE: ${escapeHtml(position)}<button type="button" class="pos-delete-btn no-print" aria-label="Delete this entire position table" title="Delete this entire position table" onclick="deletePositionGroup(this, &quot;${escapedOffice}&quot;, &quot;${escapedPosition}&quot;, &quot;${accountType}&quot;, &quot;${salaryGrade ?? ''}&quot;)">×</button></td>
            </tr>
            <tr>
                <th class="n" style="width: 30px;">${escapeHtml(headerLabels.hash)}</th>
                <th class="nm" style="width: 230px;" contenteditable="true" onblur="updateHeaderLabel(this,'name')">${escapeHtml(headerLabels.name)}</th>
                ${raterHeaders()}
                <th class="col-green-ave g" style="width: 55px; border-left: 2px solid #000 !important;" contenteditable="true" onblur="updateHeaderLabel(this,'psychoAve')">${escapeHtml(headerLabels.psychoAve)}</th>
                ${raterHeaders()}
                <th class="col-yellow-ave y" style="width: 55px; border-left: 2px solid #000 !important;" contenteditable="true" onblur="updateHeaderLabel(this,'potentialAve')">${escapeHtml(headerLabels.potentialAve)}</th>
            </tr>
        `;
    }
    function buildTableBlockHtml(candidates, currentDate, branch, posKey, accountType = '', salaryGrade = null) {
        const cols = raterColumns(candidates, blockKey(branch, posKey, currentDate), branch, posKey);
        const slots = Math.max(MIN_SLOTS, cols.length + 1);
        const free = RATERS.filter(r => !cols.includes(r));
        let rowsHtml = '';
        const minRows = Math.max(5, candidates.length);
        for (let i = 0; i < minRows; i++) rowsHtml += buildRowHtml(candidates[i] || null, i + 1, cols, slots, accountType, salaryGrade, branch);
        const positionAttr = posKey ? ` data-position="${escapeHtml(posKey)}"` : '';
        const accountTypeAttr = accountType ? ` data-account-type="${escapeHtml(accountType)}"` : '';
        const salaryGradeAttr = salaryGrade !== null ? ` data-salary-grade="${salaryGrade}"` : '';

        return `
            <div class="table-block blk"${positionAttr}${accountTypeAttr}${salaryGradeAttr}>
                <div class="mobile-sheet-scroll">
                    <button type="button" data-sheet-scroll="-1" aria-label="Scroll scoring sheet left" disabled>‹</button>
                    <span>Swipe horizontally or tap the arrows to view all columns</span>
                    <button type="button" data-sheet-scroll="1" aria-label="Scroll scoring sheet right">›</button>
                </div>
                <div class="table-scroll sc" tabindex="0" role="region" aria-label="Scrollable scoring sheet. Swipe horizontally to see all columns.">
                    <table class="excel-table">
                        <thead>
                            ${buildTableHeaderHtml(currentDate, branch, posKey, cols, slots, accountType, salaryGrade)}
                        </thead>
                        <tbody class="scores-body" data-cols="${escapeHtml(JSON.stringify(cols))}" data-slots="${slots}">
                            ${rowsHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    function updateSheetScrollControls(block) {
        const scroller = block.querySelector('.table-scroll');
        if (!scroller) return;
        const maxScroll = Math.max(0, scroller.scrollWidth - scroller.clientWidth);
        block.classList.toggle('no-horizontal-overflow', maxScroll < 2);
        const leftButton = block.querySelector('[data-sheet-scroll="-1"]');
        const rightButton = block.querySelector('[data-sheet-scroll="1"]');
        if (leftButton) leftButton.disabled = scroller.scrollLeft <= 1;
        if (rightButton) rightButton.disabled = scroller.scrollLeft >= maxScroll - 1;
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-sheet-scroll]');
        if (!button) return;
        const block = button.closest('.table-block');
        const scroller = block?.querySelector('.table-scroll');
        if (!scroller) return;
        const direction = Number(button.dataset.sheetScroll);
        scroller.scrollBy({
            left: direction * Math.max(220, scroller.clientWidth * 0.72),
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
    });

    document.getElementById('tables-wrapper').addEventListener('scroll', event => {
        if (event.target.matches('.table-scroll')) {
            updateSheetScrollControls(event.target.closest('.table-block'));
        }
    }, true);

    document.getElementById('tables-wrapper').addEventListener('change', event => {
        const select = event.target.closest('.account-type-select');
        if (!select) return;
        const accountType = select.value;
        if (accountType !== 'RO' && accountType !== 'FO') return;
        const row = select.closest('tr.in');
        if (!row) return;
        row.setAttribute('data-account-type', accountType);
        syncFromDom();
        activeAccountType = accountType;
        markResultsDirty();
        renderAll();
    });

    window.addEventListener('resize', () => {
        document.querySelectorAll('#tables-wrapper .table-block').forEach(updateSheetScrollControls);
    });

    function buildSignatoryBlock() {
        return `
            <div class="signatory-section sg">
            <div class="sig-box"><i class="sig-line"></i><b class="sig-title">HRMPSB SECRETARIAT</b><small class="sig-sub">Prepared By</small></div>
            <div class="sig-box"><i class="sig-line"></i><b class="sig-title">HRMPSB COMMITTEE MEMBER</b><small class="sig-sub">Attested By</small></div>
            <div class="sig-box"><i class="sig-line"></i><b class="sig-title">CHAIRPERSON, HRMPSB</b><small class="sig-sub">Approved By</small></div>
            </div>
        `;
    }

    function checkAndAutoAddRow(tbody) {
        const rows = tbody.querySelectorAll('tr');
        if (rows.length === 0) return;
        const lastRow = rows[rows.length - 1];
        const nameCell = lastRow.querySelector('[data-field="name"]');
        if (nameCell && nameCell.innerText.trim() !== "") {
            const cols = JSON.parse(tbody.dataset.cols || '[]');
            const slots = parseInt(tbody.dataset.slots, 10) || MIN_SLOTS;
            const block = tbody.closest('.table-block');
            const accountType = block?.getAttribute('data-account-type') || '';
            const rawSalaryGrade = block?.getAttribute('data-salary-grade') || '';
            const salaryGrade = /^\d+$/.test(rawSalaryGrade) ? Number(rawSalaryGrade) : null;
            const office = block?.closest('.table-container')?.getAttribute('data-office') || '';
            tbody.insertAdjacentHTML('beforeend', buildRowHtml(null, rows.length + 1, cols, slots, accountType, salaryGrade, office));
        }
    }

    function readDate(block) {
        const el = block.querySelector('[data-field="interview_date"]');
        const t = el ? el.innerText.replace('DATE OF INTERVIEW:', '').replace(/[\r\n\t]/g, ' ').trim() : '';
        return t;
    }

    function collectRows(officeFilter = null) {
        const out = [];
        document.querySelectorAll('.table-container').forEach(container => {
            const office = container.getAttribute('data-office') || 'REGIONAL OFFICE';
            if (officeFilter !== null && office !== officeFilter) return;
            container.querySelectorAll('.table-block').forEach(block => {
                const position = block.getAttribute('data-position') || 'UNASSIGNED';
                const date = readDate(block);
                block.querySelectorAll('.scores-body tr').forEach(row => {
                    const id = parseInt(row.getAttribute('data-id') || '0', 10) || 0;
                    const name = (row.querySelector('[data-field="name"]')?.innerText || '').replace(/[\r\n\t]/g, '').trim();
                    if (!(id > 0 || name !== '')) return;
                    const accountType = row.getAttribute('data-account-type') || '';
                    const rawSalaryGrade = row.getAttribute('data-salary-grade') || '';
                    const salaryGrade = /^\d+$/.test(rawSalaryGrade) ? Number(rawSalaryGrade) : null;
                    const scores = {};
                    row.querySelectorAll('td[data-rater]').forEach(td => {
                        const r = td.getAttribute('data-rater');
                        const v = td.innerText.trim();
                        if (!scores[r]) scores[r] = { p: null, t: null };
                        if (v !== '' && !isNaN(parseFloat(v))) scores[r][td.getAttribute('data-kind')] = parseFloat(v);
                    });
                    out.push({ row, office, position, date, id, name, accountType, salaryGrade, scores, officeMissing: row.getAttribute('data-office-missing') === '1' });
                });
            });
        });
        return out;
    }

    function syncFromDom(officeFilter = null) {
        const containers = document.querySelectorAll('.table-container');
        if (!containers.length) return;
        const fresh = {};
        collectRows(officeFilter).forEach(r => {
            if (!(r.office in groupedData)) return;
            (fresh[r.office] = fresh[r.office] || []).push({ id: r.id, name: r.name, position: r.position, office: r.office, date: r.date, accountType: r.accountType, salaryGrade: r.salaryGrade, scores: r.scores, officeMissing: r.officeMissing });
        });
        containers.forEach(c => {
            const o = c.getAttribute('data-office');
            if (officeFilter !== null && o !== officeFilter) return;
            if (o in groupedData) groupedData[o] = fresh[o] || [];
        });
    }

    async function removeRater(el) {
        const r = el.getAttribute('data-rater');
        const block = el.closest('.table-block');
        const office = block.closest('.table-container').getAttribute('data-office');
        const position = block.getAttribute('data-position') || 'UNASSIGNED';
        const accountType = block.getAttribute('data-account-type') || '';
        const rawSalaryGrade = block.getAttribute('data-salary-grade') || '';
        const salaryGrade = /^\d+$/.test(rawSalaryGrade) ? Number(rawSalaryGrade) : null;
        if (!await window.showSiteConfirm('Remove the "' + r + '" column for position "' + position + '"?\n\nIts scores will be deleted when you click Save All Changes.', { confirmText: 'Remove' })) return;
        syncFromDom();
        (groupedData[office] || []).forEach(c => {
            const pos = (c.position && c.position.trim() !== '') ? c.position.trim() : 'UNASSIGNED';
            if (pos === position && (c.accountType || '') === accountType
                && (c.salaryGrade == null ? null : Number(c.salaryGrade)) === salaryGrade && c.scores) delete c.scores[r];
        });
        const otherScopeHasScore = (groupedData[office] || []).some(c => {
            const pos = (c.position && c.position.trim() !== '') ? c.position.trim() : 'UNASSIGNED';
            return pos === position && ((c.accountType || '') !== accountType
                || (c.salaryGrade == null ? null : Number(c.salaryGrade)) !== salaryGrade) && c.scores && c.scores[r];
        });
        if (!otherScopeHasScore && storedOrders[office] && storedOrders[office][position]) {
            storedOrders[office][position] = storedOrders[office][position].filter(x => x !== r);
        }
        Object.keys(addedRaters).forEach(k => {
            if (k.indexOf(office + '|' + position + '|') === 0) addedRaters[k] = addedRaters[k].filter(x => x !== r);
        });
        markResultsDirty();
        skipNextSync = true;
        renderAll();
    }

    function updateAccountShortcuts() {
        const accountTypes = [
            { type: 'RO', button: document.getElementById('regionResultsButton'), label: 'Region' },
            { type: 'FO', button: document.getElementById('fieldResultsButton'), label: 'Field' },
        ];
        accountTypes.forEach(({ type, button, label }) => {
            if (!button) return;
            button.textContent = label;
            button.classList.toggle('active', activeAccountType === type);
            button.setAttribute('aria-pressed', activeAccountType === type ? 'true' : 'false');
        });
    }

    function switchAccountType(accountType) {
        if (accountType !== 'RO' && accountType !== 'FO') return;
        activeAccountType = accountType;
        const matchingOffice = Object.entries(groupedData).find(([, candidates]) =>
            candidates.some(candidate => candidate.accountType === activeAccountType));
        if (matchingOffice) activeTabOffice = matchingOffice[0];
        renderAll();
    }

    async function deletePositionGroup(btnEl, office, position, accountType = '', rawSalaryGrade = '') {
        if (resultsSaveBusy) {
            window.showSiteAlert('Wait for the current save to finish before deleting a position table.');
            return;
        }
        syncFromDom();
        const list = groupedData[office] || [];
        const matchesPosition = c => (c.position && c.position.trim() !== '') ? c.position.trim() === position : position === 'UNASSIGNED';
        const salaryGrade = /^\d+$/.test(rawSalaryGrade) ? Number(rawSalaryGrade) : null;
        const matchesSalaryGrade = c => (c.salaryGrade == null ? null : Number(c.salaryGrade)) === salaryGrade;
        const removedCandidates = list
            .map((candidate, index) => ({ candidate, index }))
            .filter(({ candidate }) => matchesPosition(candidate) && (candidate.accountType || '') === accountType && matchesSalaryGrade(candidate));
        const matching = removedCandidates.map(({ candidate }) => candidate);
        const savedCount = matching.filter(c => c.id > 0).length;
        const scopeLabel = accountType === 'FO' ? 'Field' : accountType === 'RO' ? 'Region' : 'Unclassified';
        const gradeLabel = salaryGrade === null ? '' : ` at SG ${salaryGrade}`;
        const preview = candidateDeletePreview(matching);
        const msg = `Delete the ${scopeLabel} "${position}"${gradeLabel} table for ${office}?\n\n${savedCount} saved applicant(s) and their scores will be moved to Deleted Applicants, where they can be restored.\n\nApplicants in this table:\n${preview}`;
        if (!await window.showSiteConfirm(msg)) return;
        if (resultsSaveBusy) {
            window.showSiteAlert('Wait for the current save to finish before deleting a position table.');
            return;
        }
        const positionDelete = { office: office, position: position, accountType: accountType, salaryGrade: salaryGrade };
        const storedRaterOrder = storedOrders[office]?.[position] ? [...storedOrders[office][position]] : null;
        const addedRaterOrders = Object.fromEntries(
            Object.entries(addedRaters)
                .filter(([key]) => key.startsWith(office + '|' + position + '|'))
                .map(([key, raters]) => [key, [...raters]])
        );
        markResultsDirty();
        groupedData[office] = list.filter(c => !matchesPosition(c) || (c.accountType || '') !== accountType || !matchesSalaryGrade(c));
        const hasOtherScopeRows = groupedData[office].some(c => matchesPosition(c));
        if (!hasOtherScopeRows && storedOrders[office] && storedOrders[office][position]) delete storedOrders[office][position];
        if (!hasOtherScopeRows) Object.keys(addedRaters).forEach(k => { if (k.indexOf(office + '|' + position + '|') === 0) delete addedRaters[k]; });
        window.__positionsToDelete.push(positionDelete);
        deleteUndoStack.push({ type: 'position', office, position, removedCandidates, positionDelete, storedRaterOrder, addedRaterOrders });
        deleteRedoStack.length = 0;
        updateDeleteHistoryButtons();
        skipNextSync = true;
        renderAll();
        if (savedCount > 0) window.showSiteAlert('Removed from view.\n\nClick "Save All Changes" to move these applicants to Deleted Applicants, where they can be restored.');
    }

    function candidateDeletePreview(candidates) {
        const names = candidates.map(candidate => candidate.name).filter(Boolean);
        const preview = names.slice(0, 8).map(name => `• ${name}`).join('\n');
        const more = names.length > 8 ? `\n…and ${names.length - 8} more.` : '';
        return `${preview || 'No named applicants.'}${more}`;
    }

    function renderAll() {
        const transitionClass = sheetTransition;
        const update = () => renderAllNow(
            typeof document.startViewTransition === 'function' ? '' : transitionClass
        );
        if (typeof window.runUITransition === 'function') {
            const transition = transitionClass === 'sr'
                ? 'sheet-reverse'
                : transitionClass === 'sl' ? 'sheet-forward' : 'content';
            return window.runUITransition(update, transition);
        }
        return update();
    }

    function renderAllNow(transitionClass = '') {
        if (!skipNextSync) syncFromDom();
        skipNextSync = false;
        const tabsBar = document.getElementById('tabs');
        const wrapper = document.getElementById('tables-wrapper');
        document.getElementById('results').dataset.accountView = activeAccountType || 'UNCLASSIFIED';
        const previousIndicator = tabsBar.querySelector('.ind');
        const indicatorLeft = previousIndicator ? previousIndicator.style.left : '0px';
        const indicatorWidth = previousIndicator ? previousIndicator.style.width : '0px';
        tabsBar.innerHTML = '';
        const indicator = document.createElement('span');
        indicator.className = 'ind';
        indicator.setAttribute('aria-hidden', 'true');
        indicator.style.left = indicatorLeft;
        indicator.style.width = indicatorWidth;
        tabsBar.appendChild(indicator);
        wrapper.innerHTML = '';
        const branchKeys = Object.keys(groupedData).filter(branch => {
            if (branch === 'REGIONAL OFFICE') return activeAccountType === 'RO';
            const candidates = groupedData[branch] || [];
            return DEFAULT_OFFICES.has(branch)
                || Object.prototype.hasOwnProperty.call(storedOrders, branch)
                || candidates.some(candidate =>
                    !candidate.accountType || candidate.accountType === activeAccountType
                )
                || scopedOfficeTabs[activeAccountType].has(branch);
        });
        if (branchKeys.length > 0 && !branchKeys.includes(activeTabOffice)) activeTabOffice = branchKeys[0];
        const officePicker = document.getElementById('officeTabSelect');
        if (officePicker) {
            officePicker.replaceChildren();
            branchKeys.forEach(branch => {
                const option = document.createElement('option');
                option.value = branch;
                option.textContent = branch;
                officePicker.appendChild(option);
            });
            officePicker.value = activeTabOffice;
            officePicker.disabled = branchKeys.length === 0;
        }
        updateAccountShortcuts();

        branchKeys.forEach(branch => {
            const candidates = groupedData[branch] || [];
            const tab = document.createElement('div');
            tab.className = `tab ${branch === activeTabOffice ? 'active' : ''}`;
            tab.setAttribute('role', 'tab');
            tab.setAttribute('tabindex', branch === activeTabOffice ? '0' : '-1');
            tab.setAttribute('aria-selected', branch === activeTabOffice ? 'true' : 'false');
            tab.onclick = () => switchTab(branch);
            tab.onkeydown = (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    switchTab(branch);
                }
            };
            const tabLabel = document.createElement('span');
            tabLabel.innerText = branch;
            tab.appendChild(tabLabel);
            if (branch !== 'REGIONAL OFFICE') {
                const closeBtn = document.createElement('button');
                closeBtn.type = 'button';
                closeBtn.className = 'tab-close-btn';
                closeBtn.innerText = '×';
                closeBtn.setAttribute('aria-label', `Delete ${activeAccountType === 'FO' ? 'Field' : 'Region'} applicants for ${branch}`);
                closeBtn.title = `Delete ${activeAccountType === 'FO' ? 'Field' : 'Region'} applicants for ${branch}`;
                closeBtn.onclick = (e) => { e.stopPropagation(); e.preventDefault(); deleteTab(branch); };
                closeBtn.onkeydown = (e) => { e.stopPropagation(); };
                tab.appendChild(closeBtn);
            }
            tabsBar.appendChild(tab);

            const container = document.createElement('div');
            container.className = `table-container sheet ${branch === activeTabOffice ? `active ${transitionClass}` : ''}`;
            container.setAttribute('data-office', branch);

            const headerScope = activeAccountType === 'FO' ? 'FIELD ACCOUNT RESULTS'
                : activeAccountType === 'RO' ? 'REGION ACCOUNT RESULTS' : 'UNCLASSIFIED RESULTS';

            let docHeaderHtml = `
                <div class="doc-header dh">
                    <div class="gov-title a">Republic of the Philippines</div>
                    <div class="agency-title b">DEPARTMENT OF ENVIRONMENT AND NATURAL RESOURCES</div>
                    <div class="office-sub c">${escapeHtml(branch)}</div>
                    <div class="account-scope">${headerScope}</div>
                    <div class="doc-name d">MASTER INTERVIEW SUMMARY RATING SHEET</div>
                </div>
            `;

            const positionGroups = {};
            candidates.forEach(cand => {
                const posKey = (cand.position && cand.position.trim() !== '') ? cand.position.trim() : 'UNASSIGNED';
                const accountType = cand.accountType === 'RO' || cand.accountType === 'FO' ? cand.accountType : '';
                const salaryGrade = cand.salaryGrade == null ? null : Number(cand.salaryGrade);
                const groupKey = JSON.stringify([accountType, salaryGrade, posKey]);
                if (!positionGroups[groupKey]) positionGroups[groupKey] = { position: posKey, accountType, salaryGrade, candidates: [] };
                positionGroups[groupKey].candidates.push(cand);
            });
            const hasActiveCandidates = candidates.some(candidate => candidate.accountType === activeAccountType);
            if (!hasActiveCandidates) {
                const groupKey = JSON.stringify([activeAccountType, null, 'UNASSIGNED']);
                positionGroups[groupKey] = { position: 'UNASSIGNED', accountType: activeAccountType, salaryGrade: null, candidates: [] };
            }
            if (Object.keys(positionGroups).length === 0) {
                const groupKey = JSON.stringify([activeAccountType, null, 'UNASSIGNED']);
                positionGroups[groupKey] = { position: 'UNASSIGNED', accountType: activeAccountType, salaryGrade: null, candidates: [] };
            }

            const sectionsByType = {};
            Object.values(positionGroups).forEach(positionGroup => {
                const { position: posKey, accountType, salaryGrade, candidates: candsForPos } = positionGroup;
                const dateGroups = {};
                candsForPos.forEach(cand => {
                    const dateKey = cand.date ? cand.date.trim() : '';
                    if (!dateGroups[dateKey]) dateGroups[dateKey] = [];
                    dateGroups[dateKey].push(cand);
                });
                if (Object.keys(dateGroups).length === 0) dateGroups["<?php echo date('F d, Y'); ?>"] = [];
                Object.keys(dateGroups).forEach(dateKey => {
                    const sectionType = accountType || 'UNCLASSIFIED';
                    if (!sectionsByType[sectionType]) sectionsByType[sectionType] = '';
                    sectionsByType[sectionType] += buildTableBlockHtml(dateGroups[dateKey], dateKey, branch, posKey, accountType, salaryGrade);
                });
            });

            const activeSectionType = activeAccountType || 'UNCLASSIFIED';
            if (!sectionsByType[activeSectionType] && !sectionsByType.UNCLASSIFIED) {
                const emptyText = activeAccountType === 'FO' ? 'No Field-account results in this office yet.'
                    : 'No Region-account results in this office yet.';
                sectionsByType[activeSectionType] = `<div class="account-empty-state">${emptyText}</div>`;
            }
            let stackHtml = docHeaderHtml;
            Object.entries(sectionsByType).forEach(([sectionType, sectionHtml]) => {
                const sectionLabel = sectionType === 'UNCLASSIFIED'
                    ? '<h3 class="account-scope">Account type not recorded — assign Region or Field for each candidate below.</h3>'
                    : '';
                stackHtml += `<div class="account-result-section" data-account-type="${sectionType}">${sectionLabel}${sectionHtml}</div>`;
            });
            stackHtml += buildSignatoryBlock();
            container.innerHTML = stackHtml;
            wrapper.appendChild(container);
            container.querySelectorAll('.table-block').forEach(updateSheetScrollControls);
            container.querySelectorAll('.scores-body').forEach(tbody => checkAndAutoAddRow(tbody));
        });

        const selectedTab = tabsBar.querySelector('.tab[aria-selected="true"]');
        if (selectedTab) {
            indicator.style.left = `${selectedTab.offsetLeft}px`;
            indicator.style.width = `${selectedTab.offsetWidth}px`;
            if (window.matchMedia('(max-width: 600px)').matches) {
                selectedTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }
        }
        sheetTransition = '';
    }

    function switchTab(branch) {
        const branches = Object.keys(groupedData);
        const currentIndex = branches.indexOf(activeTabOffice);
        const nextIndex = branches.indexOf(branch);
        sheetTransition = nextIndex > currentIndex ? 'sl' : nextIndex < currentIndex ? 'sr' : '';
        syncFromDom(activeTabOffice);
        activeTabOffice = branch;
        document.querySelectorAll('#tabs .tab').forEach(tab => {
            const isActive = tab.firstElementChild?.textContent === branch;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.setAttribute('tabindex', isActive ? '0' : '-1');
        });
        document.querySelectorAll('#tables-wrapper .table-container').forEach(container => {
            const isActive = container.getAttribute('data-office') === branch;
            container.classList.toggle('active', isActive);
            container.classList.remove('sl', 'sr');
            if (isActive && sheetTransition) container.classList.add(sheetTransition);
        });
        const officePicker = document.getElementById('officeTabSelect');
        if (officePicker) officePicker.value = branch;
        const indicator = document.querySelector('#tabs .ind');
        const selectedTab = document.querySelector('#tabs .tab.active');
        if (indicator && selectedTab) {
            indicator.style.left = `${selectedTab.offsetLeft}px`;
            indicator.style.width = `${selectedTab.offsetWidth}px`;
        }
        document.querySelectorAll('#tables-wrapper .table-container.active .table-block')
            .forEach(updateSheetScrollControls);
        sheetTransition = '';
    }
    function searchCandidate() {
        const query = document.getElementById('candidateSearch').value.trim().toLowerCase();
        if (query === '') return;
        const searchableText = candidate => [
            candidate.name,
            candidate.position,
            candidate.office,
            candidate.accountType,
            candidate.salaryGrade === null ? '' : 'sg ' + candidate.salaryGrade
        ].join(' ').toLowerCase();
        for (const branch of Object.keys(groupedData)) {
            const match = (groupedData[branch] || []).find(candidate => searchableText(candidate).includes(query));
            if (match) {
                activeTabOffice = branch;
                renderAll();
                setTimeout(() => {
                    const rows = document.querySelectorAll('.scores-body tr');
                    for (const row of rows) {
                        const nameCell = row.querySelector('[data-field="name"]');
                        const rowText = [
                            row.innerText || '',
                            branch,
                            match.accountType || '',
                            match.salaryGrade === null ? '' : 'sg ' + match.salaryGrade
                        ].join(' ').toLowerCase();
                        if (nameCell && rowText.includes(query)) {
                            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            row.style.backgroundColor = '#fff3b0';
                            setTimeout(() => { row.style.backgroundColor = ''; }, 2500);
                            break;
                        }
                    }
                }, 50);
                return;
            }
        }
        window.showSiteAlert('Candidate not found.');
    }
    document.getElementById('candidateSearch').addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchCandidate();
        }
    });

    async function deleteTab(branch) {
        if (resultsSaveBusy) {
            window.showSiteAlert('Wait for the current save to finish before deleting applicants.');
            return;
        }
        syncFromDom();
        const accountType = activeAccountType === 'FO' ? 'FO' : 'RO';
        const scopeLabel = accountType === 'FO' ? 'Field' : 'Region';
        const removedCandidates = (groupedData[branch] || [])
            .map((candidate, index) => ({ candidate, index }))
            .filter(({ candidate }) => candidate.accountType === accountType);
        if (!removedCandidates.length) {
            if (scopedOfficeTabs[accountType].delete(branch)) {
                markResultsDirty();
                skipNextSync = true;
                renderAll();
                return;
            }
            window.showSiteAlert(`There are no ${scopeLabel} applicants to delete for ${branch}.`);
            return;
        }
        const saved = removedCandidates.filter(({ candidate }) => candidate.id > 0).length;
        const msg = `Delete ${removedCandidates.length} ${scopeLabel} applicant(s) for "${branch}"?\n\n${saved} saved applicant(s) will move to Deleted Applicants when you select Save All Changes. Applicants in the other account type will remain. You can undo before saving.\n\nApplicants to remove:\n${candidateDeletePreview(removedCandidates.map(item => item.candidate))}`;
        if (!await window.showSiteConfirm(msg)) return;
        if (resultsSaveBusy) {
            window.showSiteAlert('Wait for the current save to finish before deleting applicants.');
            return;
        }
        syncFromDom();
        const officeCandidates = groupedData[branch] || [];
        const scopedCandidates = officeCandidates
            .map((candidate, index) => ({ candidate, index }))
            .filter(({ candidate }) => candidate.accountType === accountType);
        if (!scopedCandidates.length) {
            if (scopedOfficeTabs[accountType].delete(branch)) {
                markResultsDirty();
                skipNextSync = true;
                renderAll();
                return;
            }
            window.showSiteAlert(`There are no ${scopeLabel} applicants to delete for ${branch}.`);
            return;
        }
        deleteUndoStack.push({ type: 'candidate', office: branch, removedCandidates: scopedCandidates });
        deleteRedoStack.length = 0;
        const scopedIds = scopedCandidates
            .map(({ candidate }) => ({
                id: Number(candidate.id),
                office: branch,
                accountType,
            }))
            .filter(item => item.id > 0);
        scopedIds.forEach(item => {
            if (!pendingCandidateDeletes.some(existing => Number(existing.id) === item.id)) {
                pendingCandidateDeletes.push(item);
            }
        });
        scopedOfficeTabs[accountType].delete(branch);
        groupedData[branch] = officeCandidates.filter(candidate => candidate.accountType !== accountType);
        if (branch !== 'REGIONAL OFFICE' && groupedData[branch].length === 0
            && !Object.values(scopedOfficeTabs).some(offices => offices.has(branch))) {
            delete groupedData[branch];
        }
        activeTabOffice = branch;
        markResultsDirty();
        updateDeleteHistoryButtons();
        skipNextSync = true;
        renderAll();
    }

    async function promptNewTab() {
        const name = await window.showSitePrompt('Enter new PENRO or Branch Name:', {
            title: 'Add office / branch',
            inputLabel: 'PENRO or branch name',
            confirmText: 'Add',
            maxLength: 100,
        });
        if (name && name.trim() !== "") {
            const cleanName = name.trim().toUpperCase();
            if (!groupedData[cleanName]) groupedData[cleanName] = [];
            const candidates = groupedData[cleanName];
            const hasCurrentScope = candidates.some(candidate => candidate.accountType === activeAccountType);
            if (hasCurrentScope) {
                switchTab(cleanName);
                return;
            }
            scopedOfficeTabs[activeAccountType].add(cleanName);
            activeTabOffice = cleanName;
            markResultsDirty();
            skipNextSync = true;
            renderAll();
        }
    }

    function renameBranchTab(el) {
        const container = el.closest('.table-container');
        const oldBranch = container.getAttribute('data-office');
        const newBranch = el.innerText.replace('OFFICE/BRANCH:', '').replace(/[\r\n\t]/g, '').trim().toUpperCase();
        if (!newBranch || newBranch === oldBranch) { el.innerText = 'OFFICE/BRANCH: ' + oldBranch; return; }
        if (groupedData[newBranch]) { window.showSiteAlert('A tab named "' + newBranch + '" already exists.'); el.innerText = 'OFFICE/BRANCH: ' + oldBranch; return; }
        syncFromDom();
        markResultsDirty();
        pendingRenames.push({ from: oldBranch, to: newBranch });
        groupedData[newBranch] = groupedData[oldBranch] || [];
        groupedData[newBranch].forEach(candidate => {
            candidate.office = newBranch;
            candidate.officeMissing = false;
        });
        Object.values(scopedOfficeTabs).forEach(offices => {
            if (offices.delete(oldBranch)) offices.add(newBranch);
        });
        hiddenTabs.add(oldBranch);
        delete groupedData[oldBranch];
        if (activeTabOffice === oldBranch) activeTabOffice = newBranch;
        skipNextSync = true;
        renderAll();
    }

    async function handleRowDelete(cellEl) {
        if (resultsSaveBusy) {
            window.showSiteAlert('Wait for the current save to finish before deleting an applicant.');
            return;
        }
        const row = cellEl.closest('tr');
        const id = parseInt(row.getAttribute('data-id') || '0', 10) || 0;
        const candidateName = row.querySelector('[data-field="name"]')?.innerText.trim() || 'Unnamed applicant';
        const office = row.closest('.table-container')?.getAttribute('data-office') || 'REGIONAL OFFICE';
        const accountType = row.getAttribute('data-account-type') || '';
        const scopeLabel = accountType === 'FO' ? 'Field' : accountType === 'RO' ? 'Region' : 'Unclassified';
        const recoveryNote = id > 0
            ? 'The applicant will move to Deleted Applicants when you save and can be restored.'
            : 'This unsaved row will be removed; it has not been stored in the database.';
        if (await window.showSiteConfirm(`Delete ${candidateName} from ${scopeLabel} / ${office}?\n\n${recoveryNote}`)) {
            if (resultsSaveBusy) {
                window.showSiteAlert('Wait for the current save to finish before deleting an applicant.');
                return;
            }
            if (id > 0) {
                syncFromDom();
                const list = groupedData[office] || [];
                const index = list.findIndex(candidate => Number(candidate.id) === id);
                if (index < 0) {
                    window.showSiteAlert('The applicant could not be removed from the current results. Refresh the page and try again.');
                    return;
                }
                const [candidate] = list.splice(index, 1);
                groupedData[office] = list;
                pendingCandidateDeletes.push({
                    id,
                    office,
                    accountType: candidate.accountType || '',
                });
                deleteUndoStack.push({ type: 'candidate', office, candidate, removedCandidates: [{ candidate, index }] });
                deleteRedoStack.length = 0;
                markResultsDirty();
                updateDeleteHistoryButtons();
                skipNextSync = true;
                renderAll();
            } else {
                const tbody = row.closest('tbody');
                row.remove();
                reindexTableRows(tbody);
            }
        }
    }

    function reindexTableRows(tbody) {
        Array.from(tbody.children).forEach((tr, index) => {
            const numSpan = tr.querySelector('.row-number');
            if (numSpan) numSpan.innerText = index + 1;
        });
        checkAndAutoAddRow(tbody);
    }

    async function sendResultsSave(payload) {
        const response = await fetch('save_score.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (error) {
            throw new Error('Server returned non-JSON. First 300 chars: ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300));
        }
        if (data.duplicate_warning && payload.confirmDuplicates !== true) {
            if (!await window.showSiteConfirm(data.message || 'Possible duplicate applicants were found. Continue saving?')) {
                return { status: 'cancelled' };
            }
            return sendResultsSave({ ...payload, confirmDuplicates: true });
        }
        if (!response.ok && data.status === 'success') {
            throw new Error('The server returned a success response with an error status.');
        }
        return data;
    }

    function recalculateRow(row) {
        const pv = Array.from(row.querySelectorAll('td[data-kind="p"]')).map(td => td.innerText.trim());
        const tv = Array.from(row.querySelectorAll('td[data-kind="t"]')).map(td => td.innerText.trim());
        const psychoAveEl = row.querySelector('.psycho-ave');
        const potentialAveEl = row.querySelector('.potential-ave');
        if (psychoAveEl) psychoAveEl.innerText = calculateAverage(...pv);
        if (potentialAveEl) potentialAveEl.innerText = calculateAverage(...tv);
    }

    function validInterviewDate(value) {
        const text = String(value || '').trim();
        let parts = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (!parts) parts = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (parts) {
            const year = Number(parts[3] && text.includes('/') ? parts[3] : parts[1]);
            const month = Number(parts[3] && text.includes('/') ? parts[1] : parts[2]);
            const day = Number(parts[3] && text.includes('/') ? parts[2] : parts[3]);
            const date = new Date(Date.UTC(year, month - 1, day));
            return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
        }
        const natural = text.match(/^([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4})$/);
        if (!natural) return false;
        const parsed = new Date(`${natural[1]} 1, ${natural[3]} UTC`);
        if (Number.isNaN(parsed.getTime())) return false;
        const month = parsed.getUTCMonth();
        const year = Number(natural[3]);
        const day = Number(natural[2]);
        const date = new Date(Date.UTC(year, month, day));
        return date.getUTCFullYear() === year && date.getUTCMonth() === month && date.getUTCDate() === day;
    }

    function resultsValidationErrors() {
        const errors = [];
        document.querySelectorAll('.table-container').forEach(container => {
            const office = (container.getAttribute('data-office') || '').trim();
            container.querySelectorAll('.table-block').forEach(block => {
                const dateElement = block.querySelector('[data-field="interview_date"]');
                const interviewDate = dateElement
                    ? dateElement.innerText.replace('DATE OF INTERVIEW:', '').replace(/[\r\n\t]/g, ' ').trim()
                    : '';
                const normalizedOffice = office.toUpperCase();
                block.querySelectorAll('.scores-body tr').forEach((row, index) => {
                    const id = Number(row.getAttribute('data-id')) || 0;
                    const accountType = row.getAttribute('data-account-type')
                        || block.getAttribute('data-account-type') || '';
                    const name = (row.querySelector('[data-field="name"]')?.innerText || '').replace(/[\r\n\t]/g, '').trim();
                    const scoreCells = Array.from(row.querySelectorAll('td[data-rater]'));
                    const hasScore = scoreCells.some(cell => cell.innerText.trim() !== '');
                    if (!(id > 0 || name || hasScore)) return;
                    const rowLabel = name || `row ${index + 1}`;
                    if (!name) errors.push(`${office || 'Unknown office'}: ${rowLabel} is missing a name.`);
                    if (!office) errors.push(`${rowLabel} is missing an office.`);
                    if (row.getAttribute('data-office-missing') === '1') errors.push(`${rowLabel} is missing an office. Rename its office tab to the correct office before saving or exporting.`);
                    if (!validInterviewDate(interviewDate)) {
                        errors.push(`${rowLabel} must have a valid interview date.`);
                    }
                    if (!['RO', 'FO'].includes(accountType)) {
                        errors.push(`${rowLabel} must be assigned to Region or Field.`);
                    } else if (normalizedOffice === 'REGIONAL OFFICE' && accountType !== 'RO') {
                        errors.push(`${rowLabel} is marked Field under Regional Office; check its account assignment.`);
                    }
                    scoreCells.forEach(cell => {
                        const rawValue = cell.innerText.trim();
                        if (!rawValue) return;
                        const score = Number(rawValue);
                        if (!Number.isFinite(score) || score < 0 || score > 10) {
                            errors.push(`${rowLabel} has an invalid score (${rawValue}); scores must be from 0 to 10.`);
                        }
                    });
                });
            });
        });
        return [...new Set(errors)];
    }

    function validateResultsBeforeAction(action) {
        const errors = resultsValidationErrors();
        if (!errors.length) return true;
        const preview = errors.slice(0, 8).map(error => '- ' + error).join('\n');
        const remaining = errors.length > 8 ? `\n…and ${errors.length - 8} more issue(s).` : '';
        window.showSiteAlert(`${action} stopped. Fix these data issues first:\n\n${preview}${remaining}`);
        return false;
    }

    function saveWholeTable(e) {
        if (e) e.preventDefault();
        if (resultsSaveBusy) return;
        if (!validateResultsBeforeAction('Save')) return;
        resultsSaveBusy = true;
        const submittedVersion = resultsChangeVersion;
        const submittedPositionsToDelete = window.__positionsToDelete.slice();
        const submittedCandidateIdsToDelete = pendingCandidateDeletes.slice();
        const submittedDeleteUndoActions = deleteUndoStack.slice();
        const submittedRenames = pendingRenames.slice();
        const saveButton = document.getElementById('saveResultsButton');
        updateDeleteHistoryButtons();
        const saveStatus = document.getElementById('resultsSaveStatus');
        if (saveButton) saveButton.disabled = true;
        if (saveStatus) {
            saveStatus.dataset.state = 'saving';
            saveStatus.textContent = 'Saving changes…';
        }
        const candidatesToSave = collectRows().map((r, i) => {
            r.row.setAttribute('data-temp-index', i);
            return { tempIndex: i, id: r.id, name: r.name, office: r.office, officeMissing: r.officeMissing, position: r.position, interviewDate: r.date, accountType: r.accountType, salaryGrade: r.salaryGrade, scores: r.scores };
        });

        const columnsToSave = [];
        document.querySelectorAll('.table-container').forEach(container => {
            const office = container.getAttribute('data-office') || 'REGIONAL OFFICE';
            container.querySelectorAll('.table-block').forEach(block => {
                const position = block.getAttribute('data-position') || 'UNASSIGNED';
                const tbody = block.querySelector('.scores-body');
                let raters = null;
                try { raters = JSON.parse((tbody && tbody.dataset.cols) || '[]'); } catch (err) { raters = null; }
                if (Array.isArray(raters)) columnsToSave.push({ office: office, position: position, raters: raters });
            });
        });

        const savePayload = {
            csrf_token: CSRF_TOKEN,
            candidates: candidatesToSave,
            candidateIdsToDelete: submittedCandidateIdsToDelete,
            columns: columnsToSave,
            tabs: Object.keys(groupedData),
            officeTabs: {
                RO: Array.from(scopedOfficeTabs.RO),
                FO: Array.from(scopedOfficeTabs.FO)
            },
            resultsView: {
                accountType: activeAccountType,
                office: activeTabOffice
            },
            hiddenTabs: Array.from(hiddenTabs),
            renames: submittedRenames,
            headers: headerLabels,
            positionsToDelete: submittedPositionsToDelete
        };
        sendResultsSave(savePayload)
        .then(data => {
            if (data.status === 'cancelled') {
                if (saveStatus) {
                    saveStatus.dataset.state = 'dirty';
                    saveStatus.textContent = 'Save cancelled. Your changes are still here.';
                }
                return;
            }
            if (data.status === 'success') {
                if (data.savedIds) {
                    data.savedIds.forEach(item => {
                        const targetRow = document.querySelector(`tr[data-temp-index="${item.tempIndex}"]`);
                        if (targetRow) targetRow.setAttribute('data-id', item.id);
                    });
                }
                window.__positionsToDelete = window.__positionsToDelete.filter(item => !submittedPositionsToDelete.includes(item));
                const submittedCandidateDeleteIds = submittedCandidateIdsToDelete.map(item => Number(item.id));
                pendingCandidateDeletes = pendingCandidateDeletes.filter(item =>
                    !submittedCandidateDeleteIds.includes(Number(item.id))
                );
                for (const action of submittedDeleteUndoActions) {
                    const index = deleteUndoStack.indexOf(action);
                    if (index !== -1) deleteUndoStack.splice(index, 1);
                }
                deleteRedoStack.length = 0;
                updateDeleteHistoryButtons();
                for (const rename of submittedRenames) {
                    const index = pendingRenames.indexOf(rename);
                    if (index !== -1) pendingRenames.splice(index, 1);
                }
                if (resultsChangeVersion === submittedVersion) {
                    resultsDirty = false;
                    if (saveStatus) {
                        saveStatus.dataset.state = 'saved';
                        saveStatus.textContent = 'All changes saved.';
                    }
                } else if (saveStatus) {
                    saveStatus.dataset.state = 'dirty';
                    saveStatus.textContent = 'Saved, but newer changes still need to be saved.';
                }
                let msg = 'All interview rating sheets saved successfully!';
                if (typeof data.savedColumns === 'number') msg += '\n' + data.savedColumns + ' rater column(s) stored.';
                if (typeof data.savedOfficeTabs === 'number') msg += '\n' + data.savedOfficeTabs + ' office/branch tab(s) stored.';
                if (typeof data.deletedPositions === 'number' && data.deletedPositions > 0) msg += '\n' + data.deletedPositions + ' position table(s) removed.';
                if (typeof data.deletedCandidates === 'number' && data.deletedCandidates > 0) msg += '\n' + data.deletedCandidates + ' applicant row(s) moved to Deleted Applicants.';
                if (typeof data.renamedOffices === 'number' && data.renamedOffices > 0) msg += '\n' + data.renamedOffices + ' office(s) renamed.';
                window.showSiteAlert(msg);
            } else {
                if (saveStatus) {
                    saveStatus.dataset.state = 'error';
                    saveStatus.textContent = 'Save failed. Your changes are still here; try again.';
                }
                window.showSiteAlert('Error saving: ' + (data.message || 'Unknown error'));
            }
        }).catch(err => {
            if (saveStatus) {
                saveStatus.dataset.state = 'error';
                saveStatus.textContent = 'Save failed. Your changes are still here; try again.';
            }
            window.showSiteAlert('Save request failed: ' + err.message);
        }).finally(() => {
            resultsSaveBusy = false;
            if (saveButton) saveButton.disabled = false;
            updateDeleteHistoryButtons();
        });
    }

    function exportExcel() {
        if (!validateResultsBeforeAction('Export')) return;
        syncFromDom();
        window.ResultsExcelExport.exportExcel({
            XLSX: window.XLSX,
            groupedData,
            headerLabels,
            raterColumns,
            blockKey,
            minSlots: MIN_SLOTS,
            todayDate: "<?php echo date('F d, Y'); ?>",
            syncFromDom
        });
    }

    async function importExcel(file) {
        syncFromDom();
        if (window.ResultsExcelImportLoadError) throw new Error(window.ResultsExcelImportLoadError);
        if (!window.ResultsExcelImport || typeof window.ResultsExcelImport.readWorkbook !== 'function') {
            throw new Error('The import module did not initialize. Refresh the page and check the results_excel_import.js file.');
        }
        const { candidates, invalidScores } = await window.ResultsExcelImport.readWorkbook(
            file,
            groupedData,
            "<?php echo date('F d, Y'); ?>",
            activeTabOffice
        );
        const selectedIndexes = await showImportPreview(candidates, invalidScores, file.name);
        if (!selectedIndexes || selectedIndexes.length === 0) return;
        const selectedCandidates = selectedIndexes.map(index => candidates[index]);
        const existingById = new Map(Object.values(groupedData).flat()
            .filter(candidate => Number(candidate.id) > 0)
            .map(candidate => [Number(candidate.id), candidate]));
        selectedCandidates.forEach(candidate => {
            const previous = existingById.get(Number(candidate.id));
            if (previous) {
                candidate.accountType = previous.accountType || '';
                candidate.salaryGrade = previous.salaryGrade ?? null;
            } else {
                candidate.accountType = candidate.accountTypeSpecified
                    ? candidate.accountType
                    : activeAccountType;
                candidate.salaryGrade = null;
            }
            if (String(candidate.office || '').trim().toUpperCase() === 'REGIONAL OFFICE') {
                candidate.accountType = 'RO';
            }
            delete candidate.accountTypeSpecified;
            if (candidate.id > 0) {
                Object.keys(groupedData).forEach(branch => {
                    groupedData[branch] = groupedData[branch].filter(existing => Number(existing.id) !== candidate.id);
                });
            }
            if (!groupedData[candidate.office]) groupedData[candidate.office] = [];
            groupedData[candidate.office].push(candidate);
        });

        skipNextSync = true;
        activeTabOffice = Object.keys(groupedData).includes(activeTabOffice) ? activeTabOffice : Object.keys(groupedData)[0];
        renderAll();
        markResultsDirty();
        const scoreNotice = invalidScores.length ? ' Invalid score values were skipped on row(s): ' + [...new Set(invalidScores)].join(', ') + '.' : '';
        window.showSiteAlert(selectedCandidates.length + ' candidate row(s) imported. Review the results, then select Save All Changes to store them.' + scoreNotice);
    }

    function showImportPreview(candidates, invalidScores, fileName) {
        return new Promise(resolve => {
            const existingCandidates = Object.values(groupedData).flat();
            const existingById = new Map(existingCandidates
                .filter(candidate => Number(candidate.id) > 0)
                .map(candidate => [Number(candidate.id), candidate]));
            const normalize = value => String(value ?? '').trim().toLowerCase().replace(/[^a-z0-9]/g, '');
            const identityKey = candidate => [
                normalize(candidate.name),
                normalize(candidate.office),
                normalize(candidate.position),
                candidate.accountType || '',
            ].join('|');
            const existingIdentities = new Set(existingCandidates.map(identityKey));
            const recycleBinIdentities = new Set(RECYCLE_BIN_CANDIDATES.map(identityKey));
            const seenIds = new Set();
            const seenIdentities = new Set();
            const rowInfo = candidates.map((candidate, index) => {
                const id = Number(candidate.id) || 0;
                const update = id > 0 && existingById.has(id);
                let duplicateReason = '';
                if (update) {
                    if (seenIds.has(id)) duplicateReason = 'Same record appears more than once in this file';
                    seenIds.add(id);
                } else {
                    const key = identityKey(candidate);
                    if (existingIdentities.has(key)) duplicateReason = 'Possible match already exists';
                    else if (recycleBinIdentities.has(key)) duplicateReason = 'Matching applicant is in Deleted Applicants';
                    else if (seenIdentities.has(key)) duplicateReason = 'Repeated in this file';
                    seenIdentities.add(key);
                }
                return { candidate, index, update, duplicateReason, selected: !duplicateReason };
            });

            const backdrop = document.createElement('div');
            backdrop.className = 'import-preview-backdrop';
            const dialog = document.createElement('section');
            dialog.className = 'import-preview-dialog';
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.setAttribute('aria-labelledby', 'importPreviewTitle');

            const header = document.createElement('header');
            header.className = 'import-preview-header';
            const headingWrap = document.createElement('div');
            const heading = document.createElement('h2');
            heading.id = 'importPreviewTitle';
            heading.textContent = 'Review spreadsheet import';
            const fileLabel = document.createElement('p');
            fileLabel.textContent = fileName;
            headingWrap.append(heading, fileLabel);
            header.appendChild(headingWrap);
            if (!RECYCLE_BIN_DUPLICATE_CHECK_AVAILABLE) {
                const recycleNotice = document.createElement('p');
                recycleNotice.textContent = 'Recycle-bin matches could not be checked. Ask an administrator to run php setup.php.';
                headingWrap.appendChild(recycleNotice);
            }

            const summary = document.createElement('div');
            summary.className = 'import-preview-summary';
            const summaryItems = [
                ['rows', candidates.length + ' rows'],
                ['new', rowInfo.filter(row => !row.update).length + ' new'],
                ['updates', rowInfo.filter(row => row.update).length + ' updates'],
                ['duplicates', rowInfo.filter(row => row.duplicateReason).length + ' possible duplicates'],
            ];
            summaryItems.forEach(([key, text]) => {
                const item = document.createElement('span');
                item.dataset.summary = key;
                item.textContent = text;
                summary.appendChild(item);
            });
            const selectedSummary = document.createElement('span');
            selectedSummary.dataset.summary = 'selected';
            summary.appendChild(selectedSummary);

            const invalidNotice = document.createElement('div');
            invalidNotice.className = 'import-preview-warning';
            invalidNotice.hidden = invalidScores.length === 0;
            invalidNotice.textContent = invalidScores.length
                ? `${invalidScores.length} invalid score cell(s) will be ignored.`
                : '';

            const tableWrap = document.createElement('div');
            tableWrap.className = 'import-preview-table-wrap';
            const table = document.createElement('table');
            table.className = 'import-preview-table';
            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');
            ['Import', 'Type', 'Applicant', 'Office', 'Account', 'Position', 'Review'].forEach(label => {
                const cell = document.createElement('th');
                cell.textContent = label;
                headerRow.appendChild(cell);
            });
            thead.appendChild(headerRow);
            const tbody = document.createElement('tbody');
            rowInfo.forEach(info => {
                const row = document.createElement('tr');
                const selectCell = document.createElement('td');
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.checked = info.selected;
                checkbox.setAttribute('aria-label', `Import ${info.candidate.name || 'unnamed applicant'}`);
                checkbox.addEventListener('change', () => {
                    info.selected = checkbox.checked;
                    updatePreviewSummary();
                });
                selectCell.appendChild(checkbox);
                const typeCell = document.createElement('td');
                const badge = document.createElement('span');
                badge.className = `import-preview-type${info.duplicateReason ? ' duplicate' : info.update ? ' update' : ''}`;
                badge.textContent = info.duplicateReason ? 'Review' : info.update ? 'Update' : 'New';
                typeCell.appendChild(badge);
                const nameCell = document.createElement('td');
                nameCell.textContent = info.candidate.name || 'Unnamed applicant';
                const officeCell = document.createElement('td');
                officeCell.textContent = info.candidate.office || 'Regional Office';
                const accountCell = document.createElement('td');
                accountCell.textContent = info.candidate.accountType === 'RO' ? 'Region'
                    : info.candidate.accountType === 'FO' ? 'Field' : 'Unassigned';
                const positionCell = document.createElement('td');
                positionCell.textContent = info.candidate.position || 'Unassigned';
                const reviewCell = document.createElement('td');
                reviewCell.textContent = info.duplicateReason || (info.update ? 'Existing record will be updated' : 'New applicant record');
                row.append(selectCell, typeCell, nameCell, officeCell, accountCell, positionCell, reviewCell);
                tbody.appendChild(row);
            });
            table.append(thead, tbody);
            tableWrap.appendChild(table);

            const footer = document.createElement('footer');
            footer.className = 'import-preview-footer';
            const cancelButton = document.createElement('button');
            cancelButton.type = 'button';
            cancelButton.textContent = 'Cancel';
            const importButton = document.createElement('button');
            importButton.type = 'button';
            importButton.className = 'import-preview-apply';
            const focusable = () => Array.from(dialog.querySelectorAll('button, input[type="checkbox"]'))
                .filter(element => !element.disabled && element.offsetParent !== null);
            let settled = false;
            const finish = selection => {
                if (settled) return;
                settled = true;
                document.removeEventListener('keydown', onKeydown);
                backdrop.remove();
                resolve(selection);
            };
            const updatePreviewSummary = () => {
                const selectedCount = rowInfo.filter(row => row.selected).length;
                selectedSummary.textContent = selectedCount + ' selected';
                importButton.textContent = `Import selected rows (${selectedCount})`;
                importButton.disabled = selectedCount === 0;
            };
            const onKeydown = event => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    finish(null);
                } else if (event.key === 'Tab') {
                    const elements = focusable();
                    if (!elements.length) return;
                    const first = elements[0];
                    const last = elements[elements.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            };
            cancelButton.addEventListener('click', () => finish(null));
            importButton.addEventListener('click', () => finish(rowInfo.filter(row => row.selected).map(row => row.index)));
            footer.append(cancelButton, importButton);
            dialog.append(header, summary, invalidNotice, tableWrap, footer);
            backdrop.appendChild(dialog);
            backdrop.addEventListener('click', event => {
                if (event.target === backdrop) finish(null);
            });
            document.addEventListener('keydown', onKeydown);
            document.body.appendChild(backdrop);
            updatePreviewSummary();
            cancelButton.focus();
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        renderAll();
        updateDeleteHistoryButtons();
        document.getElementById('exportExcelButton').addEventListener('click', exportExcel);
        document.getElementById('officeTabSelect')?.addEventListener('change', event => {
            if (event.target.value) switchTab(event.target.value);
        });
        const importFile = document.getElementById('excelImportFile');
        document.getElementById('importExcelButton').addEventListener('click', () => importFile.click());
        importFile.addEventListener('change', async () => {
            const file = importFile.files[0];
            if (!file) return;
            try {
                await importExcel(file);
            } catch (error) {
                window.showSiteAlert('Import failed: ' + error.message);
            } finally {
                importFile.value = '';
            }
        });
        document.getElementById('candidateSearch')?.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); searchCandidate(); } });
        document.addEventListener('input', function(e) {
            if (e.target.closest('#tables-wrapper') && e.target.isContentEditable) markResultsDirty();
            if (e.target.isContentEditable) {
                const row = e.target.closest('tr');
                if (row && !(e.target.classList.contains('psycho-ave') || e.target.classList.contains('potential-ave'))) recalculateRow(row);
                const tbody = e.target.closest('.scores-body');
                if (tbody) checkAndAutoAddRow(tbody);
            }
        });
        document.getElementById('resultsForm').addEventListener('change', event => {
            if (event.target.closest('#tables-wrapper')) markResultsDirty();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.isContentEditable) {
                e.preventDefault();
                e.target.blur();
                const row = e.target.closest('tr');
                if (row) {
                    const cellIndex = Array.from(row.children).indexOf(e.target);
                    const nextRow = row.nextElementSibling;
                    if (nextRow && nextRow.children[cellIndex]) nextRow.children[cellIndex].focus();
                }
            }
        });
    });
    let pendingDirtyNavigation = false;
    document.addEventListener('click', async event => {
        if (!resultsDirty || event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
            || !(event.target instanceof Element)) return;
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;

        const destination = new URL(link.href, window.location.href);
        if (destination.origin !== window.location.origin) return;
        if (destination.pathname === window.location.pathname
            && destination.search === window.location.search) return;

        event.preventDefault();
        if (pendingDirtyNavigation) return;
        pendingDirtyNavigation = true;
        try {
            const leave = await window.showSiteConfirm(
                resultsSaveBusy
                    ? 'A save is still in progress. Leave this page?'
                    : 'You have unsaved changes. Leave this page without saving?',
                { title: 'Unsaved changes', confirmText: 'Leave page', cancelText: 'Stay here' }
            );
            if (leave) window.location.assign(destination.href);
        } finally {
            pendingDirtyNavigation = false;
        }
    });

    window.addEventListener('beforeunload', event => {
        if (!resultsDirty) return;
        event.preventDefault();
        event.returnValue = '';
    });
</script>
</body>
</html>