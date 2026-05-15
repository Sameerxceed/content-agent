<?php
/**
 * Phase 3 — Kick off a background gap-analysis job.
 *
 * Spawns the CLI agent so the request returns immediately. The UI polls
 * /api/gaps-status.php to track progress.
 *
 * POST JSON: { site_id }
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);
if (!$site_id) { http_response_code(400); echo json_encode(['error' => 'site_id required']); exit; }

// Verify ownership
if (!auth_can_access_site($db, $site_id)) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }

// Need active competitors
$stmt = $db->prepare("SELECT COUNT(*) FROM competitors WHERE site_id = ? AND status = 'active'");
$stmt->execute([$site_id]);
if ((int)$stmt->fetchColumn() < 1) {
    echo json_encode(['error' => 'No active competitors. Run "Discover Competitors" first.']);
    exit;
}

// Don't allow concurrent runs for the same site
$stmt = $db->prepare("SELECT id FROM gap_runs WHERE site_id = ? AND status IN ('queued','running') ORDER BY started_at DESC LIMIT 1");
$stmt->execute([$site_id]);
$existing = $stmt->fetchColumn();
if ($existing) {
    echo json_encode(['success' => true, 'run_id' => (int)$existing, 'already_running' => true]);
    exit;
}

// Create the run row
$db->prepare('INSERT INTO gap_runs (site_id, status, current_step) VALUES (?, "queued", "Waiting to start")')
    ->execute([$site_id]);
$run_id = (int)$db->lastInsertId();

// Spawn background CLI process
$php = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
$script = realpath(__DIR__ . '/../../agent/gap-analysis.php');
$log = sys_get_temp_dir() . "/gap-{$run_id}.log";

if (PHP_OS_FAMILY === 'Windows') {
    // Best-effort background spawn on Windows
    pclose(popen("start /B \"\" \"{$php}\" \"{$script}\" --site={$site_id} --run={$run_id} > \"{$log}\" 2>&1", 'r'));
} else {
    shell_exec("nohup \"{$php}\" \"{$script}\" --site={$site_id} --run={$run_id} > \"{$log}\" 2>&1 &");
}

echo json_encode(['success' => true, 'run_id' => $run_id]);
