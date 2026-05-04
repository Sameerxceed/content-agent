<?php
/**
 * API — Trigger a site scan.
 * POST /api/scan.php
 * Body: { "site_id": 1 } or { "url": "https://example.com" }
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();

// Auth: session or API key
if (!auth_check() && !auth_api_verify()) {
    json_response(['error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$site_id = $input['site_id'] ?? null;

if (!$site_id) {
    json_response(['error' => 'site_id is required'], 400);
}

$db = require __DIR__ . '/../../includes/db.php';

// Verify ownership if session auth
if (auth_check()) {
    $stmt = $db->prepare('SELECT id FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([$site_id, auth_user_id()]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'Site not found'], 404);
    }
}

// Run scanner in background
$php = PHP_BINARY ?: 'php';
$script = realpath(__DIR__ . '/../../agent/scanner.php');
$cmd = escapeshellcmd($php) . ' ' . escapeshellarg($script) . ' --site=' . (int)$site_id;

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    pclose(popen("start /B $cmd", 'r'));
} else {
    exec("$cmd > /dev/null 2>&1 &");
}

json_response([
    'success' => true,
    'message' => 'Scanner started for site #' . $site_id,
]);
