<?php
/**
 * Phase 3 — Content Gaps management.
 *
 * POST JSON:
 *   { action: "update_status", id, status: "open"|"planned"|"published"|"ignored" }
 *   { action: "delete", ids: [...] }
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

if ($action === 'update_status') {
    $id = (int)($input['id'] ?? 0);
    $status = $input['status'] ?? '';
    if (!$id || !in_array($status, ['open','planned','published','ignored'], true)) {
        http_response_code(400); echo json_encode(['error' => 'id and valid status required']); exit;
    }
    $stmt = $db->prepare('UPDATE content_gaps cg JOIN sites s ON cg.site_id = s.id SET cg.status = ? WHERE cg.id = ? AND s.user_id = ?');
    $stmt->execute([$status, $id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete') {
    $ids = array_values(array_filter(array_map('intval', $input['ids'] ?? []), fn($i) => $i > 0));
    if (empty($ids)) { http_response_code(400); echo json_encode(['error' => 'ids required']); exit; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge($ids, [$user_id]);
    $stmt = $db->prepare("DELETE cg FROM content_gaps cg JOIN sites s ON cg.site_id = s.id WHERE cg.id IN ({$placeholders}) AND s.user_id = ?");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
