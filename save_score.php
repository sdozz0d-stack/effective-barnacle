<?php
// ============================================================
// save_score.php — COMPLETE FIXED FILE
//
// FIX (Bug C): require_admin(true) — JSON-safe 401/403.
// FIX (Bug 2): office_name truncated for office_location.
// FIX (Bug 5): tab renames now UPDATE candidates.office_name
//              and .office_location so renamed tabs persist.
// FIX (Bug 6): every $conn->rollback() is guarded with a
//              try/catch so strict-mode mysqli cannot double-throw
//              after commit().
// FIX (Bug 22): $deletedPositions counts positions, not candidate
//               rows.
// ============================================================

ob_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_admin(true);
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/ApplicantValidation.php';

ensure_schema($conn);
$hasOfficeTypeColumn = raters_schema_has_column($conn, 'candidates', 'office_type');

function json_out(array $payload, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || strpos($contentType, 'application/json') === false) {
    json_out(['status' => 'error', 'message' => 'Invalid request.'], 400);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['candidates']) || !is_array($data['candidates'])) {
    json_out(['status' => 'error', 'message' => 'Invalid data payload.']);
}

if (!verify_csrf($data['csrf_token'] ?? null)) {
    json_out(['status' => 'error', 'message' => 'Your session expired. Please refresh the page.'], 403);
}

$candidateDeletions = $data['candidateIdsToDelete'] ?? [];
if (!is_array($candidateDeletions) || count($candidateDeletions) > 500) {
    json_out(['status' => 'error', 'message' => 'Invalid candidate deletion list.'], 400);
}
foreach ($candidateDeletions as $deletion) {
    if (!is_array($deletion)
        || !is_int($deletion['id'] ?? null)
        || $deletion['id'] < 1
        || !is_string($deletion['office'] ?? null)
        || trim($deletion['office']) === ''
        || !in_array(strtoupper(trim((string)($deletion['accountType'] ?? ''))), ['', 'RO', 'FO'], true)
    ) {
        json_out(['status' => 'error', 'message' => 'Invalid candidate deletion ID.'], 400);
    }
}
$uniqueCandidateDeletions = [];
foreach ($candidateDeletions as $deletion) {
    $candidateId = $deletion['id'];
    $scope = [
        'id' => $candidateId,
        'office' => strtoupper(mb_substr(trim($deletion['office']), 0, 100)),
        'accountType' => strtoupper(trim((string)($deletion['accountType'] ?? ''))),
    ];
    if (isset($uniqueCandidateDeletions[$candidateId]) && $uniqueCandidateDeletions[$candidateId] !== $scope) {
        json_out(['status' => 'error', 'message' => 'Conflicting candidate deletion scopes.'], 400);
    }
    $uniqueCandidateDeletions[$candidateId] = $scope;
}
$candidateDeletions = array_values($uniqueCandidateDeletions);

$officeTabs = null;
if (array_key_exists('officeTabs', $data)) {
    if (!is_array($data['officeTabs'])) {
        json_out(['status' => 'error', 'message' => 'Invalid office tab list.'], 400);
    }
    $officeTabs = ['RO' => [], 'FO' => []];
    foreach (['RO', 'FO'] as $accountType) {
        $offices = $data['officeTabs'][$accountType] ?? null;
        if (!is_array($offices) || count($offices) > 200) {
            json_out(['status' => 'error', 'message' => 'Invalid office tab list.'], 400);
        }
        foreach ($offices as $office) {
            if (!is_string($office) || trim($office) === '') {
                json_out(['status' => 'error', 'message' => 'An office tab name is invalid.'], 400);
            }
            $office = strtoupper(mb_substr(trim($office), 0, 100));
            if (!in_array($office, $officeTabs[$accountType], true)) {
                $officeTabs[$accountType][] = $office;
            }
        }
    }
}

$resultsView = null;
if (array_key_exists('resultsView', $data)) {
    $view = $data['resultsView'];
    if (!is_array($view)
        || !in_array(strtoupper(trim((string)($view['accountType'] ?? ''))), ['RO', 'FO'], true)
        || !is_string($view['office'] ?? null)
        || trim($view['office']) === ''
    ) {
        json_out(['status' => 'error', 'message' => 'Invalid results view state.'], 400);
    }
    $resultsView = [
        'accountType' => strtoupper(trim((string)$view['accountType'])),
        'office' => strtoupper(mb_substr(trim($view['office']), 0, 100)),
    ];
}

