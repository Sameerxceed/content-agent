<?php
/**
 * Phase 1 — Competitor Discovery.
 *
 * For the site's top 30 active keywords, query Google CSE → top 10 results,
 * aggregate domains, filter out non-competitors, save the most-frequent ones.
 *
 * POST JSON: { site_id }
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);
if (!$site_id) { http_response_code(400); echo json_encode(['error' => 'site_id required']); exit; }

// Verify ownership and load site
$stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$site_id, $user_id]);
$site = $stmt->fetch();
if (!$site) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }

// Need Google CSE configured
$api_key = config('google_cse_api_key');
$cx = config('google_cse_cx');
if (empty($api_key) || empty($cx)) {
    echo json_encode(['error' => 'Google Custom Search not configured. Go to Settings → API Keys to set it up.']);
    exit;
}

// Get top 30 active keywords (prefer GSC-real ones, then AI-estimated)
$stmt = $db->prepare("SELECT id, keyword FROM keywords WHERE site_id = ? AND status = 'active' ORDER BY (source = 'gsc') DESC, priority DESC, impressions DESC LIMIT 30");
$stmt->execute([$site_id]);
$keywords = $stmt->fetchAll();

if (empty($keywords)) {
    echo json_encode(['error' => 'No active keywords to analyse. Add keywords or run Find Keywords first.']);
    exit;
}

// Domain helpers
$own_domain = preg_replace('#^(https?://)?(www\.)?#i', '', strtolower(trim($site['domain'])));
$own_domain = rtrim($own_domain, '/');

// Sites that aren't real competitors — generic content aggregators, socials, marketplaces
$exclusion_list = [
    'wikipedia.org','reddit.com','quora.com','youtube.com','medium.com',
    'linkedin.com','facebook.com','twitter.com','x.com','instagram.com',
    'pinterest.com','tumblr.com','tiktok.com',
    'amazon.com','amazon.in','amazon.co.uk','ebay.com','etsy.com','alibaba.com',
    'google.com','bing.com','duckduckgo.com','yahoo.com',
    'apple.com','play.google.com','microsoft.com',
    'github.com','stackoverflow.com','news.ycombinator.com',
];

function extract_apex_domain(string $url): ?string {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return null;
    $host = strtolower($host);
    $host = preg_replace('#^www\.#', '', $host);
    return $host ?: null;
}

function is_excluded(string $domain, string $own, array $excl): bool {
    if ($domain === $own) return true;
    // Also catch subdomains of own site
    if (str_ends_with($domain, '.' . $own)) return true;
    foreach ($excl as $bad) {
        if ($domain === $bad || str_ends_with($domain, '.' . $bad)) return true;
    }
    return false;
}

// Run CSE for each keyword
$domain_data = []; // domain => ['keywords' => [...], 'shared' => N]
$cse_calls = 0;
$failed_lookups = 0;

foreach ($keywords as $kw) {
    $query = $kw['keyword'];
    $params = http_build_query([
        'key' => $api_key,
        'cx' => $cx,
        'q' => $query,
        'num' => 10,
    ]);
    $url = 'https://www.googleapis.com/customsearch/v1?' . $params;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'ContentAgent/1.0',
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $cse_calls++;

    if ($status !== 200 || !$body) { $failed_lookups++; continue; }
    $data = json_decode($body, true);
    if (empty($data['items'])) continue;

    foreach ($data['items'] as $idx => $item) {
        $url = $item['link'] ?? '';
        if (!$url) continue;
        $domain = extract_apex_domain($url);
        if (!$domain) continue;
        if (is_excluded($domain, $own_domain, $exclusion_list)) continue;

        $position = $idx + 1;
        if (!isset($domain_data[$domain])) {
            $domain_data[$domain] = ['rankings' => []];
        }
        $domain_data[$domain]['rankings'][] = [
            'keyword_id' => (int)$kw['id'],
            'keyword'    => $kw['keyword'],
            'position'   => $position,
            'url'        => $url,
            'title'      => $item['title'] ?? '',
        ];
    }
}

$total_kw = count($keywords);

// Only keep domains that appeared in 2+ keywords (signal threshold)
$candidates = [];
foreach ($domain_data as $domain => $info) {
    $shared = count($info['rankings']);
    if ($shared < 2) continue;
    $candidates[$domain] = [
        'shared' => $shared,
        'overlap_score' => (int)round(($shared / $total_kw) * 100),
        'rankings' => $info['rankings'],
    ];
}

// Sort by shared count desc and cap at top 15 (so we don't insert 100s)
uasort($candidates, fn($a, $b) => $b['shared'] <=> $a['shared']);
$candidates = array_slice($candidates, 0, 15, true);

// Persist
$insert_comp = $db->prepare('INSERT INTO competitors (site_id, domain, source, status, overlap_score, shared_keywords, last_analysed_at)
    VALUES (?, ?, "auto", "active", ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        overlap_score = VALUES(overlap_score),
        shared_keywords = VALUES(shared_keywords),
        last_analysed_at = NOW(),
        -- if user had ignored it before, keep it ignored (respect their decision)
        status = IF(status = "ignored", "ignored", "active")');

$insert_rank = $db->prepare('INSERT INTO competitor_keyword_rankings (competitor_id, keyword_id, position, url, title, last_seen_at)
    VALUES (?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        position = VALUES(position),
        url = VALUES(url),
        title = VALUES(title),
        last_seen_at = NOW()');

$inserted = 0;
$updated_rankings = 0;

foreach ($candidates as $domain => $info) {
    $insert_comp->execute([$site_id, $domain, $info['overlap_score'], $info['shared']]);

    // Get the competitor id
    $cid_stmt = $db->prepare('SELECT id FROM competitors WHERE site_id = ? AND domain = ?');
    $cid_stmt->execute([$site_id, $domain]);
    $competitor_id = (int)$cid_stmt->fetchColumn();
    if (!$competitor_id) continue;
    $inserted++;

    foreach ($info['rankings'] as $r) {
        $insert_rank->execute([
            $competitor_id,
            $r['keyword_id'],
            $r['position'],
            mb_substr($r['url'], 0, 2048),
            mb_substr($r['title'], 0, 500),
        ]);
        $updated_rankings++;
    }
}

// Log
$db->prepare('INSERT INTO agent_log (site_id, action, details, status, duration_ms) VALUES (?, ?, ?, ?, ?)')->execute([
    $site_id, 'competitors_discover',
    json_encode([
        'keywords_analysed' => $total_kw,
        'cse_calls'         => $cse_calls,
        'failed_lookups'    => $failed_lookups,
        'candidates_found'  => count($candidates),
        'rankings_saved'    => $updated_rankings,
        'by_user'           => $user_id,
    ]),
    'success', 0,
]);

echo json_encode([
    'success'           => true,
    'keywords_analysed' => $total_kw,
    'cse_calls'         => $cse_calls,
    'failed_lookups'    => $failed_lookups,
    'competitors_found' => $inserted,
    'rankings_saved'    => $updated_rankings,
]);
