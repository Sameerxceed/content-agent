<?php
/**
 * Content Plan v1 — the planning + adaptation layer on top of Keywords.
 *
 * Generates a rolling-horizon content plan from the site's keyword pool +
 * business profile. Two Claude passes: cluster keywords into 8-12 topic
 * groups, then sequence items across the rolling pipeline weeks.
 *
 * Public API used by the CLI + dashboards:
 *   plan_generate(PDO $db, int $site_id, array $opts, callable $progress): array
 *   plan_get_active(PDO $db, int $site_id): ?array
 *   plan_get_full(PDO $db, int $plan_id): array      — plan + clusters + items
 *   plan_estimate_clicks(int $volume, int $rank, int $confidence_pct): int
 *   plan_protect_keywords(PDO $db, int $plan_id): void
 *
 * Drift + horizon extension + monthly review live in companion modules:
 *   includes/plan_drift.php      — silent keyword swaps (loop 1)
 *   includes/plan_review.php     — monthly performance loop (loop 2)
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/business_profile.php';
require_once __DIR__ . '/haiku.php';

const PLAN_TARGET_CLUSTERS_MIN = 6;
const PLAN_TARGET_CLUSTERS_MAX = 12;
const PLAN_KEYWORDS_FOR_INPUT  = 120;   // we feed Claude the top N keywords by opportunity_score
const PLAN_MAX_ITEMS_PER_RUN   = 26;    // upper bound for the initial-generation pipeline

// Standard CTR-by-rank curve (Sistrix / Advanced Web Ranking averages 2024-25).
// Used to forecast click yield from estimated_rank.
const PLAN_CTR_BY_RANK = [
    1  => 0.282, 2  => 0.155, 3  => 0.104,
    4  => 0.074, 5  => 0.055, 6  => 0.041,
    7  => 0.033, 8  => 0.026, 9  => 0.022, 10 => 0.020,
];

/**
 * Generate a fresh content plan for this site. Wipes any prior 'active'
 * plan (marks it archived) and inserts a new one.
 *
 * @param array    $opts     ['cadence_posts_per_week' => 2, 'horizon_weeks' => 12, 'forecast_horizon_weeks' => 26, 'goal' => '']
 * @param callable $progress fn(string $step, int $pct, ?array $partial = null)
 * @return array Summary: ['plan_id', 'clusters', 'items', 'forecast_low', 'forecast_high', ...]
 */
