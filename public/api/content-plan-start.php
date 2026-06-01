<?php
/**
 * Content plan generation — thin queue endpoint.
 *
 * Creates an agent_runs row, fires agent/content-plan-generate.php in the
 * background via nohup/START, returns the job_id immediately. UI polls
 * /api/content-plan-status.php for current_step + final result.
 *
 * POST JSON: { site_id, cadence?, horizon?, forecast_horizon?, goal? }
 * Returns:   { success: true, job_id: int }
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

function cps_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) {
        $stray = ob_get_clean();
        if (trim($stray) !== '') error_log('[content-plan-start] stray output: ' . substr($stray, 0, 500));
    }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);
if (!$site_id) cps_respond(['error' => 'site_id required'], 400);
if (!auth_can_access_site($db, $site_id)) cps_respond(['error' => 'Site not found'], 404);

// If a plan generation is already running, just return that job_id
$stmt = $db->prepare("SELECT id FROM agent_runs WHERE site_id = ? AND job_type = 'content_plan_generate'
    AND status = 'running' AND started_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY id DESC LIMIT 1");
$stmt->execute([$site_id]);
$existing = (int)$stmt->fetchColumn();
if ($existing) cps_respond(['success' => true, 'job_id' => $existing, 'note' => 'A plan run is already in flight — polling that one.']);

$db->prepare('INSERT INTO agent_runs (site_id, job_type, status, current_step, triggered_by, started_at)
    VALUES (?, ?, "running", "Queued — starting plan generation", "manual", NOW())')
   ->execute([$site_id, 'content_plan_generate']);
$run_id = (int)$db->lastInsertId();

$php = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
$script = realpath(__DIR__ . '/../../agent/content-plan-generate.php');
if (!$script) {
    $db->prepare('UPDATE agent_runs SET status="failed", error=?, finished_at=NOW() WHERE id=?')
       ->execute(['CLI script not found', $run_id]);
    cps_respond(['error' => 'CLI script not found'], 500);
}

// Optional config from request body
$cadence  = (int)($input['cadence'] ?? 0);
$horizon  = (int)($input['horizon'] ?? 0);
$fhorizon = (int)($input['forecast_horizon'] ?? 0);
$goal     = trim((string)($input['goal'] ?? ''));

$extra_args = '';
if ($cadence  > 0) $extra_args .= ' --cadence=' . $cadence;
if ($horizon  > 0) $extra_args .= ' --horizon=' . $horizon;
if ($fhorizon > 0) $extra_args .= ' --forecast_horizon=' . $fhorizon;
if ($goal    !== '') $extra_args .= ' --goal=' . escapeshellarg($goal);

if (PHP_OS_FAMILY === 'Windows') {
    $cmd = sprintf('start /B "" "%s" "%s" --site=%d --run=%d %s', $php, $script, $site_id, $run_id, $extra_args);
    pclose(popen($cmd, 'r'));
} else {
    $log = config('log_path') . '/content-plan-generate.log';
    $cmd = sprintf(
        'nohup %s %s --site=%d --run=%d %s >> %s 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($script),
        $site_id,
        $run_id,
        $extra_args,
        escapeshellarg($log)
    );
    exec($cmd);
}

cps_respond(['success' => true, 'job_id' => $run_id]);