if (!empty($data['officeTabsToDelete'])) {
    json_out(['status' => 'error', 'message' => 'Office-wide deletion is disabled. Delete applicants by account type from the results page.'], 409);
}

foreach ($data['candidates'] as $candidate) {
    if (!is_array($candidate)) {
        json_out(['status' => 'error', 'message' => 'An applicant row is invalid. Refresh the page and try again.'], 422);
    }
    $candidateId = (int)($candidate['id'] ?? 0);
    $name = trim((string)($candidate['name'] ?? ''));
    if ($candidateId > 0 || $name !== '') {
        if ($name === '') {
            json_out(['status' => 'error', 'message' => 'Applicant names cannot be blank. Add a name before saving.'], 422);
        }
        if (trim((string)($candidate['office'] ?? '')) === '') {
            json_out(['status' => 'error', 'message' => 'Each applicant must have an office.'], 422);
        }
        if (!empty($candidate['officeMissing'])) {
            json_out(['status' => 'error', 'message' => 'Each applicant must be assigned to an office before saving. Rename its office tab to the correct office and try again.'], 422);
        }
        if (!applicant_date_is_valid((string)($candidate['interviewDate'] ?? ''))) {
            json_out(['status' => 'error', 'message' => 'Each applicant must have a valid interview date.'], 422);
        }
        $accountType = strtoupper(trim((string)($candidate['accountType'] ?? '')));
        $office = (string)$candidate['office'];
        if (!in_array($accountType, ['RO', 'FO'], true)) {
            json_out(['status' => 'error', 'message' => 'Each applicant must be assigned to Region or Field before saving.'], 422);
        }
        if (!applicant_office_account_is_valid($office, $accountType)) {
            json_out(['status' => 'error', 'message' => 'Regional Office applicants must be assigned to Region, not Field.'], 422);
        }
    }
    $scoreErrors = applicant_score_validation_errors(is_array($candidate['scores'] ?? null) ? $candidate['scores'] : []);
    if ($scoreErrors) {
        json_out(['status' => 'error', 'message' => implode("\n", array_slice($scoreErrors, 0, 5))], 422);
    }
}

$duplicateMatches = [];
if (($data['confirmDuplicates'] ?? false) !== true) {
    try {
        foreach ($data['candidates'] as $candidate) {
            if ((int)($candidate['id'] ?? 0) > 0 || trim((string)($candidate['name'] ?? '')) === '') continue;
            $matches = applicant_find_name_matches($conn, (string)$candidate['name'], 3);
            if ($matches) {
                $duplicateMatches[] = [
                    'name' => (string)$candidate['name'],
                    'matches' => $matches,
                ];
            }
        }
    } catch (Throwable $exception) {
        $duplicateSchemaError = applicant_duplicate_schema_error($exception);
        if ($duplicateSchemaError !== null) {
            json_out(['status' => 'error', 'message' => $duplicateSchemaError], 503);
        }
        error_log('save_score duplicate check failed: ' . $exception->getMessage());
        json_out(['status' => 'error', 'message' => DEBUG ? $exception->getMessage() : 'Could not check for duplicate applicants.'], 500);
    }
    if ($duplicateMatches) {
        $lines = [];
        foreach (array_slice($duplicateMatches, 0, 5) as $duplicate) {
            foreach (array_slice($duplicate['matches'], 0, 2) as $match) {
                $source = $match['source'] === 'recycle_bin' ? 'Deleted applicant' : 'Current applicant';
                $lines[] = $duplicate['name'] . ' matches ' . $source . ' #' . $match['id']
                    . ' (' . trim($match['office'] . ' · ' . $match['position'], ' ·') . ')';
            }
        }
        json_out([
            'status' => 'warning',
            'duplicate_warning' => true,
            'message' => "Possible duplicate applicants were found:\n" . implode("\n", $lines)
                . "\n\nReview these matches before saving. Continue anyway?",
        ], 409);
    }
}

function cleanScore($v): ?float {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return max(0.0, min(10.0, (float)$v));
}