function plan_generate(PDO $db, int $site_id, array $opts, callable $progress): array
{
    $cadence  = (int)($opts['cadence_posts_per_week'] ?? 2);
    $horizon  = (int)($opts['rolling_horizon_weeks']  ?? 12);
    $forecast_horizon = (int)($opts['forecast_horizon_weeks'] ?? 26);
    $goal     = trim((string)($opts['goal'] ?? ''));
    $cadence  = max(1, min(7, $cadence));
    $horizon  = max(4, min(26, $horizon));

    // ── Load profile + keywords ──────────────────────────────
    $progress('Loading business profile and keywords...', 5);
    $profile = profile_get($db, $site_id);
    if (!$profile) throw new RuntimeException('Site profile not found. Confirm Business Focus first.');

    $keywords = _plan_load_keywords($db, $site_id);
    if (count($keywords) < 20) {
        throw new RuntimeException('Need at least 20 active keywords to generate a plan. Run Find Keywords first.');
    }

    // ── Archive any existing active plan ─────────────────────
    $db->prepare("UPDATE content_plans SET status = 'archived' WHERE site_id = ? AND status = 'active'")
       ->execute([$site_id]);
    // Drop protection from previously-planned keywords; we'll re-stamp the new plan's keywords below
    $db->prepare("UPDATE keywords SET protected_by_plan = 0 WHERE site_id = ?")
       ->execute([$site_id]);

    // ── Pass A — Claude clusters the keywords ────────────────
    $progress('Clustering keywords into topic groups (AI)...', 20);
    $clusters = _plan_pass_a_cluster_keywords($keywords, $profile, $site_id);
    if (empty($clusters)) {
        throw new RuntimeException('AI could not cluster the keywords. Re-run Find Keywords and try again.');
    }

    // ── Pass B — Claude sequences items across pipeline ──────
    $items_target = $cadence * $horizon;
    $items_target = min(PLAN_MAX_ITEMS_PER_RUN, $items_target);
    $progress("Sequencing {$items_target} items across {$horizon} weeks (AI)...", 55);
    $items = _plan_pass_b_sequence_items($clusters, $keywords, $profile, $horizon, $cadence, $items_target, $site_id);
    if (empty($items)) {
        throw new RuntimeException('AI could not sequence items. Re-run Find Keywords and try again.');
    }

    // ── Compute forecast per item + plan total ───────────────
    $progress('Computing forecast...', 75);
    $forecast_total = 0;
    foreach ($items as &$it) {
        $kw_id  = (int)$it['primary_keyword_id'];
        $kw     = $keywords[$kw_id] ?? null;
        $volume = $kw ? (int)($kw['search_volume'] ?? 0) : 0;
        $rank   = max(1, (int)($it['estimated_rank'] ?? 15));
        $conf   = max(0, min(100, (int)($it['confidence'] ?? 50)));
        $it['estimated_clicks_at_6mo'] = plan_estimate_clicks($volume, $rank, $conf);
        $forecast_total += $it['estimated_clicks_at_6mo'];
    }
    unset($it);
    // Plan-level forecast range: ±30% around the sum
    $forecast_low  = (int)round($forecast_total * 0.7);
    $forecast_high = (int)round($forecast_total * 1.3);

    // ── Write the plan + clusters + items ────────────────────
    $progress('Saving plan, clusters, and pipeline...', 85);
    $start_date = new DateTime('next monday');
    $pipeline_end = (clone $start_date)->modify("+{$horizon} weeks");

    $db->prepare("INSERT INTO content_plans
        (site_id, starts_on, pipeline_extends_to, cadence_posts_per_week, rolling_horizon_weeks,
         forecast_horizon_weeks, goal, status, total_clusters, total_items_scheduled,
         estimated_clicks_at_horizon_low, estimated_clicks_at_horizon_high, next_review_due_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))")
       ->execute([
           $site_id,
           $start_date->format('Y-m-d'),
           $pipeline_end->format('Y-m-d'),
           $cadence, $horizon, $forecast_horizon,
           $goal !== '' ? $goal : null,
           count($clusters), count($items),
           $forecast_low, $forecast_high,
       ]);
    $plan_id = (int)$db->lastInsertId();

    // Insert clusters and remember their new IDs (Claude returned cluster names; map → DB id)
    $cluster_id_by_name = [];
    $insert_cluster = $db->prepare("INSERT INTO content_plan_clusters
        (plan_id, site_id, position, name, angle, pillar_keyword_id)
        VALUES (?, ?, ?, ?, ?, ?)");
    foreach (array_values($clusters) as $i => $c) {
        $insert_cluster->execute([
            $plan_id, $site_id, $i + 1,
            mb_substr((string)$c['name'], 0, 160),
            isset($c['angle']) ? mb_substr((string)$c['angle'], 0, 4000) : null,
            (int)($c['pillar_keyword_id'] ?? 0) ?: null,
        ]);
        $cluster_id_by_name[$c['name']] = (int)$db->lastInsertId();
    }

    // Insert items + cluster_keywords (pool + scheduled)
    $insert_item = $db->prepare("INSERT INTO content_plan_items
        (plan_id, cluster_id, site_id, position, target_week, target_publish_date,
         role, content_type, bucket, primary_keyword_id, secondary_keyword_ids,
         refresh_target_url, proposed_title, proposed_angle, recommended_word_count,
         channels, lock_state, estimated_rank, estimated_clicks_at_6mo, confidence)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pipeline', ?, ?, ?)");
    $insert_pool = $db->prepare("INSERT IGNORE INTO content_plan_cluster_keywords
        (cluster_id, keyword_id, role, is_scheduled, scheduled_item_id) VALUES (?, ?, ?, ?, ?)");

    $position = 1;
    $items_saved = 0;
    foreach ($items as $it) {
        $cluster_name = $it['cluster_name'] ?? '';
        $cluster_id   = $cluster_id_by_name[$cluster_name] ?? null;
        if (!$cluster_id) continue; // safety

        $target_date = _plan_target_date_for_week($start_date, (int)$it['target_week'], $cadence, $position - 1);

        $insert_item->execute([
            $plan_id, $cluster_id, $site_id, $position++,
            (int)$it['target_week'],
            $target_date,
            $it['role'] === 'pillar' ? 'pillar' : 'supporting',
            _plan_clamp_content_type($it['content_type'] ?? 'blog'),
            _plan_clamp_bucket($it['bucket'] ?? 'new_content'),
            (int)$it['primary_keyword_id'],
            !empty($it['secondary_keyword_ids']) ? json_encode($it['secondary_keyword_ids']) : null,
            $it['refresh_target_url'] ?? null,
            isset($it['proposed_title']) ? mb_substr((string)$it['proposed_title'], 0, 500) : null,
            isset($it['proposed_angle']) ? mb_substr((string)$it['proposed_angle'], 0, 4000) : null,
            isset($it['recommended_word_count']) ? (int)$it['recommended_word_count'] : null,
            json_encode($it['channels'] ?? []),
            (int)$it['estimated_rank'],
            (int)$it['estimated_clicks_at_6mo'],
            (int)$it['confidence'],
        ]);
        $item_id = (int)$db->lastInsertId();
        $items_saved++;

        // Add primary keyword to the cluster pool, marked scheduled
        $insert_pool->execute([$cluster_id, (int)$it['primary_keyword_id'],
            $it['role'] === 'pillar' ? 'pillar_candidate' : 'supporting', 1, $item_id]);
        // Add secondaries to the cluster pool as supporting/scheduled
        foreach (($it['secondary_keyword_ids'] ?? []) as $sk) {
            if ((int)$sk > 0) $insert_pool->execute([$cluster_id, (int)$sk, 'supporting', 1, $item_id]);
        }
    }

    // Add unscheduled pool keywords (per-cluster) — these get picked up by
    // future monthly reviews to extend the horizon back to the rolling cap.
    foreach ($clusters as $c) {
        $cluster_id = $cluster_id_by_name[$c['name']] ?? null;
        if (!$cluster_id) continue;
        // All keywords Claude assigned to this cluster (pillar + supporting + reserve)
        $cluster_kw_ids = array_unique(array_merge(
            isset($c['pillar_keyword_id']) ? [(int)$c['pillar_keyword_id']] : [],
            array_map('intval', $c['supporting_keyword_ids'] ?? []),
            array_map('intval', $c['reserve_keyword_ids']    ?? [])
        ));
        foreach ($cluster_kw_ids as $kid) {
            if ($kid > 0) $insert_pool->execute([$cluster_id, $kid, 'reserve', 0, null]);
        }
    }

    // ── Stamp protected_by_plan on every keyword the plan touches ──
    plan_protect_keywords($db, $plan_id);

    $progress('Plan generated.', 100);
    return [
        'plan_id'       => $plan_id,
        'clusters'      => count($clusters),
        'items_saved'   => $items_saved,
        'items_pending' => count($items) - $items_saved,
        'forecast_low'  => $forecast_low,
        'forecast_high' => $forecast_high,
        'horizon_weeks' => $horizon,
        'cadence'       => $cadence,
    ];
}

/** Returns the active plan row for a site, or null. */
function plan_get_active(PDO $db, int $site_id): ?array
{
    $stmt = $db->prepare("SELECT * FROM content_plans WHERE site_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$site_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Full plan view for the dashboard: plan + clusters (with item counts +
 * forecast totals) + items (with primary keyword denormalised).
 */
function plan_get_full(PDO $db, int $plan_id): array
{
    $plan = $db->prepare("SELECT * FROM content_plans WHERE id = ?");
    $plan->execute([$plan_id]);
    $plan_row = $plan->fetch();
    if (!$plan_row) return [];

    $clusters = $db->prepare("SELECT c.*,
        (SELECT COUNT(*) FROM content_plan_items i WHERE i.cluster_id = c.id) AS item_count,
        (SELECT COALESCE(SUM(i.estimated_clicks_at_6mo),0) FROM content_plan_items i WHERE i.cluster_id = c.id) AS cluster_clicks
        FROM content_plan_clusters c WHERE c.plan_id = ? ORDER BY c.position");
    $clusters->execute([$plan_id]);
    $cluster_rows = $clusters->fetchAll();

    $items = $db->prepare("SELECT i.*, k.keyword AS primary_keyword, k.search_volume, k.difficulty, k.intent,
            p.status AS post_status
        FROM content_plan_items i
        JOIN keywords k ON i.primary_keyword_id = k.id
        LEFT JOIN posts p ON p.id = i.post_id
        WHERE i.plan_id = ?
        ORDER BY i.target_publish_date ASC, i.position ASC");
    $items->execute([$plan_id]);
    $item_rows = $items->fetchAll();

    foreach ($item_rows as &$ir) {
        $ir['channels'] = json_decode($ir['channels'] ?? '[]', true) ?: [];
        $ir['secondary_keyword_ids'] = json_decode($ir['secondary_keyword_ids'] ?? '[]', true) ?: [];
    }
    unset($ir);

    return [
        'plan'     => $plan_row,
        'clusters' => $cluster_rows,
        'items'    => $item_rows,
    ];
}

/**
 * Estimated monthly organic clicks at the forecast horizon, given the
 * keyword's volume + the rank we expect to land + our confidence in that.
 */
function plan_estimate_clicks(int $volume, int $estimated_rank, int $confidence_pct): int
{
    if ($volume <= 0) return 0;
    $rank = max(1, min(100, $estimated_rank));
    $confidence_pct = max(0, min(100, $confidence_pct));
    $ctr = PLAN_CTR_BY_RANK[$rank] ?? null;
    if ($ctr === null) {
        // Below #10 → fall off sharply
        if      ($rank <= 20) $ctr = 0.012;
        elseif  ($rank <= 30) $ctr = 0.006;
        else                  $ctr = 0.003;
    }
    return (int)round($volume * $ctr * ($confidence_pct / 100));
}

/** Stamp protected_by_plan=1 on every keyword referenced by this plan. */
function plan_protect_keywords(PDO $db, int $plan_id): void
{
    $stmt = $db->prepare("UPDATE keywords k
        JOIN content_plan_cluster_keywords ck
          ON ck.keyword_id = k.id
        JOIN content_plan_clusters c
          ON c.id = ck.cluster_id AND c.plan_id = ?
        SET k.protected_by_plan = 1");
    $stmt->execute([$plan_id]);
}

// ─────────────────────────────────────────────────────────────
// Internals
// ─────────────────────────────────────────────────────────────

function _plan_load_keywords(PDO $db, int $site_id): array
{
    // Load active, non-ignored keywords. Index by ID for fast lookup later.
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
    $out = [];
    foreach ($rows as $r) $out[(int)$r['id']] = $r;
    return $out;
}

/**
 * Pass A — Claude clusters the keywords into 6-12 topic groups.
 * Returns array of clusters indexed by cluster name.
 */
function _plan_pass_a_cluster_keywords(array $keywords, array $profile, ?int $site_id = null): array
{
    // Build a compact keyword catalogue for Claude (id|keyword|vol|diff|intent)
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

    $resp = haiku_chat($sys, $prompt, 8000, 'plan_cluster_pick', $site_id);
    if (empty($resp['success'])) {
        error_log('[plan pass A] Claude error: ' . ($resp['error'] ?? 'unknown'));
        return [];
    }
    $data = extract_json_from_text($resp['content'] ?? '');
    if (!is_array($data) || empty($data['clusters'])) {
        error_log('[plan pass A] malformed JSON: ' . substr($resp['content'] ?? '', 0, 500));
        return [];
    }

    // Normalise + index by name
    $out = [];
    foreach ($data['clusters'] as $c) {
        $name = trim((string)($c['name'] ?? ''));
        if ($name === '' || isset($out[$name])) continue;
        $pillar_id = (int)($c['pillar_keyword_id'] ?? 0);
        if (!isset($keywords[$pillar_id])) {
            // pillar must be a real keyword; if Claude hallucinated, pick top-volume from supporting
            $supp = array_filter(array_map('intval', $c['supporting_keyword_ids'] ?? []), fn($id) => isset($keywords[$id]));
            usort($supp, fn($a, $b) => ($keywords[$b]['search_volume'] ?? 0) - ($keywords[$a]['search_volume'] ?? 0));
            $pillar_id = $supp[0] ?? 0;
            if (!$pillar_id) continue;
        }
        $out[$name] = [
            'name'                    => $name,
            'angle'                   => (string)($c['angle'] ?? ''),
            'pillar_keyword_id'       => $pillar_id,
            'supporting_keyword_ids'  => array_values(array_filter(
                array_map('intval', $c['supporting_keyword_ids'] ?? []),
                fn($id) => isset($keywords[$id]) && $id !== $pillar_id
            )),
            'reserve_keyword_ids'     => array_values(array_filter(
                array_map('intval', $c['reserve_keyword_ids'] ?? []),
                fn($id) => isset($keywords[$id])
            )),
        ];
    }
    return $out;
}

/**
 * Pass B — Claude sequences items across the pipeline weeks. Returns array
 * of item dictionaries ready to insert into content_plan_items.
 */
function _plan_pass_b_sequence_items(array $clusters, array $keywords, array $profile, int $horizon_weeks, int $cadence, int $items_target, ?int $site_id = null): array
{
    // Build cluster summary for Claude (give it the pillar + supporting names)
    $cluster_blocks = [];
    $i = 0;
    foreach ($clusters as $c) {
        $i++;
        $pillar = $keywords[$c['pillar_keyword_id']] ?? null;
        if (!$pillar) continue;
        $supp_lines = [];
        foreach ($c['supporting_keyword_ids'] as $kid) {
            $k = $keywords[$kid] ?? null;
            if ($k) $supp_lines[] = "    - {$kid}: {$k['keyword']} (vol=" . ($k['search_volume'] ?? '—') . ", diff=" . ($k['difficulty'] ?? '—') . ", intent={$k['intent']})";
        }
        $pillar_id = (int)$c['pillar_keyword_id'];
        $cluster_blocks[] = "Cluster {$i}: \"{$c['name']}\"\n"
            . "  Angle: {$c['angle']}\n"
            . "  Pillar [id={$pillar_id}]: {$pillar['keyword']} (vol=" . ($pillar['search_volume'] ?? '—') . ", diff=" . ($pillar['difficulty'] ?? '—') . ", intent={$pillar['intent']})\n"
            . "  Supporting:\n" . implode("\n", $supp_lines);
    }
    $cluster_text = implode("\n\n", $cluster_blocks);

    $sys = "You are scheduling a {$horizon_weeks}-week content pipeline at {$cadence} posts/week ({$items_target} items total).\n"
         . "Each item targets ONE primary keyword (from the clusters below) and 0-3 secondary keywords sharing intent + sub-topic.\n\n"
         . "OUTPUT — for each item:\n"
         . "  - cluster_name: must match one cluster name from the input (verbatim)\n"
         . "  - primary_keyword_id: a keyword ID from that cluster\n"
         . "  - secondary_keyword_ids: 0-3 keyword IDs from the SAME cluster (or related cluster), no duplicates of primary\n"
         . "  - target_week: 1-{$horizon_weeks}\n"
         . "  - role: 'pillar' (the cluster's anchor post — 3000-5000 words) OR 'supporting' (1500-2500 word post)\n"
         . "  - content_type: pillar | blog | comparison | guide | service_page | glossary\n"
         . "  - bucket: quick_win | new_content | aeo_gap | long_tail\n"
         . "  - proposed_title: 60 chars max, hook + benefit\n"
         . "  - proposed_angle: one sentence — why a reader would click\n"
         . "  - recommended_word_count: 3000-5000 for pillars, 1500-2500 for supporting\n"
         . "  - estimated_rank: where you expect to land at 6 months (1-30)\n"
         . "  - confidence: 0-100\n\n"
         . "SEQUENCING STRATEGY (respect these strictly):\n"
         . "  - Weeks 1-2 ({$cadence}*2 items): Quick Wins ONLY — pick keywords with gsc_position in 11-30 AND impressions > 0. Set bucket='quick_win'. (If no quick wins exist, fill these slots with the highest-confidence low-difficulty pillars.)\n"
         . "  - Weeks 3-" . max(3, (int)floor($horizon_weeks * 0.6)) . ": Pillars + early supporting posts. Start at least 3 cluster pillars before week 6 so internal-link equity starts building. Mix commercial + transactional intent.\n"
         . "  - Weeks " . max(4, (int)floor($horizon_weeks * 0.6) + 1) . "-{$horizon_weeks}: Remaining pillars, comparisons, supporting posts. AEO Gaps (informational queries) for the final third.\n\n"
         . "Each cluster should have ITS PILLAR scheduled before the cluster's last supporting post.\n"
         . "Distribute items across clusters — don't front-load one cluster.\n\n"
         . "Output ONLY valid JSON: {\"items\":[{...}]}\n\n"
         . profile_prompt_block($profile);

    $prompt = "Clusters available:\n\n{$cluster_text}\n\nProduce the {$items_target}-item pipeline now.";

    $resp = haiku_chat($sys, $prompt, 12000, 'plan_sequence_items', $site_id);
    if (empty($resp['success'])) {
        error_log('[plan pass B] Claude error: ' . ($resp['error'] ?? 'unknown'));
        return [];
    }
    $data = extract_json_from_text($resp['content'] ?? '');
    if (!is_array($data) || empty($data['items'])) {
        error_log('[plan pass B] malformed JSON: ' . substr($resp['content'] ?? '', 0, 500));
        return [];
    }

    // Sanitise each item
    $items = [];
    $used_primary = [];
    foreach ($data['items'] as $raw) {
        $cluster_name = trim((string)($raw['cluster_name'] ?? ''));
        if (!isset($clusters[$cluster_name])) continue;
        $primary = (int)($raw['primary_keyword_id'] ?? 0);
        if (!isset($keywords[$primary]) || isset($used_primary[$primary])) continue;
        $used_primary[$primary] = true;

        $secondary = array_values(array_filter(
            array_map('intval', $raw['secondary_keyword_ids'] ?? []),
            fn($id) => isset($keywords[$id]) && $id !== $primary
        ));
        $secondary = array_slice($secondary, 0, 3);

        // Pull the GSC URL for Quick Wins so the autopilot can offer a "refresh"
        $refresh_url = null;
        $bucket = _plan_clamp_bucket($raw['bucket'] ?? 'new_content');
        if ($bucket === 'quick_win') {
            $kw = $keywords[$primary];
            // Approximate: if GSC has it on a URL, pull post_performance.url. We don't
            // wire that here — leave null and let the autopilot fill it later.
            $refresh_url = null;
        }

        $items[] = [
            'cluster_name'          => $cluster_name,
            'primary_keyword_id'    => $primary,
            'secondary_keyword_ids' => $secondary,
            'target_week'           => max(1, min($horizon_weeks, (int)($raw['target_week'] ?? 1))),
            'role'                  => ($raw['role'] ?? 'supporting') === 'pillar' ? 'pillar' : 'supporting',
            'content_type'          => _plan_clamp_content_type($raw['content_type'] ?? 'blog'),
            'bucket'                => $bucket,
            'proposed_title'        => isset($raw['proposed_title']) ? mb_substr((string)$raw['proposed_title'], 0, 200) : null,
            'proposed_angle'        => isset($raw['proposed_angle']) ? mb_substr((string)$raw['proposed_angle'], 0, 1000) : null,
            'recommended_word_count'=> isset($raw['recommended_word_count']) ? (int)$raw['recommended_word_count'] : null,
            'estimated_rank'        => max(1, min(30, (int)($raw['estimated_rank'] ?? 15))),
            'confidence'            => max(0, min(100, (int)($raw['confidence'] ?? 50))),
            'refresh_target_url'    => $refresh_url,
            // Channels are assigned post-hoc by the caller (it knows the site's connected channels)
            'channels'              => [],
        ];
    }

    return $items;
}

/**
 * Compute the publish date for an item given its target_week and the
 * sequencing position within that week (cadence handles intra-week spread).
 * Items are spread Tuesday + Thursday at cadence=2, Mon/Wed/Fri at cadence=3.
 */
function _plan_target_date_for_week(DateTime $start, int $week, int $cadence, int $items_so_far): string
{
    $week_start = (clone $start)->modify('+' . ($week - 1) . ' weeks');
    $intra_week_position = $items_so_far % $cadence;
    // Spread within the week. Default: cadence=2 → Tue+Thu (days 1+3 from Monday).
    $day_offsets = [
        1 => [1],
        2 => [1, 3],
        3 => [0, 2, 4],
        4 => [0, 2, 3, 4],
        5 => [0, 1, 2, 3, 4],
        6 => [0, 1, 2, 3, 4, 5],
        7 => [0, 1, 2, 3, 4, 5, 6],
    ];
    $offsets = $day_offsets[$cadence] ?? [1, 3];
    $day = $offsets[$intra_week_position] ?? $offsets[count($offsets) - 1];
    return (clone $week_start)->modify('+' . $day . ' days')->format('Y-m-d');
}

function _plan_clamp_content_type(string $v): string
{
    $allowed = ['pillar', 'blog', 'comparison', 'guide', 'service_page', 'glossary', 'news'];
    return in_array($v, $allowed, true) ? $v : 'blog';
}

function _plan_clamp_bucket(string $v): string
{
    $allowed = ['quick_win', 'new_content', 'aeo_gap', 'long_tail'];
    return in_array($v, $allowed, true) ? $v : 'new_content';
}
