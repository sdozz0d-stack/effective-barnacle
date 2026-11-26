<?php
const BASIC_MAX_SG = 9;

function template_level_for_salary_grade(int $sg): string {
    return $sg <= BASIC_MAX_SG ? 'Basic' : 'Advanced';
}

if (!function_exists('rater_list')) {
    function rater_list(): array {
        return ['ADMIN', 'END-USER', 'CHIEF PERSONNEL SECTION', 'CHAIR', 'VICE-CHAIR', 'UNION', 'GAD', 'ALTERNATE'];
    }
}

if (!function_exists('canonical_rater_name')) {
    function canonical_rater_name(string $r): string {
        $trimmed = trim($r);
        if ($trimmed === '') return '';
        $compact = preg_replace('/[\s\-_]+/', '', strtoupper($trimmed));
        foreach (rater_list() as $known) {
            if (preg_replace('/[\s\-_]+/', '', strtoupper($known)) === $compact) return $known;
        }
        return strtoupper($trimmed);
    }
}

function ensure_column(mysqli $conn, string $table, string $column, string $definitionSql): void {
    if (!app_allow_ddl()) return;
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return;
        $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = $stmt->get_result();
        if ($exists && $exists->num_rows === 0) { $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definitionSql"); }
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1060) return;
        error_log("ensure_column {$table}.{$column}: " . $e->getMessage());
    } catch (Throwable $e) { error_log("ensure_column {$table}.{$column}: " . $e->getMessage()); }
}

function raters_schema_has_column(mysqli $conn, string $table, string $column): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return false;
    try {
        $stmt = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    } catch (Throwable $e) {
        error_log("raters_schema_has_column {$table}.{$column}: " . $e->getMessage());
        return false;
    }
}

