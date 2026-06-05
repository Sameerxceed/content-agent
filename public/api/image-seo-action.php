<?php
/**
 * Image SEO audit — API endpoint.
 *
 * Synchronous for now (~50ms/img × ~3 imgs/post × ~200 posts = ~30s).
 * Convert to background if customers hit nginx 60s timeout.
 *
 * POST JSON: { action, site_id }
 *   action=run      — run the audit, return summary
 *   action=dismiss  — dismiss a single image_audit row
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/image_auditor.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

function is_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action  = (string)($input['action']  ?? '');
$site_id = (int)   ($input['site_id'] ?? 0);

$site = auth_get_accessible_site($db, $site_id);
if (!$site) is_respond(['error' => 'Site not found'], 404);

try {
    if ($action === 'run') {
        // Bump the PHP execution-time limit. The auditor is bounded by
        // post count × ~50ms/HEAD; typical sites finish in 30s.
        @set_time_limit(180);
        $r = img_audit_site($db, $site_id);
        is_respond(['success' => true] + $r);
    }
    if ($action === 'dismiss') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) is_respond(['error' => 'id required'], 400);
        $db->prepare("UPDATE image_audits SET dismissed_at = NOW() WHERE id = ? AND site_id = ?")
           ->execute([$id, $site_id]);
        is_respond(['success' => true]);
    }
    is_respond(['error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('[image-seo-action] ' . $e->getMessage());
    is_respond(['error' => $e->getMessage()], 500);
}
