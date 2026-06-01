<?php
/**
 * Cron job actions — super-admin only.
 *
 * POST JSON:
 *   { action: 'run_now',  schedule_id }
 *   { action: 'toggle',   schedule_id, enabled: 0|1 }
 *
 * Future actions (deferred): create / edit / delete schedules.
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cron_scheduler.php';

auth_start();
if (!auth_check() || !auth_is_super_admin()) {
    http_response_code(403); ob_end_clean(); echo json_encode(['error' => 'Super admin only']); exit;
}

header('Content-Type: application/json');

function cja_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) { $stray = ob_get_clean(); if (trim($stray) !== '') error_log('[cron-job-action] stray: ' . substr($stray, 0, 500)); }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');
$schedule_id = (int)($input['schedule_id'] ?? 0);

if (!$schedule_id) cja_respond(['error' => 'schedule_id required'], 400);

switch ($action) {
    case 'run_now':
        try {
            $run_id = cron_scheduler_run_now($db, $schedule_id);
            cja_respond(['success' => true, 'run_id' => $run_id]);
        } catch (Throwable $e) {
            cja_respond(['error' => $e->getMessage()], 500);
        }
    case 'toggle':
        $enabled = (int)($input['enabled'] ?? 0) ? 1 : 0;
        $db->prepare("UPDATE cron_schedules SET enabled = ? WHERE id = ?")->execute([$enabled, $schedule_id]);
        cja_respond(['success' => true]);
    default:
        cja_respond(['error' => 'Unknown action'], 400);
}
