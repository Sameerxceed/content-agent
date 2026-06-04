<?php
/**
 * Wayback Live-Check — kick-off endpoint.
 *
 * POST JSON: { site_id }
 *
 * Backgrounds the URL-status checker. Returns immediately; UI polls
 * /api/wayback-status.php to see the unchecked counter drop.
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }
header('Content-Type: application/json');

function wbc_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_end_clean();
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);
if ($site_id <= 0) wbc_respond(['error' => 'site_id required'], 400);

$site = auth_get_accessible_site($db, $site_id);
if (!$site) wbc_respond(['error' => 'Site not found'], 404);

$php    = config('php_path') ?: '/usr/bin/php8.3';
$script = realpath(__DIR__ . '/../../agent/cron-wayback-checkstatus.php');
if (!$script || !file_exists($script)) wbc_respond(['error' => 'CLI script not found'], 500);

$log_dir = config('log_path') ?: '/var/log/contentagent';
$log     = rtrim($log_dir, '/') . '/wayback.log';

if (PHP_OS_FAMILY === 'Windows') {
    $cmd = sprintf('start /B "" "%s" "%s" --site=%d', $php, $script, $site_id);
    pclose(popen($cmd, 'r'));
} else {
    $cmd = sprintf(
        'nohup %s %s --site=%d >> %s 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($script),
        $site_id,
        escapeshellarg($log)
    );
    exec($cmd);
}

wbc_respond(['success' => true, 'launched' => true]);
