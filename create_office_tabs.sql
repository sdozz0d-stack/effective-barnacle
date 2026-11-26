-- Run this against the application's existing database.
-- Safe to run repeatedly; this creates no data and drops nothing.
-- Select the application database in Laragon before running this file.

CREATE TABLE IF NOT EXISTS office_tabs (
    account_type ENUM('RO', 'FO') NOT NULL,
    office VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_type, office)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
