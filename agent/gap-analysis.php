<?php
/**
 * Gap Analysis Agent — CLI background job.
 *
 * For a site:
 *   1. For each active competitor, fetch sitemap.xml (or fall back to homepage crawl)
 *   2. Limit to most-recent 30 URLs per competitor (memory cap)
 *   3. Fetch each URL one at a time; extract title + first 500 chars + word count
 *   4. Batch 10 pages → Claude → extract { topic, keywords } per page
 *   5. Save competitor_pages rows
 *   6. After all competitors done, build content_gaps:
 *      - distinct topics across competitor_pages
 *      - filter out topics already covered by site's keywords or post titles
 *      - count how many competitors cover each remaining topic
 *      - upsert content_gaps rows
 *
 * Updates gap_runs row with progress (used by UI polling).
 *
 * Usage: php agent/gap-analysis.php --site=1 --run=42
 *        php agent/gap-analysis.php --site=1                (creates a new run row)
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/haiku.php';

$db = require __DIR__ . '/../includes/db.php';

$opts = getopt('', ['site:', 'run:']);
$site_id = (int)($opts['site'] ?? 0);
$run_id  = (int)($opts['run'] ?? 0);
if (!$site_id) { echo "--site required\n"; exit(1); }

// Load site
$stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
$stmt->execute([$site_id]);
$site = $stmt->fetch();
if (!$site) { echo "Site #{$site_id} not found\n"; exit(1); }

// Ensure / create run row
if (!$run_id) {
    $db->prepare('INSERT INTO gap_runs (site_id, status, current_step) VALUES (?, "running", "Starting analysis")')->execute([$site_id]);
    $run_id = (int)$db->lastInsertId();
} else {
    $db->prepare('UPDATE gap_runs SET status = "running", current_step = "Starting analysis" WHERE id = ?')->execute([$run_id]);
}

function step(PDO $db, int $run_id, string $step, int $progress = -1) {
    if ($progress >= 0) {
        $db->prepare('UPDATE gap_runs SET current_step = ?, progress = ? WHERE id = ?')->execute([$step, $progress, $run_id]);
    } else {
        $db->prepare('UPDATE gap_runs SET current_step = ? WHERE id = ?')->execute([$step, $run_id]);
    }
    echo "[step] {$step}\n";
}

function fail(PDO $db, int $run_id, string $error) {
    $db->prepare('UPDATE gap_runs SET status = "failed", error = ?, finished_at = NOW() WHERE id = ?')->execute([$error, $run_id]);
    echo "[FAIL] {$error}\n";
    exit(1);
}

step($db, $run_id, 'Loading competitors', 5);

// Active competitors only
$stmt = $db->prepare('SELECT * FROM competitors WHERE site_id = ? AND status = "active" ORDER BY shared_keywords DESC LIMIT 10');
$stmt->execute([$site_id]);
$competitors = $stmt->fetchAll();

if (empty($competitors)) {
    fail($db, $run_id, 'No active competitors. Run "Discover Competitors" first.');
}

// ── Phase 1: fetch each competitor's sitemap, build URL list ────
$all_urls = []; // competitor_id => [urls]
$total_urls = 0;
$max_per_comp = 30;

foreach ($competitors as $i => $comp) {
    $domain = $comp['domain'];
    $cid = (int)$comp['id'];
    step($db, $run_id, "Finding pages on {$domain} (" . ($i+1) . "/" . count($competitors) . ")", 5 + (int)(($i / count($competitors)) * 15));

    $urls = discover_competitor_urls($domain, $max_per_comp);
    if (!empty($urls)) {
        $all_urls[$cid] = $urls;
        $total_urls += count($urls);
        echo "  {$domain}: " . count($urls) . " URLs\n";
    } else {
        echo "  {$domain}: no URLs found (sitemap missing or blocked)\n";
    }
}

if ($total_urls === 0) {
    fail($db, $run_id, 'Could not find any pages on competitor sites (sitemaps missing or blocked).');
}

step($db, $run_id, "Found {$total_urls} pages across " . count($all_urls) . " competitors", 20);

// ── Phase 2: scrape + Claude-extract topics (batched, memory-safe) ──
$pages_done = 0;
$competitors_scanned = 0;

foreach ($all_urls as $cid => $urls) {
    $batch = []; // accumulate up to 10 pages, then call Claude
    $domain = '';
    foreach ($competitors as $c) { if ((int)$c['id'] === $cid) { $domain = $c['domain']; break; } }

    foreach ($urls as $url) {
        $res = scraper_fetch($url, 8);
        $title = ''; $body_excerpt = ''; $word_count = 0;
        if ($res['status'] === 200 && !empty($res['body'])) {
            $doc = scraper_parse_html($res['body']);
            $title = scraper_get_title($doc);
            $text = scraper_get_text($doc);
            $word_count = str_word_count($text);
            $body_excerpt = mb_substr($text, 0, 500);
            unset($doc, $text);
        }
        unset($res);

        if (empty($title)) {
            // even pages we can't parse get a stub row so we know we tried
            $db->prepare('INSERT IGNORE INTO competitor_pages (competitor_id, url, scraped_at) VALUES (?, ?, NOW())')
                ->execute([$cid, mb_substr($url, 0, 2048)]);
            $pages_done++;
            continue;
        }

        $batch[] = [
            'url'   => $url,
            'title' => $title,
            'body'  => $body_excerpt,
            'words' => $word_count,
        ];

        if (count($batch) >= 10) {
            process_batch($db, $cid, $batch);
            $pages_done += count($batch);
            $batch = [];
            // Update progress (20-90 range during scraping)
            $prog = 20 + (int)(($pages_done / max($total_urls, 1)) * 70);
            $db->prepare('UPDATE gap_runs SET pages_scanned = ?, progress = ?, current_step = ? WHERE id = ?')
                ->execute([$pages_done, $prog, "Analysing {$domain} ({$pages_done}/{$total_urls})", $run_id]);
            // Free memory between batches
            gc_collect_cycles();
        }
    }

    // Flush remaining batch
    if (!empty($batch)) {
        process_batch($db, $cid, $batch);
        $pages_done += count($batch);
        $batch = [];
        gc_collect_cycles();
    }

    $competitors_scanned++;
    $db->prepare('UPDATE gap_runs SET competitors_scanned = ?, pages_scanned = ? WHERE id = ?')
        ->execute([$competitors_scanned, $pages_done, $run_id]);
}

step($db, $run_id, "Building gap list", 92);

// ── Phase 3: build content_gaps ──────────────────────────────────
// Wipe previous gaps for this site so this run produces a fresh list
$db->prepare('DELETE FROM content_gaps WHERE site_id = ? AND status IN ("open", "ignored")')->execute([$site_id]);

// Collect all topics across this site's competitors
$stmt = $db->prepare('SELECT cp.topic, cp.title, cp.competitor_id
    FROM competitor_pages cp
    JOIN competitors c ON cp.competitor_id = c.id
    WHERE c.site_id = ? AND c.status = "active" AND cp.topic IS NOT NULL AND cp.topic <> ""');
$stmt->execute([$site_id]);
$rows = $stmt->fetchAll();

// Aggregate: normalised_topic => { competitor_ids set, sample titles }
$topic_data = [];
foreach ($rows as $r) {
    $t = trim(strtolower($r['topic']));
    if ($t === '') continue;
    if (!isset($topic_data[$t])) {
        $topic_data[$t] = ['display' => $r['topic'], 'competitor_ids' => [], 'titles' => []];
    }
    $topic_data[$t]['competitor_ids'][(int)$r['competitor_id']] = true;
    if (count($topic_data[$t]['titles']) < 3 && !empty($r['title'])) {
        $topic_data[$t]['titles'][] = mb_substr($r['title'], 0, 200);
    }
}

// Site keywords + post titles = "covered"
$covered_terms = [];
$stmt = $db->prepare("SELECT LOWER(keyword) FROM keywords WHERE site_id = ? AND status = 'active'");
$stmt->execute([$site_id]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $kw) $covered_terms[] = (string)$kw;

$stmt = $db->prepare("SELECT LOWER(title) FROM posts WHERE site_id = ? AND status IN ('published', 'draft')");
$stmt->execute([$site_id]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $t) $covered_terms[] = (string)$t;

function is_covered(string $topic, array $covered): bool {
    foreach ($covered as $c) {
        if ($c === '') continue;
        // Treat as covered if user already has a keyword/post title that shares ≥60% of the topic words
        if (str_contains($c, $topic)) return true;
        if (str_contains($topic, $c) && mb_strlen($c) >= 8) return true;
    }
    return false;
}

// GSC impressions index: word → sum of impressions
$gsc_index = [];
$stmt = $db->prepare("SELECT LOWER(keyword) k, impressions FROM keywords WHERE site_id = ? AND impressions IS NOT NULL AND impressions > 0");
$stmt->execute([$site_id]);
foreach ($stmt->fetchAll() as $r) {
    foreach (preg_split('/\s+/', $r['k']) as $w) {
        $w = trim($w);
        if (mb_strlen($w) < 4) continue;
        $gsc_index[$w] = ($gsc_index[$w] ?? 0) + (int)$r['impressions'];
    }
}

$gaps_saved = 0;
$insert = $db->prepare('INSERT INTO content_gaps (site_id, topic, competitor_count, competitor_ids, sample_titles, estimated_demand, status, detected_at)
    VALUES (?, ?, ?, ?, ?, ?, "open", NOW())
    ON DUPLICATE KEY UPDATE
        competitor_count = VALUES(competitor_count),
        competitor_ids   = VALUES(competitor_ids),
        sample_titles    = VALUES(sample_titles),
        estimated_demand = VALUES(estimated_demand),
        updated_at       = NOW()');

// Sort by competitor count desc, only keep topics covered by 2+ competitors
$gap_candidates = [];
foreach ($topic_data as $norm => $info) {
    $cc = count($info['competitor_ids']);
    if ($cc < 2) continue;
    if (is_covered($norm, $covered_terms)) continue;

    $demand = 0;
    foreach (preg_split('/\s+/', $norm) as $w) {
        $w = trim($w);
        if (isset($gsc_index[$w])) $demand += $gsc_index[$w];
    }
    $gap_candidates[] = [
        'topic' => $info['display'],
        'cc' => $cc,
        'cids' => array_keys($info['competitor_ids']),
        'titles' => $info['titles'],
        'demand' => $demand,
    ];
}

// Top 50 gaps
usort($gap_candidates, fn($a, $b) => ($b['cc'] <=> $a['cc']) ?: ($b['demand'] <=> $a['demand']));
$gap_candidates = array_slice($gap_candidates, 0, 50);

foreach ($gap_candidates as $g) {
    $insert->execute([
        $site_id,
        mb_substr($g['topic'], 0, 255),
        $g['cc'],
        json_encode($g['cids']),
        json_encode($g['titles']),
        $g['demand'] ?: null,
    ]);
    $gaps_saved++;
}

// Done
$db->prepare('UPDATE gap_runs SET status = "done", progress = 100, current_step = ?, gaps_found = ?, finished_at = NOW() WHERE id = ?')
    ->execute(["Done — {$gaps_saved} gaps found", $gaps_saved, $run_id]);

$db->prepare('INSERT INTO agent_log (site_id, action, details, status) VALUES (?, ?, ?, ?)')->execute([
    $site_id, 'gap_analysis',
    json_encode(['competitors_scanned' => $competitors_scanned, 'pages_scanned' => $pages_done, 'gaps_found' => $gaps_saved]),
    'success',
]);

echo "Done. Gaps saved: {$gaps_saved}\n";

// ── Helpers ────────────────────────────────────────────────────

/** Fetch a competitor's sitemap (or homepage links as fallback) and return up to $max URLs */
function discover_competitor_urls(string $domain, int $max): array {
    $base = 'https://' . preg_replace('#^https?://#', '', $domain);
    $base = rtrim($base, '/');

    // Try a few common sitemap paths
    $candidates = [
        $base . '/sitemap.xml',
        $base . '/sitemap_index.xml',
        $base . '/sitemap-index.xml',
        $base . '/sitemap1.xml',
    ];

    $urls = [];
    foreach ($candidates as $sm_url) {
        $res = scraper_fetch($sm_url, 8);
        if ($res['status'] !== 200 || empty($res['body'])) continue;
        $urls = parse_sitemap_xml($res['body'], $base, $max);
        if (!empty($urls)) break;
    }

    // Fall back to extracting links from homepage if no sitemap
    if (empty($urls)) {
        $res = scraper_fetch($base . '/', 8);
        if ($res['status'] === 200 && !empty($res['body'])) {
            if (preg_match_all('/<a[^>]+href="([^"]+)"/i', $res['body'], $m)) {
                foreach ($m[1] as $href) {
                    if (str_starts_with($href, '/')) $href = $base . $href;
                    if (!str_starts_with($href, $base)) continue;
                    if (str_contains($href, '#')) $href = explode('#', $href)[0];
                    if (in_array($href, $urls)) continue;
                    // Prefer blog/article/guide style URLs
                    if (preg_match('#/(blog|post|article|guide|resource|insight|news|story|learn)/#i', $href)) {
                        $urls[] = $href;
                        if (count($urls) >= $max) break;
                    }
                }
            }
        }
    }

    return array_slice(array_unique($urls), 0, $max);
}

