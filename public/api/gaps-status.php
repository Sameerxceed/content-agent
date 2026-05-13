<?php
/**
 * Phase 3 — Gap analysis status polling.
 *
 * GET ?site_id=X    (returns latest run for the site)
 * GET ?run_id=Y
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site_id'] ?? 0);
$run_id = (int)($_GET['run_id'] ?? 0);

if ($run_id) {
    $stmt = $db->prepare('SELECT gr.* FROM gap_runs gr JOIN sites s ON gr.site_id = s.id WHERE gr.id = ? AND s.user_id = ?');
    $stmt->execute([$run_id, $user_id]);
} elseif ($site_id) {
    $stmt = $db->prepare('SELECT gr.* FROM gap_runs gr JOIN sites s ON gr.site_id = s.id WHERE gr.site_id = ? AND s.user_id = ? ORDER BY gr.started_at DESC LIMIT 1');
    $stmt->execute([$site_id, $user_id]);
} else {
    http_response_code(400); echo json_encode(['error' => 'site_id or run_id required']); exit;
}

$row = $stmt->fetch();
if (!$row) { echo json_encode(['success' => true, 'run' => null]); exit; }

echo json_encode([
    'success' => true,
    'run' => [
        'id'                 => (int)$row['id'],
        'status'             => $row['status'],
        'progress'           => (int)$row['progress'],
        'current_step'       => $row['current_step'],
        'competitors_scanned' => (int)$row['competitors_scanned'],
        'pages_scanned'      => (int)$row['pages_scanned'],
        'gaps_found'         => (int)$row['gaps_found'],
        'error'              => $row['error'],
        'started_at'         => $row['started_at'],
        'finished_at'        => $row['finished_at'],
    ],
]);
