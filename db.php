<?php
/**
 * Database connection handler
 * Supports both MySQL (Production cPanel / XAMPP) and SQLite (Zero-config local testing).
 */

if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/config.php';

$dbDriver = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';

try {
    if ($dbDriver === 'sqlite') {
        $dbPath = defined('DB_PATH') ? DB_PATH : __DIR__ . '/database.sqlite';
        $pdo = new PDO("sqlite:" . $dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("PRAGMA foreign_keys = ON;");
    } else {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    if (defined('APP_TIMEZONE')) {
        date_default_timezone_set(APP_TIMEZONE);
    }
} catch (PDOException $e) {
    die("<h3>Database Connection Error</h3><p>Could not connect to the database. Please check your <code>config.php</code> or run the <a href='install.php'>installer</a>.</p><p><small>Error details: " . htmlspecialchars($e->getMessage()) . "</small></p>");
}

function is_sqlite() {
    global $pdo;
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

/**
 * Helper to fetch a setting value from settings table
 */
function get_setting($key, $default = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false) ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Helper to update a setting value
 */
function set_setting($key, $value) {
    global $pdo;
    if (is_sqlite()) {
        $stmt = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON CONFLICT(key_name) DO UPDATE SET key_value = excluded.key_value");
    } else {
        $stmt = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
    }
    return $stmt->execute([$key, $value]);
}
