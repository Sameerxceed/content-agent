<?php
/**
 * Public API — SEO data for a page.
 * Called by the ContentAgent JS snippet embedded on customer sites.
 *
 * GET /api/seo-data.php?domain=xceedtech.in&path=/services
 *
 * Returns: canonical, meta, OG tags, schema, redirects — all as JSON.
 * The JS snippet on the site reads this and injects the missing tags.
 *
 * No auth required — public endpoint (CORS enabled).
 */

require_once __DIR__ . '/../../includes/helpers.php';

$db = require __DIR__ . '/../../includes/db.php';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Content-Type: application/json');

$domain = $_GET['domain'] ?? '';
$path = $_GET['path'] ?? '/';

if (empty($domain)) {
    echo json_encode(['error' => 'domain required']);
    exit;
}

$domain = preg_replace('/^www\./', '', strtolower($domain));

// Find site
$stmt = $db->prepare('SELECT id, snippet_mode, snippet_enabled FROM sites WHERE domain = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$domain]);
$site = $stmt->fetch();

if (!$site) {
    echo json_encode(['error' => 'site not found']);
    exit;
}

// KILL SWITCH: if snippet not explicitly enabled, return empty response.
// The snippet on the live site will fetch this, get nothing, and inject nothing.
if (empty($site['snippet_enabled'])) {
    echo json_encode(['disabled' => true]);
    exit;
}

$site_id = $site['id'];
$snippet_mode = $site['snippet_mode'] ?? 'fill_only';

// Check for redirect first
$stmt = $db->prepare('SELECT to_url, type FROM redirects WHERE site_id = ? AND from_path = ?');
$stmt->execute([$site_id, $path]);
$redirect = $stmt->fetch();

if ($redirect) {
    // Update hit counter
    $db->prepare('UPDATE redirects SET hits = hits + 1 WHERE site_id = ? AND from_path = ?')->execute([$site_id, $path]);

    echo json_encode([
        'redirect' => true,
        'to'       => $redirect['to_url'],
        'type'     => (int)$redirect['type'],
    ]);
    exit;
}

// Get page SEO data
$stmt = $db->prepare('SELECT * FROM page_seo WHERE site_id = ? AND url_path = ?');
$stmt->execute([$site_id, $path]);
$seo = $stmt->fetch();

$response = [
    'path' => $path,
    'snippet_mode' => $snippet_mode,
];

if ($seo) {
    if ($seo['canonical'])        $response['canonical'] = $seo['canonical'];
    if ($seo['meta_title'])       $response['meta_title'] = $seo['meta_title'];
    if ($seo['meta_description']) $response['meta_description'] = $seo['meta_description'];
    if ($seo['og_title'])         $response['og_title'] = $seo['og_title'];
    if ($seo['og_description'])   $response['og_description'] = $seo['og_description'];
    if ($seo['og_image'])         $response['og_image'] = $seo['og_image'];
    if ($seo['schema_json'])      $response['schema'] = json_decode($seo['schema_json'], true);
    if ($seo['extra_head'])       $response['extra_head'] = $seo['extra_head'];
}

echo json_encode($response);
