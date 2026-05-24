<?php
/**
 * Competitor discovery — thin queue endpoint.
 *
 * Creates an agent_runs row, fires agent/competitors-discover.php in the
 * background, returns the job_id immediately. UI polls
 * /api/competitors-status.php?id=N every few seconds for current_step and the
 * final result.
 *
 * The heavy work (Claude query generation, SERP loops, filters, DB inserts)
 * runs in the CLI process. Sub-second response time here — no more proxy
 * timeouts.
 *
 * POST JSON: { site_id }
 * Returns:   { success: true, job_id: int }
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

function cd_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) {
        $stray = ob_get_clean();
        if (trim($stray) !== '') error_log('[competitors-discover queue] stray output: ' . substr($stray, 0, 500));
    }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);
if (!$site_id) cd_respond(['error' => 'site_id required'], 400);

if (!auth_can_access_site($db, $site_id)) cd_respond(['error' => 'Site not found'], 404);

// If a discovery for this site is already running, don't start a second one.
// Just return the existing job_id so the UI can keep polling it.
$stmt = $db->prepare("SELECT id FROM agent_runs WHERE site_id = ? AND job_type = 'competitor_redetect' AND status = 'running' AND started_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY id DESC LIMIT 1");
$stmt->execute([$site_id]);
$existing = (int)$stmt->fetchColumn();
if ($existing) cd_respond(['success' => true, 'job_id' => $existing, 'note' => 'A discovery is already in flight — polling that one.']);

// Create the agent_runs row.
$db->prepare('INSERT INTO agent_runs (site_id, job_type, status, current_step, triggered_by, started_at) VALUES (?, ?, "running", "Queued — starting discovery agent", "manual", NOW())')
   ->execute([$site_id, 'competitor_redetect']);
$run_id = (int)$db->lastInsertId();

// Fire the CLI in the background. Same nohup / START /B pattern as
// public/api/onboarding.php for business-profile-infer.php.
$php = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
$script = realpath(__DIR__ . '/../../agent/competitors-discover.php');
if (!$script) {
    $db->prepare('UPDATE agent_runs SET status="failed", error=?, finished_at=NOW() WHERE id=?')
       ->execute(['CLI script not found', $run_id]);
    cd_respond(['error' => 'CLI script not found'], 500);
}

if (PHP_OS_FAMILY === 'Windows') {
    $cmd = sprintf('start /B "" "%s" "%s" --site=%d --run=%d', $php, $script, $site_id, $run_id);
    pclose(popen($cmd, 'r'));
} else {
    $log = config('log_path') . '/competitors-discover.log';
    $cmd = sprintf(
        'nohup %s %s --site=%d --run=%d >> %s 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($script),
        $site_id,
        $run_id,
        escapeshellarg($log)
    );
    exec($cmd);
}

cd_respond(['success' => true, 'job_id' => $run_id]);
