<?php
/**
 * Sitemap + IndexNow actions.
 *
 * POST JSON:
 *   key            — generate/retrieve the per-site IndexNow key
 *   verify         — check the key file is uploaded at /<key>.txt
 *   push_all       — ping every known URL (initial submission)
 *   push_urls      — ping a specific list (used by autopilot after publish)
 *   sitemap_xml    — return the sitemap.xml body for download
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sitemap_indexnow.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

function in_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json'); http_response_code($status);
    echo json_encode($payload); exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? $_GET['action'] ?? '');
$site_id = (int)($input['site_id'] ?? $_GET['site_id'] ?? 0);
$site = auth_get_accessible_site($db, $site_id);
if (!$site) in_respond(['error' => 'Site not found'], 404);

$host = preg_replace('#^https?://#i', '', (string)$site['domain']);

try {
    if ($action === 'key') {
        $key = indexnow_key_for_site($db, $site);
        in_respond(['success' => true, 'key' => $key, 'key_file_url' => 'https://' . $host . '/' . $key . '.txt']);
    }

    if ($action === 'verify') {
        $key = indexnow_key_for_site($db, $site);
        $v = indexnow_verify_key($host, $key);
        in_respond(['success' => true] + $v);
    }

    if ($action === 'push_all') {
        $key = indexnow_key_for_site($db, $site);
        // Verify first — pointless to push if the key file isn't there
        $v = indexnow_verify_key($host, $key);
        if (!$v['verified']) in_respond(['error' => 'Key file not found at ' . $v['url'] . ' (HTTP ' . $v['http'] . '). Upload the key file before pushing.'], 400);
        $entries = sitemap_collect_urls($db, $site);
        $urls = array_map(fn($e) => $e['url'], $entries);
        $r = indexnow_push($host, $key, $urls);
        in_respond($r + ['success' => $r['success']]);
    }

    if ($action === 'push_urls') {
        $key = indexnow_key_for_site($db, $site);
        $urls = $input['urls'] ?? [];
        if (!is_array($urls) || empty($urls)) in_respond(['error' => 'urls[] required'], 400);
        $r = indexnow_push($host, $key, array_values($urls));
        in_respond($r + ['success' => $r['success']]);
    }

    if ($action === 'sitemap_xml') {
        $files = sitemap_generate($db, $site);
        if (empty($files)) in_respond(['error' => 'No URLs to sitemap. Crawl your site first.'], 400);
        $body = $files['/sitemap.xml'] ?? reset($files);
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="sitemap.xml"');
        echo $body; exit;
    }

    in_respond(['error' => 'Unknown action: ' . $action], 400);
} catch (Throwable $e) {
    error_log('[indexnow-action] ' . $e->getMessage());
    in_respond(['error' => $e->getMessage()], 500);
}
