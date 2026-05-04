<?php
/**
 * API — Sites.
 * GET  /api/sites.php         — list user's sites
 * GET  /api/sites.php?id=1    — single site with stats
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();

if (!auth_check() && !auth_api_verify()) {
    json_response(['error' => 'Unauthorized'], 401);
}

$db = require __DIR__ . '/../../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$site_id = $_GET['id'] ?? null;

if ($site_id) {
    $where = 's.id = ?';
    $params = [(int)$site_id];

    if (auth_check()) {
        $where .= ' AND s.user_id = ?';
        $params[] = auth_user_id();
    }

    $stmt = $db->prepare("SELECT s.* FROM sites s WHERE {$where}");
    $stmt->execute($params);
    $site = $stmt->fetch();

    if (!$site) json_response(['error' => 'Site not found'], 404);

    // Decode JSON fields
    $site['brand_colors'] = json_decode($site['brand_colors'] ?? '[]', true);
    $site['brand_fonts'] = json_decode($site['brand_fonts'] ?? '[]', true);
    $site['topics'] = json_decode($site['topics'] ?? '[]', true);
    $site['keywords'] = json_decode($site['keywords'] ?? '[]', true);
    $site['rss_feeds'] = json_decode($site['rss_feeds'] ?? '[]', true);

    // Stats
    $stmt = $db->prepare('SELECT status, COUNT(*) as cnt FROM posts WHERE site_id = ? GROUP BY status');
    $stmt->execute([$site['id']]);
    $site['post_counts'] = [];
    foreach ($stmt->fetchAll() as $r) $site['post_counts'][$r['status']] = (int)$r['cnt'];

    $stmt = $db->prepare('SELECT score, total_issues, run_at FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 1');
    $stmt->execute([$site['id']]);
    $site['latest_audit'] = $stmt->fetch() ?: null;

    $stmt = $db->prepare('SELECT COUNT(*) FROM keywords WHERE site_id = ?');
    $stmt->execute([$site['id']]);
    $site['keyword_count'] = (int)$stmt->fetchColumn();

    json_response(['site' => $site]);
}

// List all sites
$params = [];
$where = '1=1';

if (auth_check()) {
    $where = 's.user_id = ?';
    $params[] = auth_user_id();
}

$stmt = $db->prepare("
    SELECT s.id, s.name, s.domain, s.platform, s.agent_mode, s.is_active, s.scanned_at, s.created_at,
           (SELECT COUNT(*) FROM posts p WHERE p.site_id = s.id) as post_count,
           (SELECT a.score FROM seo_audits a WHERE a.site_id = s.id ORDER BY a.run_at DESC LIMIT 1) as seo_score
    FROM sites s
    WHERE {$where}
    ORDER BY s.created_at DESC
");
$stmt->execute($params);

json_response(['sites' => $stmt->fetchAll()]);