function ensure_schema(mysqli $conn): void {
    if (!app_allow_ddl()) return;
    $conn->query("CREATE TABLE IF NOT EXISTS `candidates` (
      `id` int(11) NOT NULL AUTO_INCREMENT, `candidate_name` varchar(255) DEFAULT NULL, `position_title` varchar(255) DEFAULT NULL,
      `office_location` varchar(100) DEFAULT NULL, `office_name` varchar(255) DEFAULT '', `interview_date` varchar(255) DEFAULT NULL,
      `salary_grade` tinyint(3) unsigned DEFAULT NULL, `office_type` varchar(2) DEFAULT NULL,
      `user_id` int(11) DEFAULT NULL,
      `rater_name` varchar(255) DEFAULT NULL, `evidence_json` text DEFAULT NULL, `custom_competencies_json` longtext DEFAULT NULL,
      `cc1` decimal(4,2) DEFAULT NULL, `cc2` decimal(4,2) DEFAULT NULL, `cc3` decimal(4,2) DEFAULT NULL, `cc4` decimal(4,2) DEFAULT NULL, `cc5` decimal(4,2) DEFAULT NULL,
      `oc1` decimal(4,2) DEFAULT NULL, `oc2` decimal(4,2) DEFAULT NULL, `oc3` decimal(4,2) DEFAULT NULL, `oc4` decimal(4,2) DEFAULT NULL, `oc5` decimal(4,2) DEFAULT NULL,
      `lc1` decimal(4,2) DEFAULT NULL, `lc2` decimal(4,2) DEFAULT NULL, `lc3` decimal(4,2) DEFAULT NULL, `lc4` decimal(4,2) DEFAULT NULL, `lc5` decimal(4,2) DEFAULT NULL,
      `pcp1` decimal(4,2) DEFAULT NULL, `pcp2` decimal(4,2) DEFAULT NULL, `pcp3` decimal(4,2) DEFAULT NULL, `pco4` decimal(4,2) DEFAULT NULL,
      `core_avg` decimal(4,2) DEFAULT NULL, `org_avg` decimal(4,2) DEFAULT NULL, `lead_avg` decimal(4,2) DEFAULT NULL, `func_avg` decimal(4,2) DEFAULT NULL,
      `overall_avg` decimal(4,2) DEFAULT NULL, `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `psycho_extra1` decimal(4,2) DEFAULT NULL, `psycho_extra2` decimal(4,2) DEFAULT NULL, `psycho_extra3` decimal(4,2) DEFAULT NULL, `psycho_extra4` decimal(4,2) DEFAULT NULL,
      `potential_extra1` decimal(4,2) DEFAULT NULL, `potential_extra2` decimal(4,2) DEFAULT NULL, `potential_extra3` decimal(4,2) DEFAULT NULL, `potential_extra4` decimal(4,2) DEFAULT NULL,
      `psycho_ave` decimal(4,2) DEFAULT NULL, `potential_ave` decimal(4,2) DEFAULT NULL, `remarks` text DEFAULT NULL, PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS candidate_scores (candidate_id INT NOT NULL, rater VARCHAR(30) NOT NULL, psycho DECIMAL(5,2) NULL, potential DECIMAL(5,2) NULL, rated_by INT NULL, rated_at DATETIME NULL, PRIMARY KEY (candidate_id, rater)) DEFAULT CHARSET=utf8mb4");
    ensure_column($conn, 'candidates', 'remarks', 'TEXT NULL');
    ensure_column($conn, 'candidates', 'custom_competencies_json', 'LONGTEXT NULL');
    ensure_column($conn, 'candidates', 'psycho_ave', 'DECIMAL(4,2) NULL');
    ensure_column($conn, 'candidates', 'potential_ave', 'DECIMAL(4,2) NULL');
    ensure_column($conn, 'candidates', 'salary_grade', 'TINYINT UNSIGNED NULL');
    ensure_column($conn, 'candidates', 'office_type', 'VARCHAR(2) NULL');
    ensure_column($conn, 'candidates', 'user_id', 'INT NULL');
    ensure_column($conn, 'candidate_scores', 'rated_by', 'INT NULL');
    ensure_column($conn, 'candidate_scores', 'rated_at', 'DATETIME NULL');
    ensure_column($conn, 'candidate_scores', 'psycho', 'DECIMAL(5,2) NULL');
    ensure_column($conn, 'candidate_scores', 'potential', 'DECIMAL(5,2) NULL');
    $conn->query("CREATE TABLE IF NOT EXISTS sheet_settings (id TINYINT PRIMARY KEY, hdr_hash VARCHAR(50) DEFAULT '#', hdr_name VARCHAR(100) DEFAULT 'Name', hdr_psycho_group VARCHAR(100) DEFAULT 'PSYCHO-SOCIAL', hdr_potential_group VARCHAR(100) DEFAULT 'POTENTIAL', hdr_core VARCHAR(50) DEFAULT 'CORE', hdr_org VARCHAR(50) DEFAULT 'ORG', hdr_psycho_extra1 VARCHAR(50) DEFAULT '', hdr_psycho_extra2 VARCHAR(50) DEFAULT '', hdr_psycho_extra3 VARCHAR(50) DEFAULT '', hdr_psycho_extra4 VARCHAR(50) DEFAULT '', hdr_psycho_ave VARCHAR(50) DEFAULT 'AVE.', hdr_func VARCHAR(50) DEFAULT 'FUNC', hdr_lead VARCHAR(50) DEFAULT 'LEAD', hdr_potential_extra1 VARCHAR(50) DEFAULT '', hdr_potential_extra2 VARCHAR(50) DEFAULT '', hdr_potential_extra3 VARCHAR(50) DEFAULT '', hdr_potential_extra4 VARCHAR(50) DEFAULT '', hdr_potential_ave VARCHAR(50) DEFAULT 'AVE.', hdr_remarks VARCHAR(100) DEFAULT 'REMARKS') DEFAULT CHARSET=utf8mb4");
    $conn->query("INSERT IGNORE INTO sheet_settings (id) VALUES (1)");
    ensure_column($conn, 'sheet_settings', 'hdr_remarks', "VARCHAR(100) DEFAULT 'REMARKS'");
    $conn->query("CREATE TABLE IF NOT EXISTS position_rater_order (office VARCHAR(100) NOT NULL, position VARCHAR(255) NOT NULL, rater VARCHAR(30) NOT NULL, sort_order INT NOT NULL, PRIMARY KEY (office, position, rater)) DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS removed_tabs (office VARCHAR(100) NOT NULL PRIMARY KEY, removed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS office_tabs (
        account_type ENUM('RO', 'FO') NOT NULL,
        office VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (account_type, office)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS deleted_candidates (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        original_candidate_id INT NOT NULL,
        candidate_name VARCHAR(255) DEFAULT NULL,
        office_name VARCHAR(255) DEFAULT NULL,
        office_type VARCHAR(2) DEFAULT NULL,
        position_title VARCHAR(255) DEFAULT NULL,
        candidate_data LONGTEXT NOT NULL,
        scores_data LONGTEXT NOT NULL,
        deleted_by INT DEFAULT NULL,
        deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_deleted_at (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS score_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        candidate_id INT NULL,
        user_id INT NULL,
        username VARCHAR(100) NULL,
        action VARCHAR(32) NOT NULL,
        old_value LONGTEXT NULL,
        new_value LONGTEXT NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(512) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_score_audit_candidate (candidate_id),
        INDEX idx_score_audit_user (user_id),
        INDEX idx_score_audit_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS database_backups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        size_bytes BIGINT UNSIGNED NOT NULL,
        sha256 CHAR(64) NOT NULL,
        completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_database_backups_completed (completed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function record_score_audit(mysqli $conn, ?int $candidateId, string $action, mixed $oldValue = null, mixed $newValue = null): void {
    $oldJson = $oldValue === null ? null : json_encode($oldValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $newJson = $newValue === null ? null : json_encode($newValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $userId = function_exists('current_user_id') ? current_user_id() : null;
    $username = isset($_SESSION['username']) ? mb_substr((string)$_SESSION['username'], 0, 100) : null;
    $ip = function_exists('request_ip') ? request_ip() : ($_SERVER['REMOTE_ADDR'] ?? null);
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
    $stmt = $conn->prepare('INSERT INTO score_audit
        (candidate_id, user_id, username, action, old_value, new_value, ip, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iissssss', $candidateId, $userId, $username, $action, $oldJson, $newJson, $ip, $userAgent);
    $stmt->execute();
    $stmt->close();
}

function score_audit_schema_error(Throwable $error): ?string {
    if ($error instanceof mysqli_sql_exception
        && (int)$error->getCode() === 1146
        && stripos($error->getMessage(), 'score_audit') !== false
    ) {
        return 'Audit history is not set up in the database. Run `php setup.php` from the application server command line before saving changes.';
    }
    return null;
}

function archive_candidate_for_recovery(mysqli $conn, int $candidateId, ?int $deletedBy, ?string $accountType = null): bool {
    $stmtCandidate = $conn->prepare('SELECT * FROM candidates WHERE id = ? FOR UPDATE');
    $stmtCandidate->bind_param('i', $candidateId);
    $stmtCandidate->execute();
    $candidate = $stmtCandidate->get_result()->fetch_assoc();
    $stmtCandidate->close();
    if (!$candidate) return false;

    $stmtScores = $conn->prepare('SELECT * FROM candidate_scores WHERE candidate_id = ? ORDER BY rater');
    $stmtScores->bind_param('i', $candidateId);
    $stmtScores->execute();
    $scoresResult = $stmtScores->get_result();
    $scores = [];
    while ($score = $scoresResult->fetch_assoc()) $scores[] = $score;
    $stmtScores->close();

    $candidateJson = json_encode($candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $scoresJson = json_encode($scores, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    record_score_audit($conn, $candidateId, 'delete', [
        'candidate' => $candidate,
        'scores' => $scores,
    ]);
    $name = $candidate['candidate_name'] ?? null;
    $office = $candidate['office_name'] ?? null;
    $officeType = $candidate['office_type'] ?? null;
    if (!in_array(strtoupper((string)$officeType), ['RO', 'FO'], true)
        && in_array(strtoupper((string)$accountType), ['RO', 'FO'], true)
    ) {
        $officeType = strtoupper((string)$accountType);
    }
    $position = $candidate['position_title'] ?? null;
    $stmtArchive = $conn->prepare('INSERT INTO deleted_candidates
        (original_candidate_id, candidate_name, office_name, office_type, position_title, candidate_data, scores_data, deleted_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmtArchive->bind_param('issssssi', $candidateId, $name, $office, $officeType, $position, $candidateJson, $scoresJson, $deletedBy);
    $stmtArchive->execute();
    $stmtArchive->close();

    $stmtDeleteScores = $conn->prepare('DELETE FROM candidate_scores WHERE candidate_id = ?');
    $stmtDeleteScores->bind_param('i', $candidateId);
    $stmtDeleteScores->execute();
    $stmtDeleteScores->close();

    $stmtDeleteCandidate = $conn->prepare('DELETE FROM candidates WHERE id = ?');
    $stmtDeleteCandidate->bind_param('i', $candidateId);
    $stmtDeleteCandidate->execute();
    $deleted = $stmtDeleteCandidate->affected_rows === 1;
    $stmtDeleteCandidate->close();
    return $deleted;
}

function deleted_candidate_schema_error(Throwable $error): ?string {
    if ($error instanceof mysqli_sql_exception
        && (int)$error->getCode() === 1146
        && stripos($error->getMessage(), 'deleted_candidates') !== false
    ) {
        return 'Applicant recovery is not set up in the database. Run `php setup.php` from the application server command line before deleting applicants. No deletion was completed.';
    }
    return null;
}

function restore_candidate_from_recovery(mysqli $conn, int $recoveryId): array {
    $conn->begin_transaction();
    try {
        $stmtArchive = $conn->prepare('SELECT * FROM deleted_candidates WHERE id = ? FOR UPDATE');
        $stmtArchive->bind_param('i', $recoveryId);
        $stmtArchive->execute();
        $archive = $stmtArchive->get_result()->fetch_assoc();
        $stmtArchive->close();
        if (!$archive) throw new RuntimeException('This deleted applicant is no longer in the recycle bin.');

        $candidate = json_decode((string)$archive['candidate_data'], true, 512, JSON_THROW_ON_ERROR);
        $scores = json_decode((string)$archive['scores_data'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($candidate) || !is_array($scores) || empty($candidate['id'])) {
            throw new RuntimeException('The saved recovery data is invalid.');
        }

        $candidateId = (int)$candidate['id'];
        $stmtExists = $conn->prepare('SELECT 1 FROM candidates WHERE id = ?');
        $stmtExists->bind_param('i', $candidateId);
        $stmtExists->execute();
        $alreadyExists = $stmtExists->get_result()->num_rows > 0;
        $stmtExists->close();
        if ($alreadyExists) throw new RuntimeException('This applicant ID is already in use. The recycle-bin copy was kept unchanged.');

        $columns = array_keys($candidate);
        foreach ($columns as $column) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                throw new RuntimeException('The saved recovery data contains an invalid column.');
            }
        }
        $quotedColumns = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmtInsertCandidate = $conn->prepare("INSERT INTO candidates ({$quotedColumns}) VALUES ({$placeholders})");
        $candidateValues = array_values($candidate);
        $candidateTypes = str_repeat('s', count($candidateValues));
        $candidateParams = [$candidateTypes];
        foreach ($candidateValues as $index => &$value) $candidateParams[] = &$value;
        call_user_func_array([$stmtInsertCandidate, 'bind_param'], $candidateParams);
        unset($value);
        $stmtInsertCandidate->execute();
        $stmtInsertCandidate->close();

        $stmtInsertScore = $conn->prepare('INSERT INTO candidate_scores
            (candidate_id, rater, psycho, potential, rated_by, rated_at)
            VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($scores as $score) {
            if (!is_array($score)) throw new RuntimeException('The saved score recovery data is invalid.');
            $scoreCandidateId = $candidateId;
            $rater = (string)($score['rater'] ?? '');
            $psycho = $score['psycho'] ?? null;
            $potential = $score['potential'] ?? null;
            $ratedBy = isset($score['rated_by']) ? (int)$score['rated_by'] : null;
            $ratedAt = $score['rated_at'] ?? null;
            $stmtInsertScore->bind_param('isddis', $scoreCandidateId, $rater, $psycho, $potential, $ratedBy, $ratedAt);
            $stmtInsertScore->execute();
        }
        $stmtInsertScore->close();
        record_score_audit($conn, $candidateId, 'restore', null, [
            'candidate' => $candidate,
            'scores' => $scores,
        ]);

        $stmtRemoveArchive = $conn->prepare('DELETE FROM deleted_candidates WHERE id = ?');
        $stmtRemoveArchive->bind_param('i', $recoveryId);
        $stmtRemoveArchive->execute();
        $stmtRemoveArchive->close();
        $conn->commit();
        return [
            'candidate_name' => (string)($candidate['candidate_name'] ?? ''),
            'office_name' => (string)($candidate['office_name'] ?? ''),
            'candidate_id' => $candidateId,
        ];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }
}

function leadership_level_titles(): array {
    return [
        'Engineer II (Geodetic Engineer)' => 'B',
        'Accountant III' => 'B',
        'Engineer III (Geodetic Engineer)' => 'B',
        'Information Systems Analyst III' => 'B',
        'Attorney III' => 'B',
        'Administrative Officer V' => 'B',
        'Administrative Officer V (Budget Officer III)' => 'B',
        'Administrative Officer V (Cashier III)' => 'B',
        'Administrative Officer V (HRMO III)' => 'B',
        'Attorney II' => 'B',
        'Development Management Officer III' => 'B',
        'Forester III' => 'B',
        'Land Management Officer III' => 'B',
        'Planning Officer III' => 'B',
        'Project Evaluation Officer III' => 'B',
        'Senior Communications Development Officer' => 'B',
        'Senior Ecosystems Management Specialist' => 'B',
        'Development Management Officer IV' => 'B',
        'Engineer IV (Geodetic Engineer)' => 'B',
        'Planning Officer IV' => 'B',
        'Supervising Administrative Officer' => 'B',
        'Supervising Ecosystems Management Specialist' => 'B',
        'Attorney IV' => 'B',
        'Chief Administrative Officer' => 'I',
        'Chief Ecosystems Management Specialist' => 'I',
        'Community Environment & Natural Resources Officer' => 'I',
        'Community Environment & Natural Resources Officer (CENRO)' => 'I',
        'Development Management Officer V' => 'I',
        'Engineer V (Geodetic Engineer)' => 'I',
        'Planning Officer V' => 'I',
        'Attorney V' => 'I',
    ];
}

function leadership_level_aliases(): array {
    return [
        ['ATY','V','I'], ['ATY','IV','B'], ['ATY','III','B'], ['ATY','II','B'],
        ['ATTY','V','I'], ['ATTY','IV','B'], ['ATTY','III','B'], ['ATTY','II','B'],
        ['DMO','V','I'], ['DMO','IV','B'], ['DMO','III','B'],
        ['ENG','V','I'], ['ENG','IV','B'], ['ENG','III','B'], ['ENG','II','B'],
        ['PLO','V','I'], ['PLO','IV','B'], ['PLO','III','B'],
        ['PEO','III','B'], ['LAMO','III','B'], ['FORST','III','B'],
        ['INFOSA','III','B'], ['ISA','III','B'], ['A','III','B'],
        ['ADOF','V','B'], ['AO','V','B'], ['SADOF','','B'], ['SAO','','B'],
        ['SVEMS','','B'], ['SREMS','','B'], ['SRCDO','','B'],
        ['CECMS','','I'], ['CADOF','','I'], ['CAO','','I'], ['CENRO','','I'],
    ];
}

const LEADERSHIP_ROW_TITLES = [
    'LC1 - STRATEGIC LEADERSHIP (THINKING STRATEGICALLY AND CREATIVELY)',
    'LC2 - LEADING CHANGE',
    'LC3 - PEOPLE DEVELOPMENT (CREATING AND NURTURING A HIGH PERFORMING ORGANIZATION)',
    'LC4 - PEOPLE PERFORMANCE MANAGEMENT (MANAGING PERFORMANCE AND COACHING FOR RESULTS)',
    'LC5 - PARTNERSHIP AND NETWORKING (BUILDING COLLABORATIVE AND INCLUSIVE WORKING RELATIONSHIPS)',
];

const LEADERSHIP_BASIC_SOURCE_DESCRIPTORS = [
    "Develops office/ service's strategies and plans based on the DENR's mission/vision",
    'Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation',
    'Analyzes appropriateness of office software and equipment in the performance of assigned tasks. Develops information assets to achieve organizational goals',
    'Leads in project management activities',
    'Leads the practice of CSW in his/her office/unit',
];

const LEADERSHIP_ADVANCED_DESCRIPTORS = [
    "Ensures that others support the DENR's mission, goals, agenda, climate, and policy",
    'Ensures the effective implementation of any change initiatives/programs of the DENR by guiding the team in the transition process',
    'Motivates, trains, coaches and mentors people towards achieving quality results',
    'Monitors employee performance regularly to help employee improve his/her performance',
    'Builds and develops local partnerships and networks and identifies opportunities to deliver or enhance work outcomes',
];

function leadership_rows_for_level(string $level): array {
    $level = strtoupper(trim($level));
    $descriptors = match ($level) {
        'B' => LEADERSHIP_BASIC_SOURCE_DESCRIPTORS,
        'I' => LEADERSHIP_ADVANCED_DESCRIPTORS,
        default => throw new InvalidArgumentException('Leadership level must be B or I.'),
    };

    $rows = [];
    foreach (LEADERSHIP_ROW_TITLES as $index => $title) {
        $rows[] = [
            'id' => 'lc' . ($index + 1),
            'title' => $title,
            'desc' => $descriptors[$index],
        ];
    }
    return $rows;
}

function leadership_hidden_positions(): array { return []; }
function offices_without_leadership(): array { return []; }

function normalize_position(string $s): string { $s = str_replace('&', ' AND ', $s); $s = preg_replace('/\s+/u', ' ', $s); return mb_strtoupper(trim((string)$s)); }
function compact_position(string $s): string { return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', normalize_position($s)); }
function compact_position_no_parens(string $s): string { return compact_position((string)preg_replace('/\([^)]*\)/u', ' ', $s)); }

function leadership_level_map(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (leadership_level_titles() as $title => $lvl) { $map[compact_position($title)] = $lvl; }
    foreach (leadership_level_titles() as $title => $lvl) { $base = compact_position_no_parens($title); if (!isset($map[$base])) $map[$base] = $lvl; }
    $r2d = ['II' => '2', 'III' => '3', 'IV' => '4', 'V' => '5']; $d2r = ['2' => 'II', '3' => 'III', '4' => 'IV', '5' => 'V'];
    foreach (leadership_level_aliases() as $alias) { [$abbr, $numeral, $lvl] = $alias; $keys = [$abbr . $numeral]; if ($numeral !== '' && isset($r2d[$numeral])) $keys[] = $abbr . $r2d[$numeral]; if ($numeral !== '' && isset($d2r[$numeral])) $keys[] = $abbr . $d2r[$numeral]; foreach ($keys as $k) { if (!isset($map[$k])) $map[$k] = $lvl; } }
    return $map;
}

function leadership_level_for_position(string $position): ?string {
    $map = leadership_level_map(); $full = compact_position($position); if ($full === '') return null;
    if (isset($map[$full])) return $map[$full]; $base = compact_position_no_parens($position); return ($base !== '' && isset($map[$base])) ? $map[$base] : null;
}

function canonical_position_title(string $position): string {
    static $cache = null;
    if ($cache === null) {
        $cache = ['titles' => [], 'titlesNoParens' => [], 'aliasToTitle' => []];
        foreach (leadership_level_titles() as $title => $lvl) { $cache['titles'][compact_position($title)] = $title; $cp = compact_position_no_parens($title); if (!isset($cache['titlesNoParens'][$cp])) $cache['titlesNoParens'][$cp] = $title; }
        $r2d = ['II' => '2', 'III' => '3', 'IV' => '4', 'V' => '5'];
        $stems = ['ATTY' => 'Attorney', 'ATY' => 'Attorney', 'DMO' => 'Development Management Officer', 'ENG' => 'Engineer', 'PLO' => 'Planning Officer', 'PEO' => 'Project Evaluation Officer', 'LAMO' => 'Land Management Officer', 'FORST' => 'Forester', 'INFOSA' => 'Information Systems Analyst', 'ISA' => 'Information Systems Analyst', 'A' => 'Accountant', 'ADOF' => 'Administrative Officer', 'AO' => 'Administrative Officer', 'SADOF' => 'Supervising Administrative Officer', 'SAO' => 'Supervising Administrative Officer', 'SVEMS' => 'Supervising Ecosystems Management Specialist', 'SREMS' => 'Senior Ecosystems Management Specialist', 'SRCDO' => 'Senior Communications Development Officer', 'CECMS' => 'Chief Ecosystems Management Specialist', 'CADOF' => 'Chief Administrative Officer', 'CAO' => 'Chief Administrative Officer', 'CENRO' => 'Community Environment & Natural Resources Officer', 'PENRO' => 'Provincial Environment & Natural Resources Officer', ];
        foreach (leadership_level_aliases() as [$abbr, $numeral, $lvl]) {
            if (!isset($stems[$abbr])) continue;
            $want = compact_position($stems[$abbr] . ' ' . $numeral); $target = null;
            foreach (leadership_level_titles() as $title => $_l) { if (compact_position_no_parens($title) === $want) { $target = $title; break; } }
            if ($target === null) continue;
            $keys = [$abbr . $numeral]; if ($numeral !== '' && isset($r2d[$numeral])) $keys[] = $abbr . $r2d[$numeral];
            foreach ($keys as $k) { if (!isset($cache['aliasToTitle'][$k])) $cache['aliasToTitle'][$k] = $target; }
        }
    }
    $raw = trim($position); if ($raw === '') return '';
    $full = compact_position($raw);
    if ($full === 'ENGINEERII') return 'Engineer II (Geodetic Engineer)';
    if (isset($cache['titles'][$full])) return $cache['titles'][$full];
    $base = compact_position_no_parens($raw); if (isset($cache['titlesNoParens'][$base])) return $cache['titlesNoParens'][$base];
    if (isset($cache['aliasToTitle'][$full])) return $cache['aliasToTitle'][$full]; if (isset($cache['aliasToTitle'][$base])) return $cache['aliasToTitle'][$base];
    return $raw;
}

function office_has_leadership(string $office, string $position = ''): bool {
    if (in_array(strtoupper(trim($office)), offices_without_leadership(), true)) return false;
    $canonical = canonical_position_title($position);
    if (in_array(strtoupper(trim($position)), leadership_hidden_positions(), true)) return false;
    if (in_array(strtoupper(trim($canonical)), leadership_hidden_positions(), true)) return false;
    return leadership_level_for_position($canonical) !== null;
}

function core_cell_codes(): array { return ['cc1', 'cc2', 'cc3', 'cc4', 'cc5']; }
function right_cell_codes(bool $withLeadership = true): array { $codes = ['oc1', 'oc2', 'oc3', 'oc4', 'oc5']; if ($withLeadership) $codes = array_merge($codes, ['lc1', 'lc2', 'lc3', 'lc4', 'lc5']); return array_merge($codes, ['pcp1', 'pcp2', 'pcp3', 'pco4']); }
function cell_avg(array $cells, array $codes): ?float { $vals = []; foreach ($codes as $c) { if (isset($cells[$c]) && $cells[$c] !== null && $cells[$c] !== '') $vals[] = (float)$cells[$c]; } return count($vals) > 0 ? array_sum($vals) / count($vals) : null; }

function record_rater_order(mysqli $conn, string $office, string $position, array $ratersInOrder): void {
    $office = strtoupper(trim($office)); $position = canonical_position_title(trim($position));
    if ($office === '' || $position === '' || empty($ratersInOrder)) return;
    $clean = [];
    foreach ($ratersInOrder as $r) { $c = canonical_rater_name((string)$r); if ($c !== '' && !in_array($c, $clean, true)) $clean[] = $c; }
    if (empty($clean)) return;
    $del = $conn->prepare("DELETE FROM position_rater_order WHERE office = ? AND position = ?"); $del->bind_param('ss', $office, $position); $del->execute(); $del->close();
    $ins = $conn->prepare("INSERT INTO position_rater_order (office, position, rater, sort_order) VALUES (?, ?, ?, ?)");
    foreach ($clean as $index => $rater) { $ins->bind_param('sssi', $office, $position, $rater, $index); $ins->execute(); }
    $ins->close();
}

function position_rater_order(mysqli $conn, string $office, string $position): array {
    $office = strtoupper(trim($office)); $position = canonical_position_title(trim($position)); $out = [];
    $stmt = $conn->prepare("SELECT rater FROM position_rater_order WHERE office = ? AND position = ? ORDER BY sort_order ASC"); $stmt->bind_param('ss', $office, $position); $stmt->execute();
    $res = $stmt->get_result(); while ($row = $res->fetch_assoc()) $out[] = $row['rater']; $stmt->close();
    return $out;
}

function all_position_rater_orders(mysqli $conn): array {
    $out = []; $res = $conn->query("SELECT office, position, rater FROM position_rater_order ORDER BY office, position, sort_order ASC");
    if ($res) { while ($row = $res->fetch_assoc()) { $out[$row['office']][$row['position']][] = $row['rater']; } }
    return $out;
}

function removed_tab_list(mysqli $conn): array {
    $out = []; $res = $conn->query("SELECT office FROM removed_tabs");
    if ($res) { while ($row = $res->fetch_assoc()) $out[] = strtoupper(trim($row['office'])); }
    return $out;
}

function all_scoped_office_tabs(mysqli $conn): array {
    $out = ['RO' => [], 'FO' => []];
    $res = $conn->query("SELECT account_type, office FROM office_tabs ORDER BY office");
    while ($row = $res->fetch_assoc()) {
        $accountType = strtoupper(trim((string)$row['account_type']));
        $office = strtoupper(trim((string)$row['office']));
        if ($office !== '' && isset($out[$accountType])) $out[$accountType][] = $office;
    }
    return $out;
}