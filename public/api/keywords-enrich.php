<?php
/**
 * Enrich a site's keywords with real DataForSEO data:
 *   search_volume, difficulty, CPC.
 *
 * POST JSON: { site_id, only_missing?: bool }
 *   only_missing = true (default): only enrich keywords with NULL search_volume
 *   only_missing = false         : refresh ALL active keywords
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/dataforseo.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';

$input        = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id      = (int)($input['site_id'] ?? 0);
$only_missing = !isset($input['only_missing']) || !empty($input['only_missing']);

if (!auth_can_access_site($db, $site_id)) {
    http_response_code(404);
    echo json_encode(['error' => 'Site not found']);
    exit;
}

if (empty(config('dataforseo_login')) || empty(config('dataforseo_password'))) {
    http_response_code(400);
    echo json_encode(['error' => 'DataForSEO not configured. Open Integrations Hub.']);
    exit;
}

try {
    $sql = "SELECT id, keyword FROM keywords WHERE site_id = ? AND status = 'active'";
    if ($only_missing) {
        $sql .= ' AND (search_volume IS NULL OR difficulty IS NULL)';
    }
    $sql .= ' ORDER BY priority DESC LIMIT 700';
    $stmt = $db->prepare($sql);
    $stmt->execute([$site_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo json_encode(['success' => true, 'enriched' => 0, 'message' => 'No keywords needing enrichment.']);
        exit;
    }

    $kw_to_id = [];
    $keywords = [];
    foreach ($rows as $r) {
        $kw = strtolower(trim($r['keyword']));
        if ($kw === '') continue;
        $keywords[]      = $kw;
        $kw_to_id[$kw]   = (int)$r['id'];
    }

    $data = dataforseo_keyword_overview(array_unique($keywords));

    $updated = 0; $hit_data = 0;
    $upd = $db->prepare('UPDATE keywords SET search_volume = ?, difficulty = ? WHERE id = ?');
    foreach ($data as $kw => $info) {
        if (!isset($kw_to_id[$kw])) continue;
        $vol  = $info['search_volume'];
        $diff = $info['keyword_difficulty'];
        if ($vol === null && $diff === null) continue; // DFSO had nothing
        $upd->execute([$vol, $diff, $kw_to_id[$kw]]);
        $updated++;
        if ($vol !== null && $vol > 0) $hit_data++;
    }

    echo json_encode([
        'success'   => true,
        'requested' => count($keywords),
        'returned'  => count($data),
        'enriched'  => $updated,
        'with_volume' => $hit_data,
    ]);
} catch (Throwable $e) {
    error_log('[keywords-enrich] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
