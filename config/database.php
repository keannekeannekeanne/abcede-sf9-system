<?php
/**
 * Database connection using PDO with prepared-statement-safe defaults.
 * Edit the constants below to match your local XAMPP MySQL setup.
 */

require_once __DIR__ . '/../src/Http/Http.php';

const DB_HOST = '127.0.0.1';
const DB_NAME = 'sf9_system';
const DB_USER = 'root';
const DB_PASS = '';          // set your MySQL root password here, if any
const DB_CHARSET = 'utf8mb4';

function get_db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Never leak DSN/credentials to the client.
        error_log('DB connection failed: ' . $e->getMessage());
        // Handled by Json::boot() (API) or Page::boot() (pages), so the caller
        // always gets a well-formed JSON / HTML error rather than bare text.
        throw new HttpException(503, 'The database is unavailable right now. Please try again shortly.');
    }

    return $pdo;
}
