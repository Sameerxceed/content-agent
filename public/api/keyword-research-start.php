<?php
/**
 * Keyword research — thin queue endpoint.
 *
 * Mirrors competitors-discover.php: creates an agent_runs row, fires
 * agent/keyword-research.php in the background via nohup/START, returns the
 * job_id immediately. UI polls /api/keyword-research-status.php for progress.
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

function kr_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) {
        $stray = ob_get_clean();
        if (trim($stray) !== '') error_log('[keyword-research-start] stray output: ' . substr($stray, 0, 500));
    }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);
if (!$site_id) kr_respond(['error' => 'site_id required'], 400);
if (!auth_can_access_site($db, $site_id)) kr_respond(['error' => 'Site not found'], 404);

// If a research for this site is already running, return the existing job_id
// so the UI just attaches to it instead of starting a duplicate.
$stmt = $db->prepare("SELECT id FROM agent_runs WHERE site_id = ? AND job_type = 'keyword_research' AND status = 'running' AND started_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY id DESC LIMIT 1");
$stmt->execute([$site_id]);
$existing = (int)$stmt->fetchColumn();
if ($existing) kr_respond(['success' => true, 'job_id' => $existing, 'note' => 'A research run is already in flight — polling that one.']);

$db->prepare('INSERT INTO agent_runs (site_id, job_type, status, current_step, triggered_by, started_at) VALUES (?, ?, "running", "Queued — starting keyword research", "manual", NOW())')
   ->execute([$site_id, 'keyword_research']);
$run_id = (int)$db->lastInsertId();

$php = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
$script = realpath(__DIR__ . '/../../agent/keyword-research.php');
if (!$script) {
    $db->prepare('UPDATE agent_runs SET status="failed", error=?, finished_at=NOW() WHERE id=?')
       ->execute(['CLI script not found', $run_id]);
    kr_respond(['error' => 'CLI script not found'], 500);
}

if (PHP_OS_FAMILY === 'Windows') {
    $cmd = sprintf('start /B "" "%s" "%s" --site=%d --run=%d', $php, $script, $site_id, $run_id);
    pclose(popen($cmd, 'r'));
} else {
    $log = config('log_path') . '/keyword-research.log';
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

kr_respond(['success' => true, 'job_id' => $run_id]);
