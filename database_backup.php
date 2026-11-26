<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

define('APP_SKIP_SCHEMA_CHECK', true);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/PositionsSchema.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/sheets_schema.php';
require_once __DIR__ . '/competency_template_schema.php';

function backup_write($stream, string $contents): void {
    $written = gzwrite($stream, $contents);
    if ($written !== strlen($contents)) {
        throw new RuntimeException('Could not write the complete database backup.');
    }
}

function backup_quote_identifier(string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function backup_database(mysqli $conn, string $path): void {
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $stream = gzopen($path, 'wb9');
    if ($stream === false) {
        $conn->rollback();
        throw new RuntimeException('Could not open the backup file for writing.');
    }

    try {
        backup_write($stream, "-- Database backup generated " . date(DATE_ATOM) . "\n");
        backup_write($stream, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SESSION sql_mode='';\n\n");
        $tablesResult = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($tableRow = $tablesResult->fetch_row()) {
            $table = (string)$tableRow[0];
            $quotedTable = backup_quote_identifier($table);
            $createResult = $conn->query('SHOW CREATE TABLE ' . $quotedTable);
            $createRow = $createResult->fetch_row();
            $createResult->free();
            if (!isset($createRow[1])) throw new RuntimeException('Could not read the definition for table ' . $table . '.');

            backup_write($stream, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n" . $createRow[1] . ";\n");
            $dataResult = $conn->query('SELECT * FROM ' . $quotedTable, MYSQLI_USE_RESULT);
            $fields = $dataResult->fetch_fields();
            $columns = implode(', ', array_map(
                static fn(mysqli_field $field): string => backup_quote_identifier($field->name),
                $fields
            ));
            while ($row = $dataResult->fetch_row()) {
                $values = [];
                foreach ($row as $value) {
                    $values[] = $value === null ? 'NULL' : "'" . $conn->real_escape_string((string)$value) . "'";
                }
                backup_write($stream, 'INSERT INTO ' . $quotedTable . ' (' . $columns . ') VALUES (' . implode(', ', $values) . ");\n");
            }
            $dataResult->free();
            backup_write($stream, "\n");
        }
        backup_write($stream, "SET FOREIGN_KEY_CHECKS=1;\n");
        $conn->commit();
    } catch (Throwable $exception) {
        try { $conn->rollback(); } catch (Throwable $rollbackException) {
            error_log('database_backup snapshot rollback failed: ' . $rollbackException->getMessage());
        }
        throw $exception;
    } finally {
        gzclose($stream);
    }
}

$backupPath = null;
try {
    if (!app_allow_ddl()) {
        throw new RuntimeException('Database backup must run from the application server command line.');
    }
    if (!extension_loaded('zlib')) {
        throw new RuntimeException('The PHP zlib extension is required to create compressed backups.');
    }

    ensure_auth_schema($conn);
    ensure_positions_schema($conn);
    ensure_schema($conn);
    ensure_competency_template_schema($conn);
    ensure_sheets_schema($conn);
    ensure_drafts_schema($conn);

    $configuredDirectory = trim((string)(getenv('APP_BACKUP_DIR') ?: ''));
    $backupDirectory = $configuredDirectory !== ''
        ? $configuredDirectory
        : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('Could not create the backup directory. Check APP_BACKUP_DIR permissions.');
    }
    $resolvedDirectory = realpath($backupDirectory);
    $resolvedApp = realpath(__DIR__);
    $resolvedAppParent = realpath(dirname(__DIR__));
    if ($resolvedDirectory === false || $resolvedApp === false || $resolvedAppParent === false) {
        throw new RuntimeException('Could not resolve the backup directory.');
    }
    if ($resolvedDirectory === $resolvedAppParent
        || str_starts_with($resolvedDirectory . DIRECTORY_SEPARATOR, $resolvedAppParent . DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Backups must be stored outside the application parent directory and web root.');
    }
    if (!chmod($resolvedDirectory, 0700) && (fileperms($resolvedDirectory) & 0777) !== 0700) {
        throw new RuntimeException('Could not restrict access to the backup directory.');
    }

    $filename = 'interview-rating-' . date('Ymd-His') . '.sql.gz';
    $backupPath = $resolvedDirectory . DIRECTORY_SEPARATOR . $filename;
    backup_database($conn, $backupPath);
    if (!is_file($backupPath) || filesize($backupPath) === 0) {
        throw new RuntimeException('The backup file is missing or empty.');
    }
    if (!chmod($backupPath, 0600) && (fileperms($backupPath) & 0777) !== 0600) {
        throw new RuntimeException('Could not restrict access to the backup file.');
    }
    $verificationStream = gzopen($backupPath, 'rb');
    if ($verificationStream === false) throw new RuntimeException('Could not verify the compressed backup.');
    try {
        while (!gzeof($verificationStream)) {
            if (gzread($verificationStream, 1024 * 1024) === false) {
                throw new RuntimeException('The compressed backup failed verification.');
            }
        }
    } finally {
        gzclose($verificationStream);
    }
    $hash = hash_file('sha256', $backupPath);
    if ($hash === false) throw new RuntimeException('Could not verify the completed backup file.');

    $fileSize = filesize($backupPath);
    if ($fileSize === false) throw new RuntimeException('Could not read the completed backup size.');
    $stmt = $conn->prepare('INSERT INTO database_backups (filename, size_bytes, sha256) VALUES (?, ?, ?)');
    $stmt->bind_param('sis', $filename, $fileSize, $hash);
    $stmt->execute();
    $stmt->close();

    $retentionCutoff = time() - (30 * 86400);
    foreach (new DirectoryIterator($resolvedDirectory) as $entry) {
        if (!$entry->isFile() || !preg_match('/^interview-rating-\d{8}-\d{6}\.sql\.gz$/', $entry->getFilename())) continue;
        if ($entry->getMTime() < $retentionCutoff && !unlink($entry->getPathname())) {
            error_log('database_backup could not remove expired backup: ' . $entry->getFilename());
        }
    }

    fwrite(STDOUT, 'Backup completed: ' . $filename . ' (' . $fileSize . " bytes)\n");
} catch (Throwable $exception) {
    if (is_string($backupPath) && is_file($backupPath) && !unlink($backupPath)) {
        error_log('database_backup could not remove failed backup file: ' . $backupPath);
    }
    error_log('database_backup failed: ' . $exception->getMessage());
    fwrite(STDERR, 'Database backup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
