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
require_once __DIR__ . '/../../includes/business_profile.php';
require_once __DIR__ . '/../../includes/haiku.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);
if (!$site_id) { http_response_code(400); echo json_encode(['error' => 'site_id required']); exit; }

// Verify ownership and load site (+ rich business profile for relevance filtering)
$site    = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); echo json_encode(['error' => 'Site not found']); exit; }
$profile = profile_get($db, $site_id);

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

// Profile-aware seed queries — augment the keyword list with industry+geography
// terms so we find peer-scale competitors instead of just whoever ranks for the
// generic head terms. Without these, Xceed's "what is IT consulting" queries
// surface mega-corps (Infosys/TCS/Wipro). With them, we also search e.g.
// "AI consulting firms India" → finds boutique peers.
if ($profile) {
    $seed_queries = [];
    $industry = trim($profile['industry_sub'] ?? $profile['industry_category'] ?? '');
    $geo      = trim($profile['hq_country'] ?? '');
    $scope    = $profile['market_scope'] ?? '';
    $size     = $profile['size_tier'] ?? '';

    if ($industry !== '') {
        if ($geo !== '' && in_array($scope, ['local', 'regional', 'national'], true)) {
            $seed_queries[] = "{$industry} firms in {$geo}";
            $seed_queries[] = "best {$industry} companies {$geo}";
        }
        if (in_array($size, ['solo', 'small', 'mid'], true)) {
            $seed_queries[] = "small {$industry} agencies";
            $seed_queries[] = "boutique {$industry} firms";
        }
        $seed_queries[] = "top {$industry} consultancies";
    }
    foreach ($seed_queries as $sq) {
        $keywords[] = ['id' => 0, 'keyword' => $sq, '_seed' => true];
    }
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

// Track the FIRST error we see so the UI can show a real reason instead of
// just "0 competitors found" — quota exhausted, API disabled, bad cx, etc.
$first_error_status = null;
$first_error_message = null;

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

    if ($status !== 200 || !$body) {
        $failed_lookups++;
        if ($first_error_status === null) {
            $first_error_status = $status;
            $err = json_decode($body ?: '{}', true);
            $first_error_message = $err['error']['message'] ?? ('HTTP ' . $status);
        }
        continue;
    }
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

// Sort by shared count desc and cap at top 30 (we'll filter further with Claude
// using business profile so the cap is wider — better recall before the AI filter
// drops obvious scale mismatches).
uasort($candidates, fn($a, $b) => $b['shared'] <=> $a['shared']);
$candidates = array_slice($candidates, 0, 30, true);

// Profile-aware relevance filter — ask Claude to drop domains that are obvious
// scale or market mismatches (e.g. Infosys / TCS / Wipro for a 15-person boutique).
// Only run when a profile is available and there are enough candidates to warrant
// the call. Cost: ~$0.002 per discovery run.
$claude_drops = [];
$claude_reasons = [];
if ($profile && count($candidates) >= 5) {
    $profile_block = profile_prompt_block($profile);
    $domain_list = array_keys($candidates);
    $system = "You filter SERP-discovered candidate competitor domains for relevance. "
        . "Output ONLY valid JSON: {\"drop\":[\"domain1\",\"domain2\",...],\"reasons\":{\"domain1\":\"why\",...}}. "
        . "Drop a domain when ANY of these are true:\n"
        . "  - Its company is clearly >5x larger than the business below (e.g. Infosys, TCS, Wipro for a 15-person firm)\n"
        . "  - It serves a totally different geographic market than the business (e.g. US-only for an India-only local business)\n"
        . "  - It is a job board, directory, marketplace, news site, government site, or aggregator (not a direct competitor)\n"
        . "  - It is the customer's own subsidiary / parent / sibling brand\n"
        . "Keep a domain when you're unsure — better to surface than to silently drop. Don't drop more than half the list.";

    $prompt = $profile_block . "\n\n## Candidate competitor domains (from SERP overlap with our keywords):\n"
        . implode("\n", array_map(fn($d) => "  - {$d}", $domain_list));

    $resp = haiku_chat($system, $prompt, 600);
    if (!empty($resp['success'])) {
        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($resp['content']));
        $data = json_decode($clean, true);
        if (is_array($data['drop'] ?? null)) {
            $claude_drops = array_map('strtolower', $data['drop']);
            $claude_reasons = is_array($data['reasons'] ?? null) ? $data['reasons'] : [];
            // Safety: cap drops at 50% of the list so a hallucination can't nuke everything.
            $max_drop = (int)floor(count($candidates) / 2);
            if (count($claude_drops) > $max_drop) {
                $claude_drops = array_slice($claude_drops, 0, $max_drop);
            }
            foreach ($claude_drops as $bad_domain) {
                unset($candidates[$bad_domain]);
            }
        }
    }
}

// Final cap after AI filter — keep top 15 for storage.
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

// Build a useful headline message — if every CSE call failed, surface the real
// reason (quota / API disabled / bad key) instead of silently saying "0 found".
$headline = null;
$fix_hint = null;
if ($cse_calls > 0 && $failed_lookups === $cse_calls) {
    $msg = $first_error_message ?: ('HTTP ' . $first_error_status);
    $headline = "All {$cse_calls} Google Custom Search calls failed: {$msg}";
    if (stripos($msg, 'not have the access') !== false || stripos($msg, 'PERMISSION_DENIED') !== false) {
        $fix_hint = 'Enable the Custom Search JSON API for your Google Cloud project: https://console.cloud.google.com/apis/library/customsearch.googleapis.com';
    } elseif ($first_error_status === 429 || stripos($msg, 'quota') !== false) {
        $fix_hint = 'Daily quota (100 free/day) hit. Either wait until tomorrow or enable billing on the CSE in Google Cloud Console.';
    } elseif (stripos($msg, 'API key not valid') !== false || $first_error_status === 400) {
        $fix_hint = 'API key or CX is invalid. Check the values in Integrations Hub → Google CSE.';
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
        'first_error'       => $first_error_status ? ['status' => $first_error_status, 'message' => $first_error_message] : null,
        'by_user'           => $user_id,
    ]),
    $headline ? 'fail' : 'success', 0,
]);

echo json_encode([
    'success'           => true,
    'keywords_analysed' => $total_kw,
    'cse_calls'         => $cse_calls,
    'failed_lookups'    => $failed_lookups,
    'competitors_found' => $inserted,
    'rankings_saved'    => $updated_rankings,
    'error_headline'    => $headline,
    'fix_hint'          => $fix_hint,
]);
