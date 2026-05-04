<?php
/**
 * API — Sync project files to XAMPP htdocs.
 * POST /api/sync.php
 * Copies from E:\Xceed\Code\ContentAgent to C:\xampp\htdocs\contentagent
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();

if (!auth_check()) {
    json_response(['error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$source = 'E:\\Xceed\\Code\\ContentAgent';
$dest = 'C:\\xampp\\htdocs\\contentagent';

if (!is_dir($source)) {
    json_response(['error' => 'Source directory not found'], 500);
}

// Use robocopy on Windows for fast sync
$cmd = "robocopy \"{$source}\" \"{$dest}\" /E /MIR /NFL /NDL /NJH /NJS /NC /NS /NP";
exec($cmd, $output, $return);

// robocopy returns 0-7 for success, 8+ for errors
if ($return < 8) {
    json_response(['success' => true, 'message' => 'Files synced to htdocs']);
} else {
    // Fallback: xcopy
    $cmd2 = "xcopy \"{$source}\" \"{$dest}\" /E /Y /Q /I";
    exec($cmd2, $output2, $return2);

    if ($return2 === 0) {
        json_response(['success' => true, 'message' => 'Files synced to htdocs (xcopy)']);
    } else {
        json_response(['error' => 'Sync failed', 'code' => $return], 500);
    }
}
