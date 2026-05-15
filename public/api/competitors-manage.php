<?php
/**
 * Phase 1 — Competitors management API.
 *
 * POST JSON:
 *   { action: "add",      site_id, domains: ["hotjar.com", ...], name?: "..." }
 *   { action: "ignore",   ids: [...] }
 *   { action: "restore",  ids: [...] }
 *   { action: "delete",   ids: [...] }
 *   { action: "update_notes", id, notes }
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

function ids_clean(array $ids): array {
    return array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
}

function normalise_domain(string $raw): string {
    $d = strtolower(trim($raw));
    $d = preg_replace('#^https?://#i', '', $d);
    $d = preg_replace('#^www\.#i', '', $d);
    $d = explode('/', $d)[0];
    return rtrim($d, '/');
}

if ($action === 'add') {
    $site_id = (int)($input['site_id'] ?? 0);
    if (!$site_id) { http_response_code(400); echo json_encode(['error' => 'site_id required']); exit; }

    // Verify ownership
    if (!auth_can_access_site($db, $site_id)) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }

    $domains = $input['domains'] ?? [];
    if (!is_array($domains) || empty($domains)) { http_response_code(400); echo json_encode(['error' => 'domains required']); exit; }

    $name = trim($input['name'] ?? '') ?: null;

    $added = 0; $existing = 0;
    $stmt = $db->prepare('INSERT INTO competitors (site_id, domain, name, source, status, detected_at)
        VALUES (?, ?, ?, "manual", "active", NOW())
        ON DUPLICATE KEY UPDATE
            status = "active",
            source = IF(source = "manual", "manual", source),
            name = COALESCE(VALUES(name), name)');

    foreach ($domains as $raw) {
        $d = normalise_domain((string)$raw);
        if ($d === '' || $d === '.') continue;
        $stmt->execute([$site_id, $d, $name]);
        if ($stmt->rowCount() > 0) $added++;
        else $existing++;
    }

    echo json_encode(['success' => true, 'added' => $added, 'existing' => $existing]);
    exit;
}

if ($action === 'ignore' || $action === 'restore' || $action === 'delete') {
    $ids = ids_clean($input['ids'] ?? []);
    if (empty($ids)) { http_response_code(400); echo json_encode(['error' => 'ids required']); exit; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($action === 'delete') {
        $params = array_merge($ids, [$user_id]);
        $sql = "DELETE c FROM competitors c JOIN sites s ON c.site_id = s.id WHERE c.id IN ({$placeholders}) AND s.user_id = ?";
    } else {
        $new_status = $action === 'ignore' ? 'ignored' : 'active';
        $params = array_merge([$new_status], $ids, [$user_id]);
        $sql = "UPDATE competitors c JOIN sites s ON c.site_id = s.id SET c.status = ? WHERE c.id IN ({$placeholders}) AND s.user_id = ?";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
    exit;
}

if ($action === 'update_notes') {
    $id = (int)($input['id'] ?? 0);
    $notes = trim((string)($input['notes'] ?? ''));
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

    $stmt = $db->prepare('UPDATE competitors c JOIN sites s ON c.site_id = s.id SET c.notes = ? WHERE c.id = ? AND s.user_id = ?');
    $stmt->execute([$notes ?: null, $id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
