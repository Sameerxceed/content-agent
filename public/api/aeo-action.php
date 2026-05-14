<?php
/**
 * AEO actions API.
 *
 * POST JSON: { action, site_id, ... }
 *   - add_query     : add a tracked query
 *   - delete_query  : remove a tracked query
 *   - check_query   : run one query now
 *   - check_all     : run all active queries for the site
 *   - suggest       : ask Claude for query ideas
 *   - bulk_add      : add multiple suggested queries
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/aeo.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$action  = $input['action'] ?? '';
$site_id = (int)($input['site_id'] ?? 0);

// Verify site ownership for every action
$stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$site_id, $user_id]);
$site = $stmt->fetch();
if (!$site) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }

try {
    if ($action === 'add_query') {
        $q = trim($input['query'] ?? '');
        $cat = trim($input['category'] ?? 'industry');
        if (empty($q)) { echo json_encode(['error' => 'Query required']); exit; }
        $stmt = $db->prepare('INSERT INTO aeo_queries (site_id, query_text, category, status, source)
                              VALUES (?, ?, ?, "active", "manual")
                              ON DUPLICATE KEY UPDATE status = "active"');
        $stmt->execute([$site_id, $q, $cat]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        exit;
    }

    if ($action === 'bulk_add') {
        $queries = $input['queries'] ?? [];
        if (!is_array($queries)) { echo json_encode(['error' => 'queries must be array']); exit; }
        $added = 0;
        $stmt = $db->prepare('INSERT INTO aeo_queries (site_id, query_text, category, status, source)
                              VALUES (?, ?, ?, "active", "suggested")
                              ON DUPLICATE KEY UPDATE status = "active"');
        foreach ($queries as $q) {
            $text = trim($q['query'] ?? '');
            if (empty($text)) continue;
            $stmt->execute([$site_id, $text, trim($q['category'] ?? 'industry')]);
            $added++;
        }
        echo json_encode(['success' => true, 'added' => $added]);
        exit;
    }

    if ($action === 'delete_query') {
        $id = (int)($input['query_id'] ?? 0);
        $db->prepare('DELETE FROM aeo_queries WHERE id = ? AND site_id = ?')->execute([$id, $site_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'check_query') {
        $id = (int)($input['query_id'] ?? 0);
        // Verify the query belongs to this site
        $stmt = $db->prepare('SELECT id FROM aeo_queries WHERE id = ? AND site_id = ?');
        $stmt->execute([$id, $site_id]);
        if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Query not found']); exit; }
        $result = aeo_check_query($db, $id);
        echo json_encode($result);
        exit;
    }

    if ($action === 'check_all') {
        $r = aeo_check_all_for_site($db, $site_id);
        echo json_encode(['success' => true] + $r);
        exit;
    }

    if ($action === 'suggest') {
        $suggestions = aeo_suggest_queries($db, $site);
        echo json_encode(['success' => true, 'queries' => $suggestions]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action: ' . $action]);
} catch (Throwable $e) {
    error_log('[aeo-action] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
