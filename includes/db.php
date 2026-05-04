<?php
/**
 * Database connection wrapper using PDO.
 * Usage: $db = require __DIR__ . '/db.php';
 */

$config = require __DIR__ . '/../config/config.php';

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['db_host'],
        $config['db_port'],
        $config['db_name'],
        $config['db_charset']
    );

    $db = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Set timezone to IST
    $db->exec("SET time_zone = '+05:30'");

} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    if ($config['app_debug']) {
        die('Database connection failed: ' . $e->getMessage());
    }
    die('Database connection failed. Check logs.');
}

return $db;
