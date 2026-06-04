<?php
/**
 * Wayback Harvester — kick-off endpoint.
 *
 * POST JSON: { site_id }
 *
 * Validates access + launches the harvest CLI in the background. Returns
 * immediately with a poll-able job_id (= wayback_runs.id). Front-end polls
 * /api/wayback-status.php?site_id=... to update the progress UI.
 *
 * Heavy work never runs in PHP-FPM — exec(... &) hands off to a long-lived
 * CLI process matching the pattern in keyword-research-start.php.
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }
header('Content-Type: application/json');

function wb_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_end_clean();
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);
if ($site_id <= 0) wb_respond(['error' => 'site_id required'], 400);

$site = auth_get_accessible_site($db, $site_id);
if (!$site) wb_respond(['error' => 'Site not found'], 404);

// Bail if there's already a run in flight — avoids parallel CDX hammering.
$stmt = $db->prepare("SELECT id, started_at FROM wayback_runs WHERE site_id = ? AND status = 'running' ORDER BY id DESC LIMIT 1");
$stmt->execute([$site_id]);
if ($running = $stmt->fetch()) {
    // Treat anything older than 30 min as stale; let new one go through.
    if (strtotime($running['started_at']) > time() - 1800) {
        wb_respond(['success' => true, 'job_id' => (int)$running['id'], 'already_running' => true]);
    }
    $db->prepare("UPDATE wayback_runs SET status='failed', finished_at=NOW(), error='superseded by new run' WHERE id=?")
       ->execute([(int)$running['id']]);
}

$php    = config('php_path') ?: '/usr/bin/php8.3';
$script = realpath(__DIR__ . '/../../agent/cron-wayback-harvest.php');
if (!$script || !file_exists($script)) wb_respond(['error' => 'CLI script not found'], 500);

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

wb_respond(['success' => true, 'job_id' => null, 'launched' => true]);
