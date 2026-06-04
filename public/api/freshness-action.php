<?php
/**
 * Content freshness actions.
 *
 * POST JSON: { action, site_id, ... }
 *   run            — launch audit (backgrounded)
 *   list           — paginated list of freshness rows
 *   queue_refresh  — turn a needs_refresh row into a Content Plan item
 *   dismiss        — user says "no, keep as is" — never surface again
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/content_freshness.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

function cf_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json'); http_response_code($status);
    echo json_encode($payload); exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');
$site_id = (int)($input['site_id'] ?? 0);
$site = auth_get_accessible_site($db, $site_id);
if (!$site) cf_respond(['error' => 'Site not found'], 404);

try {
    if ($action === 'run') {
        $php    = config('php_path') ?: '/usr/bin/php8.3';
        $script = realpath(__DIR__ . '/../../agent/cron-content-freshness.php');
        if (!$script) cf_respond(['error' => 'CLI script not found'], 500);
        $log = (config('log_path') ?: '/var/log/contentagent') . '/freshness.log';
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf('start /B "" "%s" "%s" --site=%d', $php, $script, $site_id);
            pclose(popen($cmd, 'r'));
        } else {
            $cmd = sprintf('nohup %s %s --site=%d >> %s 2>&1 &', escapeshellarg($php), escapeshellarg($script), $site_id, escapeshellarg($log));
            exec($cmd);
        }
        cf_respond(['success' => true, 'launched' => true]);
    }

    if ($action === 'queue_refresh') {
        $fid = (int)($input['freshness_id'] ?? 0);
        if (!$fid) cf_respond(['error' => 'freshness_id required'], 400);
        $r = cf_queue_refresh($db, $site_id, $fid);
        cf_respond($r);
    }

    if ($action === 'dismiss') {
        $fid = (int)($input['freshness_id'] ?? 0);
        $db->prepare("UPDATE content_freshness SET dismissed_at = NOW() WHERE id = ? AND site_id = ?")->execute([$fid, $site_id]);
        cf_respond(['success' => true]);
    }

    if ($action === 'list') {
        $filter = (string)($input['filter'] ?? 'pending');
        $where = "cf.site_id = ?"; $args = [$site_id];
        if ($filter === 'pending') { $where .= " AND cf.needs_refresh = 1 AND cf.queued_plan_item_id IS NULL AND cf.dismissed_at IS NULL"; }
        if ($filter === 'queued')  { $where .= " AND cf.queued_plan_item_id IS NOT NULL"; }
        if ($filter === 'dismissed') { $where .= " AND cf.dismissed_at IS NOT NULL"; }
        $stmt = $db->prepare("SELECT cf.id, cf.post_id, cf.staleness_score, cf.refresh_reason, cf.age_days, cf.queued_plan_item_id, cf.dismissed_at, p.title, p.slug
                              FROM content_freshness cf JOIN posts p ON p.id = cf.post_id
                              WHERE {$where} ORDER BY cf.staleness_score DESC LIMIT 200");
        $stmt->execute($args);
        cf_respond([
            'summary' => cf_site_summary($db, $site_id),
            'items'   => $stmt->fetchAll(),
        ]);
    }

    cf_respond(['error' => 'Unknown action: ' . $action], 400);
} catch (Throwable $e) {
    error_log('[freshness-action] ' . $e->getMessage());
    cf_respond(['error' => $e->getMessage()], 500);
}