function parse_sitemap_xml(string $xml, string $base, int $max): array {
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    if (!$doc) return [];
    $out = [];

    // Sitemap index? Recurse into first child sitemap
    if (isset($doc->sitemap)) {
        foreach ($doc->sitemap as $sm) {
            $child_url = trim((string)$sm->loc);
            if (!$child_url) continue;
            $res = scraper_fetch($child_url, 8);
            if ($res['status'] !== 200) continue;
            $more = parse_sitemap_xml($res['body'], $base, $max - count($out));
            $out = array_merge($out, $more);
            if (count($out) >= $max) break;
        }
        return array_slice($out, 0, $max);
    }

    // Regular urlset
    if (isset($doc->url)) {
        foreach ($doc->url as $u) {
            $loc = trim((string)$u->loc);
            if (!$loc) continue;
            if (!str_starts_with($loc, $base)) continue;
            // Skip obvious non-content URLs
            if (preg_match('#/(tag|category|author|page/\d+|wp-content|wp-json|feed|amp)/#i', $loc)) continue;
            $out[] = $loc;
            if (count($out) >= $max) break;
        }
    }
    return $out;
}

/** Send a batch of 10 page summaries to Claude, save extracted topic+keywords per page */
function process_batch(PDO $db, int $competitor_id, array $batch): void {
    if (empty($batch)) return;

    $lines = [];
    foreach ($batch as $i => $p) {
        $lines[] = "Page " . ($i + 1) . ":\n"
            . "URL: " . $p['url'] . "\n"
            . "Title: " . mb_substr($p['title'], 0, 200) . "\n"
            . "Content sample: " . mb_substr($p['body'], 0, 400);
    }

    $system = "You are analysing competitor blog/marketing pages to identify the topic each covers. "
        . "For each page, output a SHORT primary topic (3-5 words, the thing the page is really about) and 2-4 secondary keywords. "
        . "Topic should be the SEARCH QUERY a reader of this page would type, NOT a generic theme. "
        . "Output ONLY a valid JSON array, one object per page in order:\n"
        . "[{\"topic\": \"...\", \"keywords\": [\"...\", \"...\"]}]";

    $user_msg = "Analyse these " . count($batch) . " pages:\n\n" . implode("\n\n", $lines);

    $ai = haiku_chat($system, $user_msg, 1500);
    $parsed = null;
    if ($ai['success']) {
        $content = preg_replace('/^```(?:json)?\s*/m', '', $ai['content']);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        if (preg_match('/\[.*\]/s', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }

    $stmt = $db->prepare('INSERT INTO competitor_pages (competitor_id, url, title, topic, keywords, word_count, scraped_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            topic = VALUES(topic),
            keywords = VALUES(keywords),
            word_count = VALUES(word_count),
            scraped_at = NOW()');

    foreach ($batch as $i => $p) {
        $topic = null; $kws = null;
        if (is_array($parsed) && isset($parsed[$i]) && is_array($parsed[$i])) {
            $topic = trim((string)($parsed[$i]['topic'] ?? '')) ?: null;
            $kws = isset($parsed[$i]['keywords']) && is_array($parsed[$i]['keywords']) ? json_encode(array_slice($parsed[$i]['keywords'], 0, 5)) : null;
        }
        $stmt->execute([
            $competitor_id,
            mb_substr($p['url'], 0, 2048),
            mb_substr($p['title'], 0, 500),
            $topic ? mb_substr($topic, 0, 255) : null,
            $kws,
            $p['words'] ?: null,
        ]);
    }
}
