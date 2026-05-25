<?php
/**
 * Poll the status of a keyword-research background job.
 *
 * GET /api/keyword-research-status.php?id=N
 * Returns: { status: 'running'|'done'|'failed', current_step, progress, summary, error }
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

function krs_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) {
        $stray = ob_get_clean();
        if (trim($stray) !== '') error_log('[keyword-research-status] stray output: ' . substr($stray, 0, 500));
    }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) krs_respond(['error' => 'id required'], 400);

$stmt = $db->prepare("SELECT id, site_id, job_type, status, current_step, progress, result_summary, error, started_at, finished_at FROM agent_runs WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) krs_respond(['error' => 'Job not found'], 404);

if (!auth_can_access_site($db, (int)$row['site_id'])) krs_respond(['error' => 'Access denied'], 403);

// Stale-job guard: 15 min for keyword research (intent classification on
// ~400 keywords across 7 Claude calls can legitimately take 5-10 min).
if ($row['status'] === 'running' && $row['started_at']) {
    $age = time() - strtotime($row['started_at']);
    if ($age > 900) {
        $db->prepare('UPDATE agent_runs SET status="failed", error="Job timed out after 15 minutes — CLI may have crashed. Check keyword-research.log on the server.", finished_at=NOW() WHERE id=?')
           ->execute([$id]);
        $row['status'] = 'failed';
        $row['error']  = 'Job timed out after 15 minutes';
    }
}

krs_respond([
    'status'       => $row['status'],
    'current_step' => $row['current_step'],
    'progress'     => (int)$row['progress'],
    'summary'      => $row['result_summary'] ? json_decode($row['result_summary'], true) : null,
    'error'        => $row['error'],
    'started_at'   => $row['started_at'],
    'finished_at'  => $row['finished_at'],
]);
