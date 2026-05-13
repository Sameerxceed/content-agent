<?php
/**
 * Keywords management API — add, ignore, restore, delete (single or bulk).
 *
 * POST JSON:
 *   { action: "add",      site_id, keywords: ["xceed imagination", ...] }
 *   { action: "ignore",   ids: [...], reason?: "off-brand" }
 *   { action: "restore",  ids: [...] }      // un-ignore
 *   { action: "delete",   ids: [...] }
 *   { action: "delete_all", site_id }       // dangerous — preserves manual + gsc rows by default
 *   { action: "delete_all", site_id, include_manual: true, include_gsc: true }
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

function verify_site(PDO $db, int $site_id, int $user_id): bool {
    $stmt = $db->prepare('SELECT id FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([$site_id, $user_id]);
    return (bool)$stmt->fetch();
}

function ids_with_ownership_filter(array $ids): array {
    return array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
}

if ($action === 'add') {
    $site_id = (int)($input['site_id'] ?? 0);
    $keywords = $input['keywords'] ?? [];
    if (!$site_id || !verify_site($db, $site_id, $user_id)) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }
    if (!is_array($keywords) || empty($keywords)) { http_response_code(400); echo json_encode(['error' => 'keywords required']); exit; }

    $added = 0;
    $skipped = 0;
    $stmt = $db->prepare('INSERT INTO keywords (site_id, keyword, source, status, priority, created_at)
        VALUES (?, ?, "manual", "active", 50, NOW())
        ON DUPLICATE KEY UPDATE status = "active", source = IF(source = "manual", "manual", source)');

    foreach ($keywords as $kw) {
        $kw = trim((string)$kw);
        if ($kw === '') continue;
        $kw = mb_substr($kw, 0, 255);
        $stmt->execute([$site_id, $kw]);
        if ($stmt->rowCount() > 0) $added++;
        else $skipped++;
    }

    $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
        $site_id, 'keywords_added_manual', json_encode(['added' => $added, 'skipped' => $skipped, 'by_user' => $user_id]), 'success',
    ]);

    echo json_encode(['success' => true, 'added' => $added, 'skipped' => $skipped]);
    exit;
}

if ($action === 'ignore' || $action === 'restore') {
    $ids = ids_with_ownership_filter($input['ids'] ?? []);
    if (empty($ids)) { http_response_code(400); echo json_encode(['error' => 'ids required']); exit; }

    $new_status = $action === 'ignore' ? 'ignored' : 'active';
    $reason = $action === 'ignore' ? trim((string)($input['reason'] ?? '')) : null;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$new_status, $reason], $ids, [$user_id]);

    $stmt = $db->prepare("UPDATE keywords k JOIN sites s ON k.site_id = s.id
        SET k.status = ?, k.ignored_reason = ?
        WHERE k.id IN ({$placeholders}) AND s.user_id = ?");
    $stmt->execute($params);

    echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
    exit;
}

if ($action === 'delete') {
    $ids = ids_with_ownership_filter($input['ids'] ?? []);
    if (empty($ids)) { http_response_code(400); echo json_encode(['error' => 'ids required']); exit; }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge($ids, [$user_id]);
    $stmt = $db->prepare("DELETE k FROM keywords k JOIN sites s ON k.site_id = s.id WHERE k.id IN ({$placeholders}) AND s.user_id = ?");
    $stmt->execute($params);

    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
    exit;
}

if ($action === 'delete_all') {
    $site_id = (int)($input['site_id'] ?? 0);
    if (!$site_id || !verify_site($db, $site_id, $user_id)) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }

    $include_manual = !empty($input['include_manual']);
    $include_gsc = !empty($input['include_gsc']);

    $where = ['site_id = ?'];
    $params = [$site_id];
    if (!$include_manual) { $where[] = "source <> 'manual'"; }
    if (!$include_gsc)    { $where[] = "(source <> 'gsc' OR gsc_synced_at IS NULL)"; }

    $sql = 'DELETE FROM keywords WHERE ' . implode(' AND ', $where);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
        $site_id, 'keywords_delete_all', json_encode(['deleted' => $stmt->rowCount(), 'include_manual' => $include_manual, 'include_gsc' => $include_gsc, 'by_user' => $user_id]), 'success',
    ]);

    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
