<?php
/**
 * Phase 2 — SERP Brief.
 *
 * For one keyword, query Google CSE → top 10 results, fetch top 5 pages,
 * have Claude analyse format/intent/length/H2s, save as JSON on the keyword row.
 * The AI writer later reads this brief to model new content on what's ranking.
 *
 * POST JSON: { keyword_id, force_refresh? }
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/scraper.php';
require_once __DIR__ . '/../../includes/haiku.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$keyword_id = (int)($input['keyword_id'] ?? 0);
$force = !empty($input['force_refresh']);
if (!$keyword_id) { http_response_code(400); echo json_encode(['error' => 'keyword_id required']); exit; }

// Verify ownership and load keyword + site
$stmt = $db->prepare('SELECT k.*, s.user_id AS owner_id, s.id AS site_id, s.domain AS site_domain, s.name AS site_name
    FROM keywords k JOIN sites s ON k.site_id = s.id WHERE k.id = ?');
$stmt->execute([$keyword_id]);
$kw = $stmt->fetch();
if (!$kw || (int)$kw['owner_id'] !== $user_id) {
    http_response_code(404); echo json_encode(['error' => 'Keyword not found']); exit;
}

// If we have a recent brief (<30 days) and not forcing, return cached
if (!$force && !empty($kw['serp_briefed_at'])) {
    $age_days = (time() - strtotime($kw['serp_briefed_at'])) / 86400;
    if ($age_days < 30 && !empty($kw['serp_brief'])) {
        echo json_encode([
            'success' => true,
            'cached' => true,
            'briefed_at' => $kw['serp_briefed_at'],
            'brief' => json_decode($kw['serp_brief'], true),
        ]);
        exit;
    }
}

// Need CSE
$api_key = config('google_cse_api_key');
$cx = config('google_cse_cx');
if (empty($api_key) || empty($cx)) {
    echo json_encode(['error' => 'Google Custom Search not configured. Go to Settings → API Keys.']);
    exit;
}

// Need Claude
if (empty(config('haiku_api_key'))) {
    echo json_encode(['error' => 'Claude API key not configured.']);
    exit;
}

$keyword = $kw['keyword'];

// ── Step 1: Fetch top 10 from Google CSE ────────────────────────────
$params = http_build_query([
    'key' => $api_key,
    'cx' => $cx,
    'q' => $keyword,
    'num' => 10,
]);
$cse_url = 'https://www.googleapis.com/customsearch/v1?' . $params;
$ch = curl_init($cse_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_USERAGENT => 'ContentAgent/1.0',
]);
$cse_body = curl_exec($ch);
$cse_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($cse_status !== 200 || !$cse_body) {
    echo json_encode(['error' => 'Could not reach Google CSE (HTTP ' . $cse_status . ')']);
    exit;
}
$cse_data = json_decode($cse_body, true);
$items = $cse_data['items'] ?? [];
if (empty($items)) {
    echo json_encode(['error' => 'No SERP results found for "' . $keyword . '"']);
    exit;
}

// SERP features from CSE metadata
$serp_features = [];
$has_paa = false;
$own_domain = preg_replace('#^(https?://)?(www\.)?#i', '', strtolower($kw['site_domain']));
$own_ranked = false;
$own_position = null;

// ── Step 2: Fetch top 5 pages (to keep memory bounded) ─────────────
$top_pages = [];
$skipped = 0;
foreach (array_slice($items, 0, 5) as $idx => $item) {
    $url = $item['link'] ?? '';
    if (!$url) { $skipped++; continue; }

    // Check if this is our own domain
    $host = preg_replace('#^www\.#', '', strtolower(parse_url($url, PHP_URL_HOST) ?? ''));
    if ($host === $own_domain) {
        $own_ranked = true;
        $own_position = $idx + 1;
    }

    $result = scraper_fetch($url, 8);
    $word_count = 0;
    $h2s = [];

    if ($result['status'] === 200 && !empty($result['body'])) {
        $doc = scraper_parse_html($result['body']);
        $text = scraper_get_text($doc);
        $word_count = str_word_count($text);
        $headings = scraper_get_headings($doc);
        foreach ($headings as $h) {
            if ($h['level'] === 2 && !empty(trim($h['text']))) {
                $h2s[] = mb_substr(trim($h['text']), 0, 120);
            }
        }
        $h2s = array_slice($h2s, 0, 6);
    } else {
        $skipped++;
    }

    $top_pages[] = [
        'position' => $idx + 1,
        'url'      => $url,
        'host'     => $host,
        'title'    => $item['title'] ?? '',
        'snippet'  => $item['snippet'] ?? '',
        'word_count' => $word_count,
        'h2s' => $h2s,
    ];

    // Free memory between fetches
    unset($result, $doc);
}

// Also collect URLs from positions 6-10 (just title/snippet, no fetch)
foreach (array_slice($items, 5, 5) as $idx => $item) {
    $top_pages[] = [
        'position' => $idx + 6,
        'url' => $item['link'] ?? '',
        'host' => preg_replace('#^www\.#', '', strtolower(parse_url($item['link'] ?? '', PHP_URL_HOST) ?? '')),
        'title' => $item['title'] ?? '',
        'snippet' => $item['snippet'] ?? '',
        'word_count' => 0,
        'h2s' => [],
    ];
}

// Average word count of top 5
$top5_words = array_filter(array_column(array_slice($top_pages, 0, 5), 'word_count'));
$avg_words = !empty($top5_words) ? (int)round(array_sum($top5_words) / count($top5_words)) : 0;

// ── Step 3: Ask Claude to analyse ──────────────────────────────────
$prompt_pages = [];
foreach (array_slice($top_pages, 0, 8) as $p) {
    $prompt_pages[] = "#{$p['position']} · {$p['host']}\n"
        . "Title: " . mb_substr($p['title'], 0, 150) . "\n"
        . "Snippet: " . mb_substr(strip_tags($p['snippet']), 0, 200) . "\n"
        . ($p['word_count'] ? "Word count: {$p['word_count']}\n" : "")
        . (empty($p['h2s']) ? "" : "H2s: " . implode(' | ', $p['h2s']) . "\n");
}

$system = "You are a content strategist analysing what ranks on Google for a target keyword. "
    . "Read the top results, identify the dominant content pattern, and write a brief for a writer who needs to compete. "
    . "Output ONLY valid JSON with EXACTLY these fields: "
    . "{\"format\", \"intent\", \"avg_word_count\", \"common_themes\" (array of strings), "
    . "\"recommended_outline\" (array of H2-level section titles, 4-7 items), "
    . "\"winning_pattern\" (1 sentence), "
    . "\"competitive_difficulty\" (\"easy\" | \"medium\" | \"hard\"), "
    . "\"notes\" (1-2 sentences of strategic advice for the writer)}. "
    . "Format options: \"how-to guide\", \"listicle\", \"comparison\", \"product page\", \"definition / glossary\", "
    . "\"deep dive article\", \"news / opinion\", \"video page\", \"local / location page\". "
    . "Intent options: informational | commercial | transactional | navigational.";

$user_msg = "Target keyword: \"{$keyword}\"\n"
    . "Business doing the writing: {$kw['site_name']} ({$kw['site_domain']})\n"
    . "Average word count of top 5: " . ($avg_words ?: 'unknown') . "\n\n"
    . "Top 8 results:\n\n" . implode("\n---\n", $prompt_pages);

$ai = haiku_chat($system, $user_msg, 1024);
$brief = null;
if ($ai['success'] && !empty($ai['content'])) {
    $content = preg_replace('/^```(?:json)?\s*/m', '', $ai['content']);
    $content = preg_replace('/\s*```\s*$/m', '', $content);
    if (preg_match('/\{.*\}/s', $content, $m)) {
        $brief = json_decode($m[0], true);
    }
}

if (!$brief || !is_array($brief)) {
    echo json_encode(['error' => 'Claude returned an unparseable brief. Try again.']);
    exit;
}

// Augment with hard data we computed locally
$brief['avg_word_count'] = $brief['avg_word_count'] ?? $avg_words;
$brief['top_results'] = array_map(fn($p) => [
    'position' => $p['position'],
    'host' => $p['host'],
    'title' => $p['title'],
    'url' => $p['url'],
    'word_count' => $p['word_count'],
], array_slice($top_pages, 0, 10));
$brief['own_position'] = $own_position;
$brief['own_ranked'] = $own_ranked;

// ── Step 4: Save ────────────────────────────────────────────────────
$stmt = $db->prepare('UPDATE keywords SET serp_brief = ?, serp_briefed_at = NOW() WHERE id = ?');
$stmt->execute([json_encode($brief), $keyword_id]);

$db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
    $kw['site_id'], 'serp_brief_generated',
    json_encode(['keyword_id' => $keyword_id, 'keyword' => $keyword, 'avg_words' => $avg_words, 'by_user' => $user_id]),
    'success',
]);

echo json_encode([
    'success' => true,
    'cached' => false,
    'briefed_at' => date('Y-m-d H:i:s'),
    'brief' => $brief,
]);
