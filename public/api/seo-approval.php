<?php
/**
 * SEO approval API — approve, reject, or edit-and-approve pending page_seo proposals.
 *
 * POST JSON:
 *   { action: "approve", id: 123 }
 *   { action: "reject",  id: 123 }
 *   { action: "edit_approve", id: 123, meta_title, meta_description, og_title, og_description, og_image }
 *   { action: "approve_all", site_id: 1 }      — approve every pending row for a site
 *   { action: "reject_all",  site_id: 1 }
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

// Single-row actions
if (in_array($action, ['approve', 'reject', 'edit_approve'], true)) {
    $id = (int)($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

    // Verify ownership via join
    $stmt = $db->prepare('SELECT ps.* FROM page_seo ps JOIN sites s ON ps.site_id = s.id WHERE ps.id = ? AND s.user_id = ?');
    $stmt->execute([$id, $user_id]);
    $seo = $stmt->fetch();
    if (!$seo) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

    if ($action === 'edit_approve') {
        $stmt = $db->prepare('UPDATE page_seo SET meta_title = ?, meta_description = ?, og_title = ?, og_description = ?, og_image = ?, status = "approved", reviewed_at = NOW(), reviewed_by = ? WHERE id = ?');
        $stmt->execute([
            trim($input['meta_title'] ?? '') ?: null,
            trim($input['meta_description'] ?? '') ?: null,
            trim($input['og_title'] ?? '') ?: null,
            trim($input['og_description'] ?? '') ?: null,
            trim($input['og_image'] ?? '') ?: null,
            $user_id,
            $id,
        ]);
    } else {
        $new_status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $db->prepare('UPDATE page_seo SET status = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?');
        $stmt->execute([$new_status, $user_id, $id]);
    }

    $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
        $seo['site_id'], 'seo_' . $action, json_encode(['page_seo_id' => $id, 'url_path' => $seo['url_path'], 'by_user' => $user_id]), 'success',
    ]);

    echo json_encode(['success' => true]);
    exit;
}

// Bulk actions
if (in_array($action, ['approve_all', 'reject_all'], true)) {
    $site_id = (int)($input['site_id'] ?? 0);
    if (!$site_id) { http_response_code(400); echo json_encode(['error' => 'site_id required']); exit; }

    if (!auth_can_access_site($db, $site_id)) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }

    $new_status = $action === 'approve_all' ? 'approved' : 'rejected';
    $stmt = $db->prepare('UPDATE page_seo SET status = ?, reviewed_at = NOW(), reviewed_by = ? WHERE site_id = ? AND status = "pending"');
    $stmt->execute([$new_status, $user_id, $site_id]);
    $affected = $stmt->rowCount();

    $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
        $site_id, 'seo_' . $action, json_encode(['affected' => $affected, 'by_user' => $user_id]), 'success',
    ]);

    echo json_encode(['success' => true, 'affected' => $affected]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
