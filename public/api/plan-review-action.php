<?php
/**
 * Plan review actions — apply approved changes or reject the review.
 *
 * POST JSON:
 *   { action: 'apply',  review_id, approved_ids: ['swap:0', 'addition:1', ...] }
 *   { action: 'reject', review_id }
 *   { action: 'generate_now', plan_id }   — manual trigger of monthly review
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/plan_review.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

function pra_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) { $stray = ob_get_clean(); if (trim($stray) !== '') error_log('[plan-review-action] stray: ' . substr($stray, 0, 500)); }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');

switch ($action) {

    case 'apply':
        $review_id = (int)($input['review_id'] ?? 0);
        $approved_ids = array_values((array)($input['approved_ids'] ?? []));
        if (!$review_id) pra_respond(['error' => 'review_id required'], 400);
        $stmt = $db->prepare("SELECT site_id FROM plan_reviews WHERE id = ?");
        $stmt->execute([$review_id]);
        $sid = (int)$stmt->fetchColumn();
        if (!$sid) pra_respond(['error' => 'Review not found'], 404);
        if (!auth_can_access_site($db, $sid)) pra_respond(['error' => 'Access denied'], 403);
        try {
            $applied = review_apply($db, $review_id, $approved_ids);
            pra_respond(['success' => true, 'applied' => $applied]);
        } catch (Throwable $e) {
            pra_respond(['error' => $e->getMessage()], 500);
        }

    case 'reject':
        $review_id = (int)($input['review_id'] ?? 0);
        if (!$review_id) pra_respond(['error' => 'review_id required'], 400);
        $stmt = $db->prepare("SELECT site_id FROM plan_reviews WHERE id = ?");
        $stmt->execute([$review_id]);
        $sid = (int)$stmt->fetchColumn();
        if (!$sid) pra_respond(['error' => 'Review not found'], 404);
        if (!auth_can_access_site($db, $sid)) pra_respond(['error' => 'Access denied'], 403);
        $db->prepare("UPDATE plan_reviews SET status='rejected', reviewed_at=NOW() WHERE id=?")->execute([$review_id]);
        pra_respond(['success' => true]);

    case 'generate_now':
        $plan_id = (int)($input['plan_id'] ?? 0);
        if (!$plan_id) pra_respond(['error' => 'plan_id required'], 400);
        $stmt = $db->prepare("SELECT site_id FROM content_plans WHERE id = ?");
        $stmt->execute([$plan_id]);
        $sid = (int)$stmt->fetchColumn();
        if (!$sid) pra_respond(['error' => 'Plan not found'], 404);
        if (!auth_can_access_site($db, $sid)) pra_respond(['error' => 'Access denied'], 403);
        // Fire the cron CLI in background
        $php = PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\php\\php.exe' : '/usr/bin/php8.3';
        $script = realpath(__DIR__ . '/../../agent/cron-plan-monthly-review.php');
        if (!$script) pra_respond(['error' => 'CLI script not found'], 500);
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf('start /B "" "%s" "%s" --plan=%d --force', $php, $script, $plan_id);
            pclose(popen($cmd, 'r'));
        } else {
            $log = config('log_path') . '/cron-plan-monthly-review.log';
            $cmd = sprintf('nohup %s %s --plan=%d --force >> %s 2>&1 &', escapeshellarg($php), escapeshellarg($script), $plan_id, escapeshellarg($log));
            exec($cmd);
        }
        pra_respond(['success' => true, 'message' => 'Review generation queued — should be ready in ~1 minute.']);

    default:
        pra_respond(['error' => 'Unknown action: ' . $action], 400);
}
