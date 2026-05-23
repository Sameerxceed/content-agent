<?php
/**
 * Phase 1 — Competitor Discovery.
 *
 * For the site's top 30 active keywords, query Google CSE → top 10 results,
 * aggregate domains, filter out non-competitors, save the most-frequent ones.
 *
 * POST JSON: { site_id }
 */
// DataForSEO's live/advanced SERP endpoint takes 5-15s per query because it
// actually polls Google live. With 15-30 keywords sequential, the whole run
// is 1-5 minutes — well past PHP's default 30s execution limit. Bump it,
// and don't abandon the run if the user closes their tab mid-flight.
set_time_limit(600);
ignore_user_abort(true);

// Bulletproof JSON output — any stray PHP notice/warning would break
// JSON.parse on the client (we hit this exact bug today: "JSON.parse:
// unexpected character at line 1 column 1"). Buffer all output and emit
// only well-formed JSON via cd_respond().
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/business_profile.php';
require_once __DIR__ . '/../../includes/haiku.php';
require_once __DIR__ . '/../../includes/serp.php';

auth_start();
if (!auth_check()) { http_response_code(401); ob_end_clean(); echo json_encode(['error' => 'Unauthorized']); exit; }

header('Content-Type: application/json');

/** Discard any buffered stray output, then send JSON. */
function cd_respond(array $payload, int $status = 200): void {
    if (ob_get_length()) {
        $stray = ob_get_clean();
        if (trim($stray) !== '') error_log('[competitors-discover] stray output: ' . substr($stray, 0, 500));
    }
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$site_id = (int)($input['site_id'] ?? 0);
if (!$site_id) cd_respond(['error' => 'site_id required'], 400);

// Verify ownership and load site (+ rich business profile for relevance filtering)
$site    = auth_get_accessible_site($db, $site_id);
if (!$site) cd_respond(['error' => 'Site not found'], 404);
$profile = profile_get($db, $site_id);

// SERP queries go through the provider abstraction in includes/serp.php.
// Brave Search is tried first (free 1k/month), DataForSEO is the paid fallback.
// At least one provider must be configured.
if (!serp_active_provider()) {
    cd_respond(['error' => 'No SERP provider configured. Set up Brave Search (free) or DataForSEO in Integrations Hub.']);
}

// Limit to top 5 keywords. DataForSEO's live/advanced SERP endpoint takes
// 5-15s per query (it polls Google live). nginx / PHP-FPM cuts the connection
// at ~60s by default — set_time_limit() doesn't override that, so we have to
// stay well inside the proxy budget. 5 * ~10s = ~50s, fits reliably.
// The first run worked at 12 keywords because DataForSEO had cached SERPs for
// some queries; subsequent runs hit fresh queries and time out.
//
// Proper fix is to convert this to a background job (fire CLI + poll status)
// — bookmarked for next session. For now: small sample is better than
// timeout errors.
$stmt = $db->prepare("SELECT id, keyword FROM keywords WHERE site_id = ? AND status = 'active' ORDER BY (source = 'gsc') DESC, priority DESC, impressions DESC LIMIT 5");
$stmt->execute([$site_id]);
$keywords = $stmt->fetchAll();

if (empty($keywords)) {
    cd_respond(['error' => 'No active keywords to analyse. Add keywords or run Find Keywords first.']);
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
        // Pick the SINGLE most-targeted seed based on profile shape.
        if ($geo !== '' && in_array($scope, ['local', 'regional', 'national'], true)) {
            $seed_queries[] = "{$industry} firms in {$geo}";
        } elseif (in_array($size, ['solo', 'small', 'mid'], true)) {
            $seed_queries[] = "boutique {$industry} firms";
        } else {
            $seed_queries[] = "top {$industry} consultancies";
        }
        // One more general one — peer comparison
        if (in_array($size, ['solo', 'small', 'mid'], true)) {
            $seed_queries[] = "small {$industry} agencies";
        }
    }
    // Cap at 2 seeds so we stay inside the proxy timeout (5 keywords + 2 seeds
    // = 7 SERP calls * ~10s = ~70s, just under nginx default 60-90s budget).
    foreach (array_slice($seed_queries, 0, 2) as $sq) {
        $keywords[] = ['id' => 0, 'keyword' => $sq, '_seed' => true];
    }
}

