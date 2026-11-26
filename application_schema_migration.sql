-- Consolidated application schema/data migration.
-- Select the application database in Laragon before running this file.
-- Safe to run repeatedly; existing application data is preserved.
--
-- This combines the application schema/data work from check.sql, schema.sql,
-- password_reset_requests.sql, email_login_migrations.sql, the FreeDB
-- supplemental-table/column scripts, and the deleted-candidate/office-tab
-- table scripts. sum.sql is database-user provisioning with a placeholder
-- password, so configure and run it separately if needed.

SET @database_name = DATABASE();

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','rater','user') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_users_email = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'email'
);
SET @sql = IF(
    @has_users_email = 0,
    'ALTER TABLE `users` ADD COLUMN `email` VARCHAR(254) NULL DEFAULT NULL',
    'SELECT ''users.email already exists'' AS status'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_unique_email_index = (
    SELECT COUNT(*)
    FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @database_name
          AND TABLE_NAME = 'users'
          AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING COUNT(*) = 1 AND SUM(COLUMN_NAME = 'email') = 1
    ) AS email_indexes
);
SET @sql = IF(
    @has_unique_email_index = 0,
    'CREATE UNIQUE INDEX `uq_users_email` ON `users` (`email`)',
    'SELECT ''a unique users.email index already exists'' AS status'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_users_region = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'users' AND COLUMN_NAME = 'region'
);
SET @sql = IF(
    @has_users_region = 0,
    'ALTER TABLE `users` ADD COLUMN `region` VARCHAR(100) NULL DEFAULT NULL',
    'SELECT ''users.region already exists'' AS status'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_users_field_name = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'users' AND COLUMN_NAME = 'field_name'
);
SET @sql = IF(
    @has_users_field_name = 0,
    'ALTER TABLE `users` ADD COLUMN `field_name` VARCHAR(100) NULL DEFAULT NULL',
    'SELECT ''users.field_name already exists'' AS status'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_users_account_type = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'users' AND COLUMN_NAME = 'account_type'
);
SET @sql = IF(
    @has_users_account_type = 0,
    'ALTER TABLE `users` ADD COLUMN `account_type` ENUM(''region'',''field'') NULL DEFAULT NULL',
    'SELECT ''users.account_type already exists'' AS status'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_users_salary_grade = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'users' AND COLUMN_NAME = 'salary_grade'
);
SET @sql = IF(
    @has_users_salary_grade = 0,
    'ALTER TABLE `users` ADD COLUMN `salary_grade` TINYINT UNSIGNED NULL DEFAULT NULL',
    'SELECT ''users.salary_grade already exists'' AS status'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_users_rater_position = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'users' AND COLUMN_NAME = 'rater_position'
);
SET @sql = IF(
    @has_users_rater_position = 0,
    'ALTER TABLE `users` ADD COLUMN `rater_position` VARCHAR(100) NULL DEFAULT NULL',
    'SELECT ''users.rater_position already exists'' AS status'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

CREATE TABLE IF NOT EXISTS positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_scope VARCHAR(40) NOT NULL DEFAULT 'REGIONAL_OFFICE',
    source_sheet VARCHAR(100) NOT NULL DEFAULT '',
    title VARCHAR(255) NOT NULL,
    salary_grade TINYINT UNSIGNED NOT NULL,
    leadership_level CHAR(1) NULL,
    UNIQUE KEY uq_scope_sheet_title (office_scope, source_sheet, title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_positions_source_sheet = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'positions' AND COLUMN_NAME = 'source_sheet'
);
SET @sql = IF(
    @has_positions_source_sheet = 0,
    'ALTER TABLE `positions` ADD COLUMN `source_sheet` VARCHAR(100) NOT NULL DEFAULT '''' AFTER `office_scope`',
    'SELECT ''positions.source_sheet already exists'' AS status'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_old_positions_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'positions'
      AND INDEX_NAME = 'uq_scope_title'
);
SET @sql = IF(
    @has_old_positions_index > 0,
    'ALTER TABLE `positions` DROP INDEX `uq_scope_title`',
    'SELECT ''legacy positions index is absent'' AS status'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_positions_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'positions'
      AND INDEX_NAME = 'uq_scope_sheet_title'
);
SET @sql = IF(
    @has_positions_index = 0,
    'ALTER TABLE `positions` ADD UNIQUE KEY `uq_scope_sheet_title` (`office_scope`, `source_sheet`, `title`)',
    'SELECT ''positions.uq_scope_sheet_title already exists'' AS status'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

CREATE TABLE IF NOT EXISTS positions_seed_state_v2 (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    seed_version SMALLINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

INSERT INTO positions (office_scope, title, salary_grade, leadership_level) VALUES
    ('REGIONAL_OFFICE', 'Accountant I', 12, NULL),
    ('REGIONAL_OFFICE', 'Accountant II', 16, NULL),
    ('REGIONAL_OFFICE', 'Accountant III', 19, 'B'),
    ('REGIONAL_OFFICE', 'Accounting Analyst', 11, NULL),
    ('REGIONAL_OFFICE', 'Administrative Aide I', 1, NULL),
    ('REGIONAL_OFFICE', 'Administrative Aide III', 3, NULL),
    ('REGIONAL_OFFICE', 'Administrative Aide IV', 4, NULL),
    ('REGIONAL_OFFICE', 'Administrative Aide VI', 6, NULL),
    ('REGIONAL_OFFICE', 'Administrative Aide VI (Driver II)', 6, NULL),
    ('REGIONAL_OFFICE', 'Administrative Assistant I', 7, NULL),
    ('REGIONAL_OFFICE', 'Administrative Assistant I (Computer Operator I)', 7, NULL),
    ('REGIONAL_OFFICE', 'Administrative Assistant II', 8, NULL),
    ('REGIONAL_OFFICE', 'Administrative Assistant II (Bookkeeper)', 8, NULL),
    ('REGIONAL_OFFICE', 'Administrative Assistant II (Budgeting Asst.)', 8, NULL),
    ('REGIONAL_OFFICE', 'Administrative Assistant II (Senior Bookkeeper)', 8, NULL),
    ('REGIONAL_OFFICE', 'Administrative Assistant III', 9, NULL),
    ('REGIONAL_OFFICE', 'Administrative Assistant III (Computer Operator II)', 9, NULL),
    ('REGIONAL_OFFICE', 'Administrative Assistant III (Senior Bookkeeper)', 9, NULL),
    ('REGIONAL_OFFICE', 'Administrative Officer I', 10, NULL),
    ('REGIONAL_OFFICE', 'Administrative Officer I (Cashier I)', 10, NULL),
    ('REGIONAL_OFFICE', 'Administrative Officer I (Records Officer I)', 10, NULL),
    ('REGIONAL_OFFICE', 'Administrative Officer I (Supply Officer I)', 10, NULL),
    ('REGIONAL_OFFICE', 'Administrative Officer II', 11, NULL),
    ('REGIONAL_OFFICE', 'Administrative Officer II (Budget Officer I)', 11, NULL),
    ('REGIONAL_OFFICE', 'Administrative Officer III', 14, NULL),
    ('REGIONAL_OFFICE', 'Administrative Officer IV', 15, NULL),
    ('REGIONAL_OFFICE', 'Administrative Officer IV (Budget Officer II)', 15, NULL),
    ('REGIONAL_OFFICE', 'Administrative Officer IV (HRMO II)', 15, NULL),
    ('REGIONAL_OFFICE', 'Administrative Officer V', 18, 'B'),
    ('REGIONAL_OFFICE', 'Administrative Officer V (Budget Officer III)', 18, 'B'),
    ('REGIONAL_OFFICE', 'Administrative Officer V (Cashier III)', 18, 'B'),
    ('REGIONAL_OFFICE', 'Administrative Officer V (HRMO III)', 18, 'B'),
    ('REGIONAL_OFFICE', 'Attorney II', 18, 'B'),
    ('REGIONAL_OFFICE', 'Attorney III', 21, 'B'),
    ('REGIONAL_OFFICE', 'Attorney IV', 23, 'B'),
    ('REGIONAL_OFFICE', 'Attorney V', 25, 'I'),
    ('REGIONAL_OFFICE', 'Cartographer I', 6, NULL),
    ('REGIONAL_OFFICE', 'Cartographer II', 8, NULL),
    ('REGIONAL_OFFICE', 'Cartographer III', 11, NULL),
    ('REGIONAL_OFFICE', 'Cartographer IV', 15, NULL),
    ('REGIONAL_OFFICE', 'Chief Administrative Officer', 24, 'I'),
    ('REGIONAL_OFFICE', 'Chief Ecosystems Management Specialist', 24, 'I'),
    ('REGIONAL_OFFICE', 'Communication Development Officer I', 11, NULL),
    ('REGIONAL_OFFICE', 'Communication Development Officer II', 15, NULL),
    ('REGIONAL_OFFICE', 'Community Development Assistant II', 3, NULL),
    ('REGIONAL_OFFICE', 'Community Development Officer II', 4, NULL),
    ('REGIONAL_OFFICE', 'Community Environment & Natural Resources Officer', 24, 'I'),
    ('REGIONAL_OFFICE', 'Community Environment & Natural Resources Officer (CENRO)', 24, 'I'),
    ('REGIONAL_OFFICE', 'Credit Officer I', 9, NULL),
    ('REGIONAL_OFFICE', 'Development Management Officer I', 11, NULL),
    ('REGIONAL_OFFICE', 'Development Management Officer II', 15, NULL),
    ('REGIONAL_OFFICE', 'Development Management Officer III', 18, 'B'),
    ('REGIONAL_OFFICE', 'Development Management Officer IV', 22, 'B'),
    ('REGIONAL_OFFICE', 'Development Management Officer V', 24, 'I'),
    ('REGIONAL_OFFICE', 'Draftsman I', 6, NULL),
    ('REGIONAL_OFFICE', 'Draftsman II', 8, NULL),
    ('REGIONAL_OFFICE', 'Ecosystems Management Specialist I', 11, NULL),
    ('REGIONAL_OFFICE', 'Ecosystems Management Specialist II', 15, NULL),
    ('REGIONAL_OFFICE', 'Engineer I (Geodetic Engineer)', 12, NULL),
    ('REGIONAL_OFFICE', 'Engineer II (Geodetic Engineer)', 16, 'B'),
    ('REGIONAL_OFFICE', 'Engineer III (Geodetic Engineer)', 19, 'B'),
    ('REGIONAL_OFFICE', 'Engineer IV (Geodetic Engineer)', 22, 'B'),
    ('REGIONAL_OFFICE', 'Engineer V (Geodetic Engineer)', 24, 'I'),
    ('REGIONAL_OFFICE', 'Engineering Aide', 4, NULL),
    ('REGIONAL_OFFICE', 'Forest Ranger', 4, NULL),
    ('REGIONAL_OFFICE', 'Forest Technician I', 6, NULL),
    ('REGIONAL_OFFICE', 'Forest Technician II', 8, NULL),
    ('REGIONAL_OFFICE', 'Forester I', 11, NULL),
    ('REGIONAL_OFFICE', 'Forester II', 15, NULL),
    ('REGIONAL_OFFICE', 'Forester III', 18, 'B'),
    ('REGIONAL_OFFICE', 'Information Officer I', 11, NULL),
    ('REGIONAL_OFFICE', 'Information Officer II', 15, NULL),
    ('REGIONAL_OFFICE', 'Information Systems Analyst I', 12, NULL),
    ('REGIONAL_OFFICE', 'Information Systems Analyst II', 16, NULL),
    ('REGIONAL_OFFICE', 'Information Systems Analyst III', 19, 'B'),
    ('REGIONAL_OFFICE', 'Laborer I', 1, NULL),
    ('REGIONAL_OFFICE', 'Land Management Inspector', 6, NULL),
    ('REGIONAL_OFFICE', 'Land Management Officer I', 11, NULL),
    ('REGIONAL_OFFICE', 'Land Management Officer II', 15, NULL),
    ('REGIONAL_OFFICE', 'Land Management Officer III', 18, 'B'),
    ('REGIONAL_OFFICE', 'Legal Assistant II', 12, NULL),
    ('REGIONAL_OFFICE', 'Mathematician Aide I', 6, NULL),
    ('REGIONAL_OFFICE', 'Mathematician Aide II', 8, NULL),
    ('REGIONAL_OFFICE', 'Mathematician I', 10, NULL),
    ('REGIONAL_OFFICE', 'Park Attendant II', 4, NULL),
    ('REGIONAL_OFFICE', 'Park Maintenance Foreman', 8, NULL),
    ('REGIONAL_OFFICE', 'Park Operation Superintendent IV', 22, NULL),
    ('REGIONAL_OFFICE', 'Park Operation Superintendent V', 24, NULL),
    ('REGIONAL_OFFICE', 'Planning Officer I', 11, NULL),
    ('REGIONAL_OFFICE', 'Planning Officer II', 15, NULL),
    ('REGIONAL_OFFICE', 'Planning Officer III', 18, 'B'),
    ('REGIONAL_OFFICE', 'Planning Officer IV', 22, 'B'),
    ('REGIONAL_OFFICE', 'Planning Officer V', 24, 'I'),
    ('REGIONAL_OFFICE', 'Project Evaluation Officer I', 11, NULL),
    ('REGIONAL_OFFICE', 'Project Evaluation Officer II', 15, NULL),
    ('REGIONAL_OFFICE', 'Project Evaluation Officer III', 18, 'B'),
    ('REGIONAL_OFFICE', 'Provincial Environment & Natural Resources Officer', 26, 'I'),
    ('REGIONAL_OFFICE', 'Scaler', 5, NULL),
    ('REGIONAL_OFFICE', 'Senior Communications Development Officer', 18, 'B'),
    ('REGIONAL_OFFICE', 'Senior Ecosystems Management Specialist', 18, 'B'),
    ('REGIONAL_OFFICE', 'Special Investigator I', 11, NULL),
    ('REGIONAL_OFFICE', 'Statistician I', 11, NULL),
    ('REGIONAL_OFFICE', 'Statistician II', 15, NULL),
    ('REGIONAL_OFFICE', 'Supervising Administrative Officer', 22, 'B'),
    ('REGIONAL_OFFICE', 'Supervising Ecosystems Management Specialist', 22, 'B'),
    ('REGIONAL_OFFICE', 'Tracer', 3, NULL),
    ('REGIONAL_OFFICE', 'Tree Marker', 2, NULL),
    ('REGIONAL_OFFICE', 'Utility Worker I', 1, NULL),
    ('REGIONAL_OFFICE', 'Zoology Technician', 6, NULL)
ON DUPLICATE KEY UPDATE
    salary_grade = VALUES(salary_grade),
    leadership_level = VALUES(leadership_level);

INSERT INTO positions_seed_state_v2 (id, seed_version) VALUES (1, 2)
ON DUPLICATE KEY UPDATE seed_version = GREATEST(seed_version, VALUES(seed_version));

INSERT IGNORE INTO positions (office_scope, title, salary_grade, leadership_level)
SELECT 'FIELD_OFFICES', title, salary_grade, leadership_level
FROM positions
WHERE office_scope = 'REGIONAL_OFFICE'
  AND title IN (
    'Administrative Aide IV','Administrative Aide VI',
    'Administrative Assistant I (Computer Operator I)','Administrative Assistant II (Bookkeeper)',
    'Administrative Assistant III (Computer Operator II)','Credit Officer I','Park Attendant II',
    'Forest Ranger','Engineering Aide','Cartographer I','Cartographer II',
    'Land Management Inspector','Forest Technician I','Forest Technician II','Park Maintenance Foreman',
    'Administrative Officer I (Cashier I)','Administrative Officer I (Records Officer I)',
    'Administrative Officer I (Supply Officer I)','Planning Officer I','Planning Officer II',
    'Information Systems Analyst II','Administrative Officer IV (Budget Officer II)',
    'Administrative Officer IV (HRMO II)','Forester I','Forester II',
    'Ecosystems Management Specialist I','Ecosystems Management Specialist II',
    'Land Management Officer I','Land Management Officer II','Special Investigator I',
    'Community Development Officer II','Development Management Officer I',
    'Development Management Officer II','Engineer II (Geodetic Engineer)',
    'Accountant III','Planning Officer III','Development Management Officer III',
    'Forester III','Land Management Officer III','Senior Ecosystems Management Specialist',
    'Supervising Ecosystems Management Specialist'
  );

UPDATE users
SET account_type = 'field'
WHERE role <> 'admin'
  AND account_type IS NULL
  AND field_name IS NOT NULL
  AND field_name <> '';

UPDATE users
SET account_type = 'region'
WHERE role <> 'admin'
  AND account_type IS NULL
  AND region IS NOT NULL
  AND region <> '';

CREATE TABLE IF NOT EXISTS form_drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    status ENUM('draft','saved') NOT NULL DEFAULT 'draft',
    template_id BIGINT UNSIGNED NULL,
    salary_grade TINYINT UNSIGNED NULL,
    position_title VARCHAR(255),
    office_name VARCHAR(255),
    candidate_name VARCHAR(255),
    form_json LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user_status_updated (user_id, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS submission_attempts (
    ip VARCHAR(45) NOT NULL PRIMARY KEY,
    `count` INT NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sheet_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    title VARCHAR(120) NOT NULL DEFAULT 'Untitled sheet',
    cells LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sheet_documents_owner_updated (owner_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS competency_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    title VARCHAR(120) NOT NULL DEFAULT 'Untitled template',
    layout_json LONGTEXT NOT NULL,
    min_salary_grade TINYINT UNSIGNED NULL DEFAULT NULL,
    max_salary_grade TINYINT UNSIGNED NULL DEFAULT NULL,
    region VARCHAR(100) NULL DEFAULT NULL,
    field_name VARCHAR(100) NULL DEFAULT NULL,
    is_shared TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_competency_templates_owner_updated (owner_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deleted_candidates (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS score_audit (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS database_backups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_database_backups_completed (completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS office_tabs (
    account_type ENUM('RO', 'FO') NOT NULL,
    office VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_type, office)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_reset_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    status ENUM('pending','approved','rejected','used','expired') NOT NULL DEFAULT 'pending',
    token_hash CHAR(64) NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    expires_at DATETIME NULL,
    completed_at DATETIME NULL,
    reviewed_by INT NULL,
    INDEX idx_reset_status_requested (status, requested_at),
    CONSTRAINT fk_password_reset_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_candidate_user_id = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'candidates'
      AND COLUMN_NAME = 'user_id'
);
SET @candidate_user_id_sql = IF(
    @has_candidate_user_id = 0,
    'ALTER TABLE `candidates` ADD COLUMN `user_id` INT NULL',
    'SELECT ''candidates.user_id already exists'' AS status'
);
PREPARE candidate_user_id_stmt FROM @candidate_user_id_sql;
EXECUTE candidate_user_id_stmt;
DEALLOCATE PREPARE candidate_user_id_stmt;

SET @has_candidate_custom_json = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'candidates'
      AND COLUMN_NAME = 'custom_json'
);
SET @candidate_custom_json_sql = IF(
    @has_candidate_custom_json = 0,
    'ALTER TABLE `candidates` ADD COLUMN `custom_json` LONGTEXT NULL',
    'SELECT ''candidates.custom_json already exists'' AS status'
);
PREPARE candidate_custom_json_stmt FROM @candidate_custom_json_sql;
EXECUTE candidate_custom_json_stmt;
DEALLOCATE PREPARE candidate_custom_json_stmt;

SELECT 'users' AS table_name, COLUMN_NAME AS column_name
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'users'
  AND COLUMN_NAME IN ('email','region','field_name','account_type','salary_grade','rater_position')
UNION ALL
SELECT 'positions', COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @database_name AND TABLE_NAME = 'positions'
  AND COLUMN_NAME IN ('office_scope','source_sheet','title','salary_grade','leadership_level');

SELECT office_scope, COUNT(*) AS total
FROM positions
GROUP BY office_scope;
