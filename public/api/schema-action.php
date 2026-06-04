<?php
/**
 * Schema audit actions.
 *
 * POST JSON: { action, site_id, ... }
 *
 *   run        — launch audit run (backgrounded)
 *   list       — paginated list with filters
 *   register   — manually add a URL + expected types to track
 *   delete     — stop tracking a URL
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/schema_auditor.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

function sch_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');
$site_id = (int)($input['site_id'] ?? 0);
$site = auth_get_accessible_site($db, $site_id);
if (!$site) sch_respond(['error' => 'Site not found'], 404);

try {
    if ($action === 'run') {
        $php    = config('php_path') ?: '/usr/bin/php8.3';
        $script = realpath(__DIR__ . '/../../agent/cron-schema-audit.php');
        if (!$script) sch_respond(['error' => 'CLI script not found'], 500);
        $log = (config('log_path') ?: '/var/log/contentagent') . '/schema.log';
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf('start /B "" "%s" "%s" --site=%d', $php, $script, $site_id);
            pclose(popen($cmd, 'r'));
        } else {
            // setsid detaches from PHP-FPM process group so the job survives reaping.
            $cmd = sprintf('setsid %s %s --site=%d </dev/null >> %s 2>&1 &', escapeshellarg($php), escapeshellarg($script), $site_id, escapeshellarg($log));
            exec($cmd);
        }
        sch_respond(['success' => true, 'launched' => true]);
    }

    if ($action === 'register') {
        $url = trim((string)($input['url'] ?? ''));
        $types = $input['expected_types'] ?? [];
        if ($url === '' || !is_array($types) || empty($types)) sch_respond(['error' => 'url + expected_types required'], 400);
        sch_register_url($db, $site_id, $url, array_map('strval', $types));
        sch_respond(['success' => true]);
    }

    if ($action === 'delete') {
        $id = (int)($input['audit_id'] ?? 0);
        $db->prepare("DELETE FROM schema_audits WHERE id = ? AND site_id = ?")->execute([$id, $site_id]);
        sch_respond(['success' => true]);
    }

    if ($action === 'list') {
        $filter = (string)($input['filter'] ?? 'all');
        $where = "site_id = ?"; $args = [$site_id];
        if (in_array($filter, ['ok', 'degraded', 'broken', 'fetch_failed'], true)) {
            $where .= " AND last_status = ?"; $args[] = $filter;
        }
        $stmt = $db->prepare("SELECT id, url, expected_types, found_types, missing_types, block_count, last_status, last_checked_at
                              FROM schema_audits WHERE {$where} ORDER BY FIELD(last_status, 'broken','fetch_failed','degraded','ok'), last_checked_at DESC LIMIT 200");
        $stmt->execute($args);
        sch_respond([
            'summary' => sch_site_summary($db, $site_id),
            'items'   => $stmt->fetchAll(),
        ]);
    }

    sch_respond(['error' => 'Unknown action: ' . $action], 400);
} catch (Throwable $e) {
    error_log('[schema-action] ' . $e->getMessage());
    sch_respond(['error' => $e->getMessage()], 500);
}