// Domain helpers
$own_domain = preg_replace('#^(https?://)?(www\.)?#i', '', strtolower(trim($site['domain'])));
$own_domain = rtrim($own_domain, '/');

// Brand-name impostor protection. The first run for Xceed returned domains
// like xceed.com, xceedtek.com, xceed.me — namesakes/squatters whose only
// "overlap" is ranking for typos of the brand name (xcerd, xceee, xteed).
// We compute a brand root from the domain (e.g. "xceed" from "xceedtech.in")
// and reject any candidate domain that contains it. We also strip those
// brand-tainted keywords from the discovery seed set so we don't waste
// SERP calls on queries that only surface impostors.
$brand_root = strtolower(explode('.', $own_domain)[0]);
// Strip common suffixes so "xceedtech" → "xceed"
$brand_root = preg_replace('/(tech|techno|technologies|labs|software|digital|studio|agency|group|inc|co|ltd)$/i', '', $brand_root);
$brand_root = strlen($brand_root) >= 4 ? $brand_root : strtolower(explode('.', $own_domain)[0]);

// Drop keywords whose tokens are dominated by the brand name — these are
// branded searches, not topical queries that find competitors.
$keywords = array_values(array_filter($keywords, function($kw) use ($brand_root) {
    $tokens = preg_split('/[^a-z0-9]+/i', strtolower($kw['keyword']));
    $tokens = array_filter($tokens, fn($t) => strlen($t) >= 3);
    if (empty($tokens)) return true;
    $brand_hits = 0;
    foreach ($tokens as $t) {
        if (similar_text($t, $brand_root) / max(strlen($t), strlen($brand_root)) >= 0.6) {
            $brand_hits++;
        }
    }
    // If half or more of the meaningful tokens look like the brand name, skip.
    return $brand_hits < count($tokens) / 2;
}));

if (empty($keywords)) {
    cd_respond(['error' => 'After filtering brand-name keywords, no usable seed queries remain. Add some topical (non-branded) keywords first, then retry.']);
}

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

function is_excluded(string $domain, string $own, array $excl, string $brand_root = ''): bool {
    if ($domain === $own) return true;
    // Also catch subdomains of own site
    if (str_ends_with($domain, '.' . $own)) return true;
    foreach ($excl as $bad) {
        if ($domain === $bad || str_ends_with($domain, '.' . $bad)) return true;
    }
    // Brand-name impostor filter — drop xceed.com / xceedtek.com / xceed.me
    // class of namesakes when the brand root is e.g. "xceed". We compare
    // against the apex segment of the candidate, not the full host.
    if ($brand_root !== '' && strlen($brand_root) >= 4) {
        $apex = explode('.', $domain)[0];
        if (str_starts_with($apex, $brand_root)) return true;
        // Very close fuzzy match (typos / single-char variants of the brand)
        if (similar_text($apex, $brand_root) / max(strlen($apex), strlen($brand_root)) >= 0.75) return true;
    }
    return false;
}

// Run CSE for each keyword
$domain_data = []; // domain => ['keywords' => [...], 'shared' => N]
$cse_calls = 0;            // kept name for back-compat with downstream logging
$failed_lookups = 0;
$provider_tally = []; // which provider actually served each query

// Track the FIRST error we see so the UI can show a real reason instead of
// just "0 competitors found" — insufficient balance, auth failure, etc.
$first_error_status = null;
$first_error_message = null;

