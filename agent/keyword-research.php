<?php
/**
 * Keyword Research Agent
 * Scrapes Google Autocomplete + People Also Ask for keyword ideas.
 *
 * CLI Usage: php agent/keyword-research.php --site=1
 *            php agent/keyword-research.php --site=1 --seed="web design"
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/haiku.php';

$db = require __DIR__ . '/../includes/db.php';

// ── Parse CLI arguments ──────────────────────────────────
$opts = getopt('', ['site:', 'seed:']);
$site_id = $opts['site'] ?? null;
$seed_kw = $opts['seed'] ?? null;

if (!$site_id) {
    echo "Usage: php keyword-research.php --site=1 [--seed=\"topic\"]\n";
    exit(1);
}

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
$stmt->execute([$site_id]);
$site = $stmt->fetch();

if (!$site) {
    echo "Site #{$site_id} not found.\n";
    exit(1);
}

$start_time = microtime(true);
$topics = json_decode($site['topics'] ?? '[]', true) ?: [];

// Use seed keyword or site topics
$seeds = [];
if ($seed_kw) {
    $seeds[] = $seed_kw;
} elseif (!empty($topics)) {
    $seeds = array_slice($topics, 0, 5);
} else {
    // No topics set — use AI to generate seed keywords from site name/domain
    require_once __DIR__ . '/../includes/haiku.php';
    $site_label = $site['name'] . ' (' . $site['domain'] . ')';
    $ai = haiku_chat(
        "Given this business: {$site_label}, suggest 5 short keyword phrases (2-3 words each) that potential customers would search for. Output ONLY a JSON array of strings, nothing else.",
        $site_label,
        256
    );
    if ($ai['success']) {
        $content = preg_replace('/^```(?:json)?\s*/m', '', $ai['content']);
        $content = preg_replace('/\s*```\s*$/m', '', $content);
        $parsed = json_decode(trim($content), true);
        if (is_array($parsed)) {
            $seeds = array_slice($parsed, 0, 5);
        }
    }
    // Fallback if AI also fails
    if (empty($seeds)) {
        $seeds[] = str_replace(['.com', '.co.uk', '.in', '.net', '.org', '-', '_'], ' ', $site['domain']);
        $seeds[] = $site['name'];
    }
}

echo "Keyword Research for: {$site['domain']}\n";
echo "Seed keywords: " . implode(', ', $seeds) . "\n";
echo str_repeat('=', 60) . "\n";

$all_keywords = [];

// ── Step 1: Google Autocomplete ─────────────────────────
echo "\n[1/4] Google Autocomplete...\n";

foreach ($seeds as $seed) {
    $suggestions = google_autocomplete($seed);
    echo "  '{$seed}' → " . count($suggestions) . " suggestions\n";

    foreach ($suggestions as $kw) {
        $all_keywords[$kw] = ['source' => 'autocomplete', 'seed' => $seed];
    }

    // Also try with common modifiers
    $modifiers = ['best', 'how to', 'what is', 'why', 'top', 'cheap', 'near me'];
    foreach (array_slice($modifiers, 0, 3) as $mod) {
        $modified = google_autocomplete("$mod $seed");
        foreach ($modified as $kw) {
            $all_keywords[$kw] = ['source' => 'autocomplete', 'seed' => "$mod $seed"];
        }
    }

    usleep(500000); // 500ms delay between requests
}

echo "  Total from autocomplete: " . count($all_keywords) . "\n";

// ── Step 2: People Also Ask ─────────────────────────────
echo "\n[2/4] People Also Ask...\n";

foreach (array_slice($seeds, 0, 3) as $seed) {
    $questions = people_also_ask($seed);
    echo "  '{$seed}' → " . count($questions) . " questions\n";

    foreach ($questions as $q) {
        $all_keywords[$q] = ['source' => 'paa', 'seed' => $seed];
    }

    usleep(500000);
}

echo "  Total keywords so far: " . count($all_keywords) . "\n";

// ── Step 3: AI Clustering ───────────────────────────────
echo "\n[3/4] Clustering keywords with AI...\n";

$keyword_list = array_keys($all_keywords);
$clusters = cluster_keywords($keyword_list);

if ($clusters) {
    echo "  Created " . count($clusters) . " clusters\n";
    foreach ($clusters as $name => $kws) {
        echo "    {$name}: " . count($kws) . " keywords\n";
    }
}

// ── Step 4: Estimate difficulty & save ──────────────────
echo "\n[4/4] Estimating difficulty & saving...\n";

$insert_stmt = $db->prepare('INSERT INTO keywords (site_id, keyword, cluster, priority, search_volume, difficulty, last_checked)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE cluster = VALUES(cluster), priority = VALUES(priority), last_checked = NOW()');

