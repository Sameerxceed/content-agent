<?php
/**
 * Import every keyword a competitor's domain ranks for in Google's top 100
 * (via DataForSEO) and surface them as suggested target keywords for the site.
 *
 * POST JSON: { site_id, competitor_id, max?: 500 }
 *
 * Keywords get inserted with:
 *   - source='manual' (so they pass existing ENUM constraints)
 *   - status='active'
 *   - cluster='from competitor: {domain}' (to show provenance)
 *   - search_volume + current_rank (the competitor's rank, NOT ours)
 *
 * Idempotent: ON DUPLICATE KEY skip — won't overwrite a keyword you already track.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/dataforseo.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';

$input         = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id       = (int)($input['site_id'] ?? 0);
$competitor_id = (int)($input['competitor_id'] ?? 0);
$max           = (int)($input['max'] ?? 500);
if ($max <= 0 || $max > 1000) $max = 500;

if (!auth_can_access_site($db, $site_id)) {
    http_response_code(404); echo json_encode(['error' => 'Site not found']); exit;
}
if (empty(config('dataforseo_login'))) {
    http_response_code(400); echo json_encode(['error' => 'DataForSEO not configured.']); exit;
}

try {
    $stmt = $db->prepare('SELECT id, domain, name FROM competitors WHERE id = ? AND site_id = ?');
    $stmt->execute([$competitor_id, $site_id]);
    $competitor = $stmt->fetch();
    if (!$competitor) { http_response_code(404); echo json_encode(['error' => 'Competitor not found']); exit; }

    $rows = dataforseo_keywords_for_site($competitor['domain'], $max);
    if (empty($rows)) {
        echo json_encode(['success' => true, 'imported' => 0, 'skipped' => 0, 'message' => 'DataForSEO returned no ranked keywords for ' . $competitor['domain']]);
        exit;
    }

    $insert = $db->prepare("
        INSERT INTO keywords (site_id, keyword, search_volume, current_rank, cluster, source, status, priority, created_at)
        VALUES (?, ?, ?, NULL, ?, 'manual', 'active', 50, NOW())
        ON DUPLICATE KEY UPDATE id = id
    ");

    $cluster_name = 'from-competitor: ' . $competitor['domain'];

    $imported = 0; $skipped = 0;
    foreach ($rows as $r) {
        $kw = trim($r['keyword']);
        if ($kw === '' || mb_strlen($kw) > 255) continue;
        $insert->execute([
            $site_id,
            $kw,
            $r['search_volume'],
            $cluster_name,
        ]);
        if ($insert->rowCount() > 0) $imported++; else $skipped++;
    }

    // Refresh competitor's shared_keywords count
    $db->prepare('UPDATE competitors SET shared_keywords = ? WHERE id = ?')
       ->execute([count($rows), $competitor_id]);

    echo json_encode([
        'success'   => true,
        'competitor'=> $competitor['domain'],
        'returned'  => count($rows),
        'imported'  => $imported,
        'skipped'   => $skipped,
    ]);
} catch (Throwable $e) {
    error_log('[competitor-keywords-import] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