foreach ($keywords as $kw) {
    $query = $kw['keyword'];
    $cse_calls++;

    // serp_search() tries providers in priority order (Brave first, DataForSEO
    // fallback) and returns whichever one succeeded plus a per-provider error log.
    $serp = serp_search($query, 30);
    $items = $serp['results'];
    if (!empty($serp['provider'])) {
        $provider_tally[$serp['provider']] = ($provider_tally[$serp['provider']] ?? 0) + 1;
    }

    if (empty($items)) {
        $failed_lookups++;
        // Record the first error message from any provider so we can surface it
        if ($first_error_message === null && !empty($serp['errors'])) {
            $first_error_status  = 0;
            $first_error_message = implode(' / ', array_map(
                fn($p, $msg) => "{$p}: {$msg}",
                array_keys($serp['errors']),
                array_values($serp['errors'])
            ));
        }
        continue;
    }

    foreach ($items as $item) {
        $url = $item['url'] ?? '';
        if (!$url) continue;
        $domain = extract_apex_domain($url);
        if (!$domain) continue;
        if (is_excluded($domain, $own_domain, $exclusion_list, $brand_root)) continue;

        $position = (int)($item['position'] ?? 0) ?: 99;
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

    usleep(100000); // 100ms throttle — DFSO is fine without but we're polite
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
        . "  - It is a TYPO or VARIANT of the customer's brand name (namesake / brand-squat / domain that only ranks for misspellings of the customer's name, not for the customer's actual topic). Example: if the customer is 'xceedtech.in', drop xceed.com / xceedtek.com / xceed.me — they share keywords only because Google surfaces them for typos of the brand.\n"
        . "  - Its name visually rhymes with the customer's brand name and clearly trades on confusion (1-2 char Levenshtein distance from the customer's domain root)\n"
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

// Build a useful headline message — if every SERP call failed, surface the
// real reason (no provider configured, quota, balance, auth, etc.) instead
// of "0 found".
$headline = null;
$fix_hint = null;
if ($cse_calls > 0 && $failed_lookups === $cse_calls) {
    $msg = $first_error_message ?: 'unknown error';
    $headline = "All {$cse_calls} SERP calls failed: {$msg}";
    if (stripos($msg, 'not configured') !== false) {
        $fix_hint = 'Configure Brave Search (free, 2k queries/month) or DataForSEO in Integrations Hub.';
    } elseif (stripos($msg, 'rate') !== false || stripos($msg, '429') !== false || stripos($msg, 'quota') !== false) {
        $fix_hint = 'Provider rate limit / quota hit. Brave free tier is 2k/month + 1 req/sec — wait, or set up DataForSEO as a paid fallback.';
    } elseif (stripos($msg, 'balance') !== false || stripos($msg, '40200') !== false || stripos($msg, '40201') !== false || stripos($msg, 'insufficient') !== false) {
        $fix_hint = 'DataForSEO balance is empty. Top up at https://app.dataforseo.com/billing — or rely on Brave Search (free) if you have a key configured.';
    } elseif (stripos($msg, 'auth') !== false || stripos($msg, '401') !== false || stripos($msg, '403') !== false) {
        $fix_hint = 'A SERP provider rejected the credentials. Re-enter the key in Integrations Hub.';
    }
}

$provider_summary = '';
foreach ($provider_tally as $p => $n) {
    $provider_summary .= ($provider_summary === '' ? '' : ', ') . "{$p}={$n}";
}

// Log
$db->prepare('INSERT INTO agent_log (site_id, action, details, status, duration_ms) VALUES (?, ?, ?, ?, ?)')->execute([
    $site_id, 'competitors_discover',
    json_encode([
        'keywords_analysed'  => $total_kw,
        'cse_calls'          => $cse_calls,
        'failed_lookups'     => $failed_lookups,
        'candidates_found'   => count($candidates),
        'rankings_saved'     => $updated_rankings,
        'provider_breakdown' => $provider_tally,
        'first_error'        => $first_error_message ? ['status' => $first_error_status, 'message' => $first_error_message] : null,
        'by_user'            => $user_id,
    ]),
    $headline ? 'fail' : 'success', 0,
]);

cd_respond([
    'success'            => true,
    'keywords_analysed'  => $total_kw,
    'cse_calls'          => $cse_calls,
    'failed_lookups'     => $failed_lookups,
    'competitors_found'  => $inserted,
    'rankings_saved'     => $updated_rankings,
    'provider_breakdown' => $provider_tally,
    'provider_summary'   => $provider_summary,
    'error_headline'     => $headline,
    'fix_hint'           => $fix_hint,
]);