$saved = 0;
foreach ($all_keywords as $keyword => $info) {
    // Find which cluster this keyword belongs to
    $cluster_name = null;
    if ($clusters) {
        foreach ($clusters as $name => $kws) {
            if (in_array($keyword, $kws)) {
                $cluster_name = $name;
                break;
            }
        }
    }

    // Estimate difficulty (basic: by word count heuristic)
    $word_count = str_word_count($keyword);
    $difficulty = estimate_difficulty($word_count);

    // Priority: shorter = more competitive but valuable, questions = high intent
    $priority = 50;
    if ($info['source'] === 'paa') $priority = 70; // Questions have high content potential
    if ($word_count >= 4) $priority += 10; // Long-tail = easier to rank
    if ($word_count <= 2) $priority -= 10; // Very competitive
    $priority = max(0, min(100, $priority));

    $insert_stmt->execute([
        $site_id,
        mb_substr($keyword, 0, 255),
        $cluster_name,
        $priority,
        null, // No volume data without paid API
        $difficulty,
    ]);

    $saved++;
}

$duration = round((microtime(true) - $start_time) * 1000);

echo "\nDone! Saved {$saved} keywords in {$duration}ms\n";

// Log agent action
$stmt = $db->prepare('INSERT INTO agent_log (site_id, action, details, status, duration_ms) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([
    $site_id,
    'keyword_research',
    json_encode(['total' => $saved, 'clusters' => count($clusters ?? []), 'seeds' => $seeds]),
    'success',
    $duration,
]);

// ── Functions ───────────────────────────────────────────

/**
 * Fetch Google Autocomplete suggestions.
 */
function google_autocomplete(string $query): array
{
    $url = 'https://suggestqueries.google.com/complete/search?client=firefox&q=' . urlencode($query);
    $result = http_get($url, [], 10);

    if ($result['status'] !== 200) return [];

    $data = json_decode($result['body'], true);
    if (!$data || !isset($data[1])) return [];

    return array_filter($data[1], fn($s) => strtolower($s) !== strtolower($query));
}

/**
 * Scrape People Also Ask from Google search results.
 */
function people_also_ask(string $query): array
{
    $url = 'https://www.google.com/search?q=' . urlencode($query) . '&hl=en';
    $result = http_get($url, [
        'Accept: text/html',
        'Accept-Language: en-US,en;q=0.9',
    ], 10);

    if ($result['status'] !== 200) return [];

    $questions = [];

    // Extract "People Also Ask" questions from HTML
    // They typically appear in data-q attributes or specific div structures
    preg_match_all('/data-q="([^"]+)"/', $result['body'], $matches);
    if (!empty($matches[1])) {
        $questions = array_merge($questions, $matches[1]);
    }

    // Also try aria-label patterns
    preg_match_all('/aria-label="([^"]*\?)"/', $result['body'], $matches2);
    if (!empty($matches2[1])) {
        $questions = array_merge($questions, $matches2[1]);
    }

    // Related searches
    preg_match_all('/<div[^>]*class="[^"]*related[^"]*"[^>]*>.*?<a[^>]*>([^<]+)<\/a>/is', $result['body'], $matches3);
    if (!empty($matches3[1])) {
        $questions = array_merge($questions, $matches3[1]);
    }

    return array_unique(array_map('trim', $questions));
}

/**
 * Use Haiku to cluster keywords into topic groups.
 */
function cluster_keywords(array $keywords): ?array
{
    if (count($keywords) < 5) return null;

    // Limit to 100 keywords to keep API costs low
    $kw_sample = array_slice($keywords, 0, 100);
    $kw_list = implode("\n", $kw_sample);

    $system = "You are an SEO strategist. Group keywords into topic clusters for blog content planning.
Output ONLY valid JSON: an object where keys are cluster names (2-4 words) and values are arrays of keywords from the input list.
Each keyword should appear in exactly one cluster. Aim for 5-10 clusters.";

    $prompt = "Group these keywords into topic clusters:\n\n{$kw_list}";

    $result = haiku_chat($system, $prompt, 2048);

    if (!$result['success']) return null;

    $parsed = json_decode($result['content'], true);
    return is_array($parsed) ? $parsed : null;
}

/**
 * Basic difficulty estimation based on keyword characteristics.
 */
function estimate_difficulty(int $word_count): int
{
    if ($word_count <= 1) return 90;
    if ($word_count === 2) return 70;
    if ($word_count === 3) return 50;
    if ($word_count === 4) return 35;
    return 20; // 5+ words = long tail, easier
}