function score_audit_snapshot(array $candidate, array $scores): array {
    $normalizedScores = [];
    foreach ($scores as $rater => $values) {
        $normalizedScores[(string)$rater] = [
            'psycho' => isset($values['p']) ? (float)$values['p'] : null,
            'potential' => isset($values['t']) ? (float)$values['t'] : null,
        ];
    }
    ksort($normalizedScores, SORT_STRING);
    return [
        'candidate' => [
            'name' => (string)($candidate['name'] ?? ''),
            'office' => (string)($candidate['office'] ?? ''),
            'position' => (string)($candidate['position'] ?? ''),
            'interview_date' => (string)($candidate['interview_date'] ?? ''),
            'account_type' => (string)($candidate['account_type'] ?? ''),
            'salary_grade' => isset($candidate['salary_grade']) ? (int)$candidate['salary_grade'] : null,
        ],
        'scores' => $normalizedScores,
    ];
}

try {
    $conn->begin_transaction();

    $storedTypeExpression = $hasOfficeTypeColumn ? 'c.office_type' : 'NULL';
    $stmtReadDeleteScope = $conn->prepare("SELECT c.office_name, {$storedTypeExpression} AS office_type, u.account_type
        FROM candidates c
        LEFT JOIN candidate_scores cs ON cs.candidate_id = c.id
        LEFT JOIN users u ON u.id = cs.rated_by
        WHERE c.id = ?");
    foreach ($candidateDeletions as $deletion) {
        $candidateId = $deletion['id'];
        $stmtReadDeleteScope->bind_param('i', $candidateId);
        $stmtReadDeleteScope->execute();
        $scopeResult = $stmtReadDeleteScope->get_result();
        $storedType = '';
        $scoreTypes = [];
        $hasUnknownScoreType = false;
        $candidateOffice = null;
        while ($scopeRow = $scopeResult->fetch_assoc()) {
            $storedOffice = trim((string)($scopeRow['office_name'] ?? ''));
            $candidateOffice = $storedOffice !== '' ? strtoupper($storedOffice) : 'REGIONAL OFFICE';
            $rowType = strtoupper(trim((string)($scopeRow['office_type'] ?? '')));
            if (in_array($rowType, ['RO', 'FO'], true)) $storedType = $rowType;
            $sourceType = strtolower(trim((string)($scopeRow['account_type'] ?? '')));
            if ($sourceType === 'region') {
                $scoreTypes['RO'] = true;
            } elseif ($sourceType === 'field') {
                $scoreTypes['FO'] = true;
            } else {
                $hasUnknownScoreType = true;
            }
        }
        $resolvedType = $storedType !== ''
            ? $storedType
            : (count($scoreTypes) === 1 && !$hasUnknownScoreType ? (string)array_key_first($scoreTypes) : '');
        if ($candidateOffice !== $deletion['office'] || $resolvedType !== $deletion['accountType']) {
            $stmtReadDeleteScope->close();
            $conn->rollback();
            json_out([
                'status' => 'error',
                'message' => 'A selected applicant no longer matches the office and account type being deleted. No changes were saved. Refresh the page and try again.',
            ], 409);
        }
    }
    $stmtReadDeleteScope->close();

    if ($hasOfficeTypeColumn) {
        $stmtUpdate = $conn->prepare("UPDATE candidates SET
            candidate_name = ?, office_name = ?, office_location = ?, position_title = ?, interview_date = ?,
            office_type = COALESCE(NULLIF(?, ''), office_type),
            salary_grade = COALESCE(?, salary_grade)
            WHERE id = ?");
        $stmtInsert = $conn->prepare("INSERT INTO candidates
            (candidate_name, office_name, office_location, position_title, interview_date, office_type, salary_grade)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
    } else {
        $stmtUpdate = $conn->prepare("UPDATE candidates SET
            candidate_name = ?, office_name = ?, office_location = ?, position_title = ?, interview_date = ?,
            salary_grade = COALESCE(?, salary_grade)
            WHERE id = ?");
        $stmtInsert = $conn->prepare("INSERT INTO candidates
            (candidate_name, office_name, office_location, position_title, interview_date, salary_grade)
            VALUES (?, ?, ?, ?, ?, ?)");
    }

    $stmtDeleteScores = $conn->prepare("DELETE FROM candidate_scores WHERE candidate_id = ?");
    $stmtUpsertScore  = $conn->prepare("INSERT INTO candidate_scores (candidate_id, rater, psycho, potential, rated_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE psycho = VALUES(psycho), potential = VALUES(potential), rated_by = VALUES(rated_by)");
    $storedTypeSelect = $hasOfficeTypeColumn ? 'office_type' : 'NULL';
    $stmtReadCandidate = $conn->prepare("SELECT candidate_name, office_name, position_title, interview_date,
        {$storedTypeSelect} AS office_type, salary_grade FROM candidates WHERE id = ?");
    $stmtReadScoreOwners = $conn->prepare('SELECT rater, rated_by, psycho, potential FROM candidate_scores WHERE candidate_id = ?');

    $savedIds        = [];
    $orderByPosition = [];

    foreach ($data['candidates'] as $c) {
        $id        = (int)($c['id'] ?? 0);
        $tempIndex = (int)($c['tempIndex'] ?? 0);
        $name      = mb_substr(trim($c['name'] ?? ''), 0, 255);
        $office    = mb_substr(trim($c['office'] ?? 'General Branch'), 0, 255);

        $officeLoc = mb_substr($office, 0, 100);

        $position  = mb_substr(trim($c['position'] ?? ''), 0, 255);
        if ($position === '') $position = 'UNASSIGNED';
        $position  = canonical_position_title($position);

        $interviewDate = mb_substr(trim($c['interviewDate'] ?? ''), 0, 255);
        if ($interviewDate === '') $interviewDate = date('F d, Y');
        $accountType = strtoupper(trim((string)($c['accountType'] ?? '')));
        if (!in_array($accountType, ['RO', 'FO'], true)) $accountType = '';
        $salaryGrade = filter_var($c['salaryGrade'] ?? null, FILTER_VALIDATE_INT);
        if ($salaryGrade === false || $salaryGrade < 1 || $salaryGrade > 255) $salaryGrade = null;

        $scores = is_array($c['scores'] ?? null) ? $c['scores'] : [];

        $cleanScores = [];
        $scoreOwners = [];
        $oldScores = [];
        $oldCandidate = null;
        if ($id > 0) {
            $stmtReadCandidate->bind_param('i', $id);
            $stmtReadCandidate->execute();
            $oldCandidate = $stmtReadCandidate->get_result()->fetch_assoc() ?: null;

            $stmtReadScoreOwners->bind_param('i', $id);
            $stmtReadScoreOwners->execute();
            $ownerResult = $stmtReadScoreOwners->get_result();
            while ($owner = $ownerResult->fetch_assoc()) {
                $scoreOwners[(string)$owner['rater']] = $owner['rated_by'] === null ? null : (int)$owner['rated_by'];
                $oldScores[(string)$owner['rater']] = [
                    'p' => $owner['psycho'] === null ? null : (float)$owner['psycho'],
                    't' => $owner['potential'] === null ? null : (float)$owner['potential'],
                ];
            }
        }
        $orderKey = $office . "\0" . $position;
        foreach ($scores as $rater => $pt) {
            $rater = trim((string)$rater);
            if ($rater === '') continue;
            $p = cleanScore($pt['p'] ?? null);
            $t = cleanScore($pt['t'] ?? null);
            if ($p === null && $t === null) continue;
            $cleanScores[$rater] = ['p' => $p, 't' => $t];
            if (!isset($orderByPosition[$orderKey])) $orderByPosition[$orderKey] = [];
            if (!in_array($rater, $orderByPosition[$orderKey], true)) $orderByPosition[$orderKey][] = $rater;
        }

        if ($id > 0) {
            if ($hasOfficeTypeColumn) {
                $stmtUpdate->bind_param("ssssssii", $name, $office, $officeLoc, $position, $interviewDate, $accountType, $salaryGrade, $id);
            } else {
                $stmtUpdate->bind_param("sssssii", $name, $office, $officeLoc, $position, $interviewDate, $salaryGrade, $id);
            }
            $stmtUpdate->execute();
            $candidateId = $id;
            $savedIds[] = ['tempIndex' => $tempIndex, 'id' => $id];
            $effectiveAccountType = $accountType !== ''
                ? $accountType
                : strtoupper((string)($oldCandidate['office_type'] ?? ''));
            $storedSalaryGrade = $oldCandidate['salary_grade'] ?? null;
            $effectiveSalaryGrade = $salaryGrade ?? ($storedSalaryGrade === null ? null : (int)$storedSalaryGrade);
        } elseif ($name !== '') {
            if ($hasOfficeTypeColumn) {
                $stmtInsert->bind_param("ssssssi", $name, $office, $officeLoc, $position, $interviewDate, $accountType, $salaryGrade);
            } else {
                $stmtInsert->bind_param("sssssi", $name, $office, $officeLoc, $position, $interviewDate, $salaryGrade);
            }
            $stmtInsert->execute();
            $candidateId = $conn->insert_id;
            $savedIds[] = ['tempIndex' => $tempIndex, 'id' => $candidateId];
            $effectiveAccountType = $accountType;
            $effectiveSalaryGrade = $salaryGrade;
        } else {
            continue;
        }

        $stmtDeleteScores->bind_param("i", $candidateId);
        $stmtDeleteScores->execute();

        foreach ($cleanScores as $rater => $pt) {
            $ratedBy = $scoreOwners[$rater] ?? null;
            $stmtUpsertScore->bind_param("isddi", $candidateId, $rater, $pt['p'], $pt['t'], $ratedBy);
            $stmtUpsertScore->execute();
        }

        $newSnapshot = score_audit_snapshot([
            'name' => $name,
            'office' => $office,
            'position' => $position,
            'interview_date' => $interviewDate,
            'account_type' => $effectiveAccountType,
            'salary_grade' => $effectiveSalaryGrade,
        ], $cleanScores);
        if ($id > 0) {
            if ($oldCandidate !== null) {
                $oldSnapshot = score_audit_snapshot([
                    'name' => $oldCandidate['candidate_name'],
                    'office' => $oldCandidate['office_name'],
                    'position' => $oldCandidate['position_title'],
                    'interview_date' => $oldCandidate['interview_date'],
                    'account_type' => strtoupper((string)($oldCandidate['office_type'] ?? '')),
                    'salary_grade' => $oldCandidate['salary_grade'],
                ], $oldScores);
                if ($oldSnapshot !== $newSnapshot) {
                    record_score_audit($conn, $candidateId, 'edit', $oldSnapshot, $newSnapshot);
                }
            }
        } else {
            record_score_audit($conn, $candidateId, 'submit', null, $newSnapshot);
        }
    }
    $stmtReadCandidate->close();
    $stmtReadScoreOwners->close();

    $deletedCandidates = 0;
    if ($candidateDeletions) {
        foreach ($candidateDeletions as $deletion) {
            if (archive_candidate_for_recovery($conn, $deletion['id'], current_user_id(), $deletion['accountType'])) {
                $deletedCandidates++;
            } else {
                throw new RuntimeException('An applicant selected for deletion could not be found.');
            }
        }
    }

    // ---------- FIX (Bug 22): count positions, not candidate rows ----------
    $deletedPositions = 0;
    if (isset($data['positionsToDelete']) && is_array($data['positionsToDelete'])) {
        $storedTypeExpression = $hasOfficeTypeColumn ? 'c.office_type' : 'NULL';
        $stmtFindScopedCandidates = $conn->prepare("SELECT c.id, {$storedTypeExpression} AS office_type, u.account_type
            FROM candidates c
            LEFT JOIN candidate_scores cs ON cs.candidate_id = c.id
            LEFT JOIN users u ON u.id = cs.rated_by
            WHERE UPPER(TRIM(c.office_name)) = UPPER(?) AND TRIM(c.position_title) = ?
                AND c.salary_grade <=> ?
            ORDER BY c.id");
        $stmtDelOrder = $conn->prepare("DELETE FROM position_rater_order
            WHERE office = ? AND position = ?
                AND NOT EXISTS (
                    SELECT 1 FROM candidates
                    WHERE UPPER(TRIM(office_name)) = UPPER(?) AND TRIM(position_title) = ?
                )");

        foreach ($data['positionsToDelete'] as $entry) {
            if (!is_array($entry)) continue;
            $off = strtoupper(mb_substr(trim((string)($entry['office'] ?? '')), 0, 100));
            $pos = canonical_position_title(mb_substr(trim((string)($entry['position'] ?? '')), 0, 255));
            $accountType = strtoupper(trim((string)($entry['accountType'] ?? '')));
            $salaryGrade = filter_var($entry['salaryGrade'] ?? null, FILTER_VALIDATE_INT);
            if ($salaryGrade === false || $salaryGrade < 1 || $salaryGrade > 255) $salaryGrade = null;
            if ($off === '' || $pos === '' || $pos === 'UNASSIGNED') continue;

            $stmtFindScopedCandidates->bind_param('ssi', $off, $pos, $salaryGrade);
            $stmtFindScopedCandidates->execute();
            $scopeResult = $stmtFindScopedCandidates->get_result();
            $candidateScopes = [];
            while ($scopeRow = $scopeResult->fetch_assoc()) {
                $candidateId = (int)$scopeRow['id'];
                if (!isset($candidateScopes[$candidateId])) {
                    $storedType = strtoupper(trim((string)($scopeRow['office_type'] ?? '')));
                    $candidateScopes[$candidateId] = [
                        'stored' => in_array($storedType, ['RO', 'FO'], true) ? $storedType : '',
                        'types' => [],
                        'unknown' => false,
                    ];
                }
                if ($candidateScopes[$candidateId]['stored'] !== '') continue;
                $sourceType = strtolower(trim((string)($scopeRow['account_type'] ?? '')));
                if ($sourceType === 'region') {
                    $candidateScopes[$candidateId]['types']['RO'] = true;
                } elseif ($sourceType === 'field') {
                    $candidateScopes[$candidateId]['types']['FO'] = true;
                } else {
                    $candidateScopes[$candidateId]['unknown'] = true;
                }
            }
            foreach ($candidateScopes as $candidateId => $scope) {
                $resolvedType = $scope['stored'] !== ''
                    ? $scope['stored']
                    : (count($scope['types']) === 1 && !$scope['unknown'] ? (string)array_key_first($scope['types']) : '');
                $shouldDelete = in_array($accountType, ['RO', 'FO'], true)
                    ? $resolvedType === $accountType
                    : $resolvedType === '';
                if (!$shouldDelete) continue;
                if (!archive_candidate_for_recovery($conn, (int)$candidateId, current_user_id(), $resolvedType)) {
                    throw new RuntimeException('An applicant selected for position deletion could not be found.');
                }
                $deletedCandidates++;
            }

            $stmtDelOrder->bind_param('ssss', $off, $pos, $off, $pos);
            $stmtDelOrder->execute();

            $deletedPositions++;

        }
        $stmtFindScopedCandidates->close();
        $stmtDelOrder->close();
    }

    // ---------- FIX (Bug 5): persist tab renames ----------
    $renamedOffices = 0;
    if (isset($data['renames']) && is_array($data['renames'])) {
        $stmtRenameCand = $conn->prepare("UPDATE candidates SET office_name = ?, office_location = ?
            WHERE UPPER(TRIM(office_name)) = UPPER(?)");

        foreach ($data['renames'] as $rn) {
            if (!is_array($rn)) continue;
            $from = strtoupper(mb_substr(trim((string)($rn['from'] ?? '')), 0, 100));
            $to   = strtoupper(mb_substr(trim((string)($rn['to']   ?? '')), 0, 255));
            if ($from === '' || $to === '' || $from === $to) continue;

            $toLoc = mb_substr($to, 0, 100);

            $stmtRenameCand->bind_param('sss', $to, $toLoc, $from);
            $stmtRenameCand->execute();
            $stmtRenameCand->free_result();
            $renamedOffices++;

            // Move rater order rows (use INSERT ... ON DUPLICATE to avoid conflicts)
            $stmtMoveOrder = $conn->prepare(
                "INSERT INTO position_rater_order (office, position, rater, sort_order)
                 SELECT ?, position, rater, sort_order
                 FROM position_rater_order
                 WHERE office = ?
                 ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)"
            );
            $stmtMoveOrder->bind_param('ss', $to, $from);
            $stmtMoveOrder->execute();
            $stmtMoveOrder->close();

            $stmtDelOrder2 = $conn->prepare("DELETE FROM position_rater_order WHERE office = ?");
            $stmtDelOrder2->bind_param('s', $from);
            $stmtDelOrder2->execute();
            $stmtDelOrder2->close();

            // Un-hide in removed_tabs (renamed tabs should not be hidden)
            $stmtUnhide = $conn->prepare("DELETE FROM removed_tabs WHERE office IN (?, ?)");
            $stmtUnhide->bind_param('ss', $from, $to);
            $stmtUnhide->execute();
            $stmtUnhide->close();

        }
        $stmtRenameCand->close();
    }

    // ---------- Rater column merge ----------
    $savedColumns = 0;
    if (isset($data['columns']) && is_array($data['columns'])) {
        $mergedCols = [];
        foreach ($data['columns'] as $col) {
            if (!is_array($col)) continue;
            $colOffice   = strtoupper(mb_substr(trim((string)($col['office'] ?? '')), 0, 100));
            $colPosition = mb_substr(trim((string)($col['position'] ?? '')), 0, 255);
            if ($colPosition === '') $colPosition = 'UNASSIGNED';
            $colPosition = canonical_position_title($colPosition);
            if ($colOffice === '' || !is_array($col['raters'] ?? null)) continue;

            $k = $colOffice . "\0" . $colPosition;
            if (!isset($mergedCols[$k])) $mergedCols[$k] = [];
            foreach (array_slice($col['raters'], 0, 50) as $r) {
                $r = mb_substr(trim((string)$r), 0, 30);
                if ($r !== '' && !in_array($r, $mergedCols[$k], true)) $mergedCols[$k][] = $r;
            }
        }

        foreach ($mergedCols as $k => $raters) {
            [$colOffice, $colPosition] = explode("\0", $k, 2);

            if (count($raters) > 0) {
                $marks = implode(',', array_fill(0, count($raters), '?'));
                $stmtDel = $conn->prepare("DELETE FROM position_rater_order WHERE office = ? AND position = ? AND rater NOT IN ($marks)");
                $stmtDel->bind_param(str_repeat('s', 2 + count($raters)), $colOffice, $colPosition, ...$raters);
            } else {
                $stmtDel = $conn->prepare("DELETE FROM position_rater_order WHERE office = ? AND position = ?");
                $stmtDel->bind_param('ss', $colOffice, $colPosition);
            }
            $stmtDel->execute();
            $stmtDel->close();

            record_rater_order($conn, $colOffice, $colPosition, $raters);
            $savedColumns += count($raters);
        }
    }

    // ---------- Tabs (un-hide) ----------
    $tabList = [];
    if (isset($data['tabs']) && is_array($data['tabs'])) {
        foreach (array_slice($data['tabs'], 0, 200) as $t) {
            $t = strtoupper(mb_substr(trim((string)$t), 0, 100));
            if ($t !== '' && !in_array($t, $tabList, true)) $tabList[] = $t;
        }
        if (count($tabList) > 0) {
            $marks = implode(',', array_fill(0, count($tabList), '?'));
            $stmtTabs = $conn->prepare("DELETE FROM position_rater_order WHERE office NOT IN ($marks)");
            $stmtTabs->bind_param(str_repeat('s', count($tabList)), ...$tabList);
            $stmtTabs->execute();
            $stmtTabs->close();

            try {
                $stmtShow = $conn->prepare("DELETE FROM removed_tabs WHERE office IN ($marks)");
                $stmtShow->bind_param(str_repeat('s', count($tabList)), ...$tabList);
                $stmtShow->execute();
                $stmtShow->close();
            } catch (Exception $e) {
                error_log('save_score removed_tabs un-hide error: ' . $e->getMessage());
            }
        }
    }

    // ---------- Hidden tabs ----------
    if (isset($data['hiddenTabs']) && is_array($data['hiddenTabs'])) {
        try {
            $stmtHide = $conn->prepare("INSERT INTO removed_tabs (office) VALUES (?)
                ON DUPLICATE KEY UPDATE removed_at = CURRENT_TIMESTAMP");
            foreach (array_slice($data['hiddenTabs'], 0, 200) as $t) {
                $t = strtoupper(mb_substr(trim((string)$t), 0, 100));
                if ($t === '' || in_array($t, $tabList, true)) continue;
                $stmtHide->bind_param('s', $t);
                $stmtHide->execute();
            }
            $stmtHide->close();
        } catch (Exception $e) {
            error_log('save_score removed_tabs hide error: ' . $e->getMessage());
        }
    }

    $savedOfficeTabs = 0;
    if ($officeTabs !== null) {
        $stmtDeleteOfficeTabs = $conn->prepare('DELETE FROM office_tabs WHERE account_type = ?');
        $stmtInsertOfficeTab = $conn->prepare('INSERT INTO office_tabs (account_type, office) VALUES (?, ?)');
        foreach ($officeTabs as $accountType => $offices) {
            $stmtDeleteOfficeTabs->bind_param('s', $accountType);
            $stmtDeleteOfficeTabs->execute();
            foreach ($offices as $office) {
                $stmtInsertOfficeTab->bind_param('ss', $accountType, $office);
                $stmtInsertOfficeTab->execute();
                $savedOfficeTabs++;
            }
        }
        $stmtDeleteOfficeTabs->close();
        $stmtInsertOfficeTab->close();
    }

    foreach ($orderByPosition as $key => $ratersInOrder) {
        [$office, $position] = explode("\0", $key, 2);
        record_rater_order($conn, $office, $position, $ratersInOrder);
    }

    // ---------- Header labels ----------
    if (isset($data['headers']) && is_array($data['headers'])) {
        $h = $data['headers'];
        $get = function($key, $default = '') use ($h) {
            return mb_substr(trim($h[$key] ?? $default), 0, 100);
        };

        $hash            = $get('hash', '#');
        $name            = $get('name', 'Name');
        $psychoGroup     = $get('psychoGroup', 'PSYCHO-SOCIAL');
        $potentialGroup  = $get('potentialGroup', 'POTENTIAL');
        $core            = $get('core', 'CORE');
        $org             = $get('org', 'ORG');
        $pe1             = $get('psychoExtra1');
        $pe2             = $get('psychoExtra2');
        $pe3             = $get('psychoExtra3');
        $pe4             = $get('psychoExtra4');
        $pAve            = $get('psychoAve', 'AVE.');
        $func            = $get('func', 'FUNC');
        $lead            = $get('lead', 'LEAD');
        $te1             = $get('potentialExtra1');
        $te2             = $get('potentialExtra2');
        $te3             = $get('potentialExtra3');
        $te4             = $get('potentialExtra4');
        $tAve            = $get('potentialAve', 'AVE.');
        $remarksHdr      = $get('remarks', 'REMARKS');

        $stmtHdr = $conn->prepare("INSERT INTO sheet_settings
            (id, hdr_hash, hdr_name, hdr_psycho_group, hdr_potential_group,
             hdr_core, hdr_org, hdr_psycho_extra1, hdr_psycho_extra2, hdr_psycho_extra3, hdr_psycho_extra4, hdr_psycho_ave,
             hdr_func, hdr_lead, hdr_potential_extra1, hdr_potential_extra2, hdr_potential_extra3, hdr_potential_extra4, hdr_potential_ave,
             hdr_remarks)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                hdr_hash=VALUES(hdr_hash), hdr_name=VALUES(hdr_name),
                hdr_psycho_group=VALUES(hdr_psycho_group), hdr_potential_group=VALUES(hdr_potential_group),
                hdr_core=VALUES(hdr_core), hdr_org=VALUES(hdr_org),
                hdr_psycho_extra1=VALUES(hdr_psycho_extra1), hdr_psycho_extra2=VALUES(hdr_psycho_extra2),
                hdr_psycho_extra3=VALUES(hdr_psycho_extra3), hdr_psycho_extra4=VALUES(hdr_psycho_extra4),
                hdr_psycho_ave=VALUES(hdr_psycho_ave),
                hdr_func=VALUES(hdr_func), hdr_lead=VALUES(hdr_lead),
                hdr_potential_extra1=VALUES(hdr_potential_extra1), hdr_potential_extra2=VALUES(hdr_potential_extra2),
                hdr_potential_extra3=VALUES(hdr_potential_extra3), hdr_potential_extra4=VALUES(hdr_potential_extra4),
                hdr_potential_ave=VALUES(hdr_potential_ave),
                hdr_remarks=VALUES(hdr_remarks)");

        $stmtHdr->bind_param(
            str_repeat('s', 19),
            $hash, $name, $psychoGroup, $potentialGroup,
            $core, $org, $pe1, $pe2, $pe3, $pe4, $pAve,
            $func, $lead, $te1, $te2, $te3, $te4, $tAve,
            $remarksHdr
        );
        $stmtHdr->execute();
    }

    $conn->commit();
    if ($resultsView !== null) {
        $_SESSION['results_account_type'] = $resultsView['accountType'];
        $_SESSION['results_office'] = $resultsView['office'];
    }

    json_out([
        'status'           => 'success',
        'savedIds'         => $savedIds,
        'savedColumns'     => $savedColumns,
        'savedOfficeTabs'  => $savedOfficeTabs,
        'deletedCandidates' => $deletedCandidates,
        'deletedPositions' => $deletedPositions,
        'renamedOffices'   => $renamedOffices
    ]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('save_score error: ' . $e->getMessage());
    $recoverySchemaError = deleted_candidate_schema_error($e);
    $auditSchemaError = score_audit_schema_error($e);
    json_out([
        'status' => 'error',
        'message' => $recoverySchemaError ?? $auditSchemaError ?? (DEBUG ? $e->getMessage() : 'Could not save changes.')
    ], 500);
}
exit;
?>
