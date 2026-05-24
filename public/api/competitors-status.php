<?php
/**
 * Poll the status of a competitor-discovery background job.
 *
 * GET /api/competitors-status.php?id=N
 * Returns: { status: 'running'|'done'|'failed', current_step, progress, summary, error }
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

function cs_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) {
        $stray = ob_get_clean();
        if (trim($stray) !== '') error_log('[competitors-status] stray output: ' . substr($stray, 0, 500));
    }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) cs_respond(['error' => 'id required'], 400);

$stmt = $db->prepare("SELECT id, site_id, job_type, status, current_step, progress, result_summary, error, started_at, finished_at FROM agent_runs WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) cs_respond(['error' => 'Job not found'], 404);

// Permission check — only owners / super-admin can see jobs for their sites.
if (!auth_can_access_site($db, (int)$row['site_id'])) cs_respond(['error' => 'Access denied'], 403);

// If a job has been "running" for > 10 minutes without an update, the CLI
// probably died. Mark it as failed so the UI stops polling forever.
if ($row['status'] === 'running' && $row['started_at']) {
    $age = time() - strtotime($row['started_at']);
    if ($age > 600) {
        $db->prepare('UPDATE agent_runs SET status="failed", error="Job timed out after 10 minutes — CLI may have crashed. Check competitors-discover.log on the server.", finished_at=NOW() WHERE id=?')
           ->execute([$id]);
        $row['status'] = 'failed';
        $row['error']  = 'Job timed out after 10 minutes';
    }
}

cs_respond([
    'status'       => $row['status'],
    'current_step' => $row['current_step'],
    'progress'     => (int)$row['progress'],
    'summary'      => $row['result_summary'] ? json_decode($row['result_summary'], true) : null,
    'error'        => $row['error'],
    'started_at'   => $row['started_at'],
    'finished_at'  => $row['finished_at'],
]);
