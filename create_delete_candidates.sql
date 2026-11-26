-- Run this against the application's existing database.
-- Safe to run repeatedly; this creates no data and drops nothing.
-- Select the application database in Laragon before running this file.

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
