<?php

function applicant_normalize_name(string $name): string {
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    return mb_strtolower($name, 'UTF-8');
}

function applicant_find_name_matches(mysqli $conn, string $name, int $limit = 8): array {
    $normalizedName = applicant_normalize_name($name);
    if ($normalizedName === '') return [];
    $limit = max(2, min($limit, 20));

    $currentMatches = [];
    $stmt = $conn->prepare("SELECT id, candidate_name, office_name, position_title, office_type
        FROM candidates WHERE LOWER(TRIM(candidate_name)) = LOWER(TRIM(?))
        ORDER BY id DESC LIMIT 100");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (applicant_normalize_name((string)($row['candidate_name'] ?? '')) !== $normalizedName) continue;
        $currentMatches[] = [
            'source' => 'current',
            'id' => (int)$row['id'],
            'name' => (string)($row['candidate_name'] ?? ''),
            'office' => (string)($row['office_name'] ?? ''),
            'position' => (string)($row['position_title'] ?? ''),
            'account_type' => strtoupper((string)($row['office_type'] ?? '')),
        ];
        if (count($currentMatches) >= $limit) break;
    }
    $stmt->close();

    $deletedMatches = [];
    $stmt = $conn->prepare("SELECT original_candidate_id, candidate_name, office_name, position_title, office_type
        FROM deleted_candidates WHERE LOWER(TRIM(candidate_name)) = LOWER(TRIM(?))
        ORDER BY deleted_at DESC, id DESC LIMIT 100");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (applicant_normalize_name((string)($row['candidate_name'] ?? '')) !== $normalizedName) continue;
        $deletedMatches[] = [
            'source' => 'recycle_bin',
            'id' => (int)$row['original_candidate_id'],
            'name' => (string)($row['candidate_name'] ?? ''),
            'office' => (string)($row['office_name'] ?? ''),
            'position' => (string)($row['position_title'] ?? ''),
            'account_type' => strtoupper((string)($row['office_type'] ?? '')),
        ];
        if (count($deletedMatches) >= $limit) break;
    }
    $stmt->close();

    if (!$deletedMatches) return array_slice($currentMatches, 0, $limit);
    $currentLimit = min(count($currentMatches), $limit - 1);
    return array_merge(
        array_slice($currentMatches, 0, $currentLimit),
        array_slice($deletedMatches, 0, $limit - $currentLimit)
    );
}

function applicant_duplicate_schema_error(Throwable $error): ?string {
    if ($error instanceof mysqli_sql_exception
        && (int)$error->getCode() === 1146
        && stripos($error->getMessage(), 'deleted_candidates') !== false
    ) {
        return 'Duplicate checking needs the recycle-bin database table. Ask the server administrator to run `php setup.php` before saving applicants.';
    }
    return null;
}

function applicant_date_is_valid(string $date): bool {
    $date = trim($date);
    if ($date === '') return false;
    foreach (['!Y-m-d', '!m/d/Y', '!n/j/Y', '!F d, Y', '!F j, Y', '!F d Y', '!F j Y', '!M d, Y', '!M j, Y', '!M d Y', '!M j Y'] as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $date);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return true;
        }
    }
    return false;
}

function applicant_office_account_is_valid(string $office, string $accountType): bool {
    $accountType = strtoupper(trim($accountType));
    if (!in_array($accountType, ['RO', 'FO'], true)) return false;
    return strtoupper(trim($office)) !== 'REGIONAL OFFICE' || $accountType === 'RO';
}

function applicant_score_value_is_valid(mixed $value): bool {
    if ($value === null || (is_string($value) && trim($value) === '')) return true;
    return is_numeric($value) && is_finite((float)$value) && (float)$value >= 0 && (float)$value <= 10;
}

function applicant_score_validation_errors(array $scores): array {
    $errors = [];
    foreach ($scores as $rater => $values) {
        if (!is_array($values)) {
            $errors[] = 'Scores for ' . (string)$rater . ' are invalid.';
            continue;
        }
        foreach (['p' => 'psychosocial', 't' => 'potential'] as $key => $label) {
            if (!applicant_score_value_is_valid($values[$key] ?? null)) {
                $errors[] = $label . ' score for ' . (string)$rater . ' must be a number from 0 to 10.';
            }
        }
    }
    return $errors;
}
