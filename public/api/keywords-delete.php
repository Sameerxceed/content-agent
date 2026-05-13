<?php
/**
 * Delete keywords — one, many, or all for a site.
 *
 * POST JSON:
 *   { action: "delete", ids: [1, 2, 3] }
 *   { action: "delete_all", site_id: 1 }
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

if ($action === 'delete') {
    $ids = $input['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) { http_response_code(400); echo json_encode(['error' => 'ids required']); exit; }
    $ids = array_map('intval', $ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Only allow deleting keywords on sites this user owns
    $params = array_merge($ids, [$user_id]);
    $stmt = $db->prepare("DELETE k FROM keywords k JOIN sites s ON k.site_id = s.id WHERE k.id IN ({$placeholders}) AND s.user_id = ?");
    $stmt->execute($params);

    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
    exit;
}

if ($action === 'delete_all') {
    $site_id = (int)($input['site_id'] ?? 0);
    if (!$site_id) { http_response_code(400); echo json_encode(['error' => 'site_id required']); exit; }

    $chk = $db->prepare('SELECT id FROM sites WHERE id = ? AND user_id = ?');
    $chk->execute([$site_id, $user_id]);
    if (!$chk->fetch()) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }

    $stmt = $db->prepare('DELETE FROM keywords WHERE site_id = ?');
    $stmt->execute([$site_id]);

    $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
        $site_id, 'keywords_delete_all', json_encode(['deleted' => $stmt->rowCount(), 'by_user' => $user_id]), 'success',
    ]);

    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
