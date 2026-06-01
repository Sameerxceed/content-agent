<?php
/**
 * Debug helper for Pass A keyword clustering failure.
 * Loads the site's keywords, runs the Pass A Claude call, dumps raw response.
 *
 * Usage: php agent/debug-plan-pass-a.php --site=1
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/business_profile.php';
require_once __DIR__ . '/../includes/haiku.php';
require_once __DIR__ . '/../includes/content_plan.php';

$db = require __DIR__ . '/../includes/db.php';

$opts    = getopt('', ['site:']);
$site_id = (int)($opts['site'] ?? 1);

echo "=== Debug Pass A for site_id={$site_id} ===\n\n";

$profile = profile_get($db, $site_id);
if (!$profile) { echo "ERROR: no profile for site {$site_id}\n"; exit(1); }
echo "Profile loaded: " . ($profile['name'] ?? '?') . "\n";

// Use a reflection-free path: inline the loader since it's private
$stmt = $db->prepare("SELECT k.id, k.keyword, k.search_volume, k.difficulty, k.intent,
        k.opportunity_score, k.recommended_action, k.keyword_type, k.buyer_question,
        k.gsc_position, k.current_rank, k.impressions, k.clicks, k.source
    FROM keywords k
    WHERE k.site_id = ? AND k.status = 'active' AND k.intent IS NOT NULL
    ORDER BY COALESCE(k.opportunity_score, k.priority) DESC,
             k.search_volume DESC,
             k.keyword ASC
    LIMIT " . (int)PLAN_KEYWORDS_FOR_INPUT);
$stmt->execute([$site_id]);
$rows = $stmt->fetchAll();
$keywords = [];
foreach ($rows as $r) $keywords[(int)$r['id']] = $r;

echo "Loaded " . count($keywords) . " keywords (filter: status=active AND intent IS NOT NULL)\n";

// Also count without intent filter so we know
$c = $db->prepare("SELECT COUNT(*) FROM keywords WHERE site_id = ? AND status = 'active'");
$c->execute([$site_id]);
echo "Active keywords ignoring intent filter: " . (int)$c->fetchColumn() . "\n";
$c2 = $db->prepare("SELECT COUNT(*) FROM keywords WHERE site_id = ? AND status = 'active' AND intent IS NULL");
$c2->execute([$site_id]);
echo "Active keywords with NULL intent: " . (int)$c2->fetchColumn() . "\n\n";

if (count($keywords) < 20) { echo "ERROR: <20 keywords with intent. That's the bug — intent is missing.\n"; exit(1); }

// Build the exact prompt Pass A uses
$lines = [];
foreach ($keywords as $k) {
    $lines[] = sprintf('%d | %s | vol=%s diff=%s intent=%s',
        (int)$k['id'],
        $k['keyword'],
        $k['search_volume']  !== null ? $k['search_volume']  : '—',
        $k['difficulty']     !== null ? $k['difficulty']     : '—',
        $k['intent']         !== null ? $k['intent']         : '—'
    );
}
$catalog = implode("\n", $lines);

$sys = "You are a content strategist for " . ($profile['name'] ?? 'this business') . ".\n"
     . "Given a scored keyword list, organise it into " . PLAN_TARGET_CLUSTERS_MIN . "-" . PLAN_TARGET_CLUSTERS_MAX . " topic clusters.\n\n"
     . "EACH cluster has:\n"
     . "  - name: 2-4 words, the topic\n"
     . "  - angle: one sentence positioning (what this cluster proves about the business)\n"
     . "  - pillar_keyword_id: the broadest / highest-volume / most strategic keyword in the cluster (anchors the pillar page)\n"
     . "  - supporting_keyword_ids: 4-7 keyword IDs in the same topic family (sub-topics, intents, comparisons). These will become 4-7 supporting blog posts under the pillar.\n"
     . "  - reserve_keyword_ids: 0-5 additional keyword IDs that belong topically but aren't priority. These become 'pool' candidates for future monthly reviews to schedule when the horizon extends.\n\n"
     . "STRICT CONSTRAINTS:\n"
     . "  - Every cluster pillar should have search_volume >= 500 (substantive pillars rank better).\n"
     . "  - Supporting keywords MUST share intent + sub-topic with pillar (not just any related keyword).\n"
     . "  - No keyword appears in more than one cluster (each id used at most once across the whole output).\n"
     . "  - Use the keyword IDs from the catalogue exactly — never invent.\n"
     . "  - Balance: 60% commercial/transactional intent, 30% informational, 10% comparison.\n"
     . "  - Match this business's scale + geography. Don't cluster around terms a 15-person consultancy can't credibly rank for.\n\n"
     . "Output ONLY valid JSON: {\"clusters\":[{...}]}\n\n"
     . profile_prompt_block($profile);

$prompt = "Keyword catalogue (id | keyword | vol diff intent):\n\n{$catalog}\n\nProduce the cluster list now.";

echo "Calling Claude (max_tokens=8000)...\n";
$start = microtime(true);
$resp = haiku_chat($sys, $prompt, 8000);
$elapsed = round(microtime(true) - $start, 1);
echo "Elapsed: {$elapsed}s\n\n";

echo "=== RAW haiku_chat() RESPONSE ===\n";
echo "success: " . var_export($resp['success'] ?? null, true) . "\n";
echo "error:   " . ($resp['error'] ?? '(none)') . "\n";
echo "stop_reason: " . ($resp['stop_reason'] ?? '?') . "\n";
echo "content length: " . strlen($resp['content'] ?? '') . "\n";
echo "\n--- CONTENT (first 4000 chars) ---\n";
echo substr($resp['content'] ?? '', 0, 4000);
echo "\n--- END CONTENT ---\n\n";

if (!empty($resp['content'])) {
    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($resp['content']));
    $data  = json_decode($clean, true);
    echo "json_decode result type: " . gettype($data) . "\n";
    if (is_array($data)) {
        echo "keys: " . implode(',', array_keys($data)) . "\n";
        if (isset($data['clusters'])) {
            echo "clusters count: " . count($data['clusters']) . "\n";
        }
    } else {
        echo "json_decode error: " . json_last_error_msg() . "\n";
    }
}
