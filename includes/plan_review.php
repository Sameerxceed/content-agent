<?php
/**
 * Monthly Performance Review — the continuous-learning loop.
 *
 * Runs on the 1st of each month for every active plan. Analyses what was
 * published in the last 30 days, learns what's working, and proposes
 * pipeline changes + horizon extensions. User reviews + approves; nothing
 * is auto-applied (explicit "propose" semantics).
 *
 * Public API:
 *   review_generate(PDO $db, int $plan_id): int  // returns plan_reviews.id
 *   review_apply(PDO $db, int $review_id, array $approved_ids): array
 *   review_expire_stale(PDO $db): int            // sweep old proposed reviews
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/business_profile.php';
require_once __DIR__ . '/haiku.php';
require_once __DIR__ . '/content_plan.php';

const REVIEW_PERIOD_DAYS  = 30;
const REVIEW_EXPIRY_DAYS  = 7;

/**
 * Generate a monthly review document. Inserts a plan_reviews row with
 * status='proposed'. Returns the review_id.
 */
function review_generate(PDO $db, int $plan_id): int
{
    $plan = $db->prepare("SELECT * FROM content_plans WHERE id = ?");
    $plan->execute([$plan_id]);
    $plan = $plan->fetch();
    if (!$plan) throw new RuntimeException("Plan {$plan_id} not found");

    $site_id = (int)$plan['site_id'];
    $profile = profile_get($db, $site_id);
    $period_end = (new DateTime('today'))->format('Y-m-d');
    $period_start = (new DateTime('today'))->modify('-' . REVIEW_PERIOD_DAYS . ' days')->format('Y-m-d');

    // ── Step 1: Pull last 30 days' performance ──
    $summary = _review_compute_summary($db, $plan_id, $period_start, $period_end);

    // ── Step 2: Pull current pipeline + cluster pool for Claude ──
    $pipeline = $db->prepare("SELECT i.id, i.target_publish_date, i.role, i.content_type, i.bucket,
            i.estimated_rank, i.estimated_clicks_at_6mo, i.confidence,
            i.proposed_title, c.name AS cluster_name, k.keyword AS primary_keyword,
            k.opportunity_score, k.search_volume
        FROM content_plan_items i
        JOIN content_plan_clusters c ON i.cluster_id = c.id
        JOIN keywords k ON i.primary_keyword_id = k.id
        WHERE i.plan_id = ? AND i.lock_state = 'pipeline'
        ORDER BY i.target_publish_date ASC");
    $pipeline->execute([$plan_id]);
    $pipeline_rows = $pipeline->fetchAll();

    $pool = $db->prepare("SELECT ck.cluster_id, ck.keyword_id, ck.role, c.name AS cluster_name,
            k.keyword, k.opportunity_score, k.search_volume, k.intent
        FROM content_plan_cluster_keywords ck
        JOIN content_plan_clusters c ON ck.cluster_id = c.id
        JOIN keywords k ON ck.keyword_id = k.id
        WHERE c.plan_id = ? AND ck.is_scheduled = 0 AND k.status = 'active'
        ORDER BY k.opportunity_score DESC
        LIMIT 60");
    $pool->execute([$plan_id]);
    $pool_rows = $pool->fetchAll();

    // ── Step 3: Claude review call ──
    $review_json = _review_call_claude($plan, $profile, $summary, $pipeline_rows, $pool_rows, $period_start, $period_end);
    if (!$review_json) throw new RuntimeException('Claude review call returned no data');

    // ── Step 4: Insert plan_reviews row ──
    $expires_at = (new DateTime('today'))->modify('+' . REVIEW_EXPIRY_DAYS . ' days')->format('Y-m-d H:i:s');
    $db->prepare("INSERT INTO plan_reviews
        (plan_id, site_id, period_start, period_end, summary, learnings, proposed_changes, forecast_update,
         status, created_at, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'proposed', NOW(), ?)")
       ->execute([
           $plan_id, $site_id, $period_start, $period_end,
           json_encode($review_json['summary'] ?? $summary),
           json_encode($review_json['learnings'] ?? []),
           json_encode($review_json['proposed_changes'] ?? []),
           json_encode($review_json['forecast_update'] ?? null),
           $expires_at,
       ]);
    $review_id = (int)$db->lastInsertId();

    // Mark last_review_at on the plan
    $db->prepare("UPDATE content_plans SET last_review_at = NOW(),
        next_review_due_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?")->execute([$plan_id]);

    // Alert for the user
    $changes = $review_json['proposed_changes'] ?? [];
    $n = count($changes['swaps'] ?? []) + count($changes['additions'] ?? [])
       + count($changes['removals'] ?? []) + count($changes['reschedules'] ?? []);
    $db->prepare("INSERT INTO alerts (site_id, type, severity, title, detail, link_url, detected_at)
        VALUES (?, 'plan_review_ready', 'info', ?, ?, ?, NOW())")
       ->execute([
           $site_id,
           "📊 Monthly Plan Review ready — {$n} proposed change" . ($n === 1 ? '' : 's'),
           'AI analysed last month\'s performance and is proposing pipeline updates. Review and approve.',
           '/dashboard/plan-review.php?id=' . $review_id,
       ]);

    return $review_id;
}

/**
 * Apply the user-approved subset of proposed changes.
 *
 * @param array $approved_ids Per-change identifiers. Each change in proposed_changes
 *                            should be addressable by index in its category, e.g.:
 *                            ['swap:0', 'swap:2', 'addition:1', 'removal:0']
 * @return array Counts of applied changes by category.
 */
function review_apply(PDO $db, int $review_id, array $approved_ids): array
{
    $review = $db->prepare("SELECT * FROM plan_reviews WHERE id = ?");
    $review->execute([$review_id]);
    $review = $review->fetch();
    if (!$review) throw new RuntimeException("Review {$review_id} not found");
    if ($review['status'] !== 'proposed') throw new RuntimeException("Review already {$review['status']}");

    $plan_id  = (int)$review['plan_id'];
    $changes  = json_decode($review['proposed_changes'] ?? '{}', true) ?: [];
    $applied  = ['swaps' => 0, 'additions' => 0, 'removals' => 0, 'reschedules' => 0];

    // Index approved IDs for fast lookup
    $approved_set = array_flip($approved_ids);

    $db->beginTransaction();
    try {
        // ── Swaps ──
        foreach (($changes['swaps'] ?? []) as $i => $swap) {
            if (!isset($approved_set["swap:{$i}"])) continue;
            $item_id   = (int)($swap['item_id'] ?? 0);
            $to_kw_id  = (int)($swap['to_keyword_id'] ?? 0);
            if (!$item_id || !$to_kw_id) continue;
            $from_kw = (int)$db->query("SELECT primary_keyword_id FROM content_plan_items WHERE id={$item_id}")->fetchColumn();
            $db->prepare("UPDATE content_plan_items SET primary_keyword_id = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$to_kw_id, $item_id]);
            $db->prepare("INSERT INTO plan_drift_log (plan_id, item_id, change_type, from_keyword_id, to_keyword_id, reason, review_id)
                VALUES (?, ?, 'swap', ?, ?, ?, ?)")->execute([$plan_id, $item_id, $from_kw, $to_kw_id,
                    'monthly review: ' . ($swap['reason'] ?? ''), $review_id]);
            $applied['swaps']++;
        }

        // ── Reschedules ──
        foreach (($changes['reschedules'] ?? []) as $i => $rs) {
            if (!isset($approved_set["reschedule:{$i}"])) continue;
            $item_id = (int)($rs['item_id'] ?? 0);
            $new_date = (string)($rs['to_date'] ?? '');
            if (!$item_id || $new_date === '') continue;
            $db->prepare("UPDATE content_plan_items SET target_publish_date = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$new_date, $item_id]);
            $db->prepare("INSERT INTO plan_drift_log (plan_id, item_id, change_type, reason, review_id)
                VALUES (?, ?, 'reschedule', ?, ?)")->execute([$plan_id, $item_id,
                'monthly review: ' . ($rs['reason'] ?? ''), $review_id]);
            $applied['reschedules']++;
        }

        // ── Additions (extend the pipeline horizon) ──
        foreach (($changes['additions'] ?? []) as $i => $add) {
            if (!isset($approved_set["addition:{$i}"])) continue;
            $cluster_id        = (int)($add['cluster_id'] ?? 0);
            $primary_kw_id     = (int)($add['primary_keyword_id'] ?? 0);
            $target_week       = max(1, (int)($add['target_week'] ?? 12));
            $content_type      = (string)($add['content_type'] ?? 'blog');
            $proposed_title    = (string)($add['proposed_title'] ?? '');
            $proposed_angle    = (string)($add['proposed_angle'] ?? '');
            if (!$cluster_id || !$primary_kw_id) continue;
            // Compute publish date (cadence-aware)
            $cadence = (int)$db->query("SELECT cadence_posts_per_week FROM content_plans WHERE id={$plan_id}")->fetchColumn() ?: 2;
            $start = (new DateTime('today'));
            $pub = (clone $start)->modify("+" . ($target_week - 1) . " weeks +1 days")->format('Y-m-d');
            // Channels: take from cluster's existing items (simplest) or default
            $sample_channels = $db->query("SELECT channels FROM content_plan_items WHERE cluster_id={$cluster_id} LIMIT 1")->fetchColumn();
            $channels = $sample_channels ?: json_encode(['cms', 'schema', 'llms']);
            // Position: append
            $position = (int)$db->query("SELECT COALESCE(MAX(position),0)+1 FROM content_plan_items WHERE plan_id={$plan_id}")->fetchColumn();
            $db->prepare("INSERT INTO content_plan_items
                (plan_id, cluster_id, site_id, position, target_week, target_publish_date, role, content_type, bucket,
                 primary_keyword_id, channels, lock_state, estimated_rank, confidence, proposed_title, proposed_angle)
                VALUES (?, ?, ?, ?, ?, ?, 'supporting', ?, 'new_content', ?, ?, 'pipeline', 12, 50, ?, ?)")
               ->execute([$plan_id, $cluster_id, (int)$review['site_id'], $position, $target_week, $pub,
                   $content_type, $primary_kw_id, $channels,
                   mb_substr($proposed_title, 0, 500), mb_substr($proposed_angle, 0, 4000)]);
            $new_item_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE content_plan_cluster_keywords SET is_scheduled=1, scheduled_item_id=? WHERE cluster_id=? AND keyword_id=?")
               ->execute([$new_item_id, $cluster_id, $primary_kw_id]);
            $db->prepare("INSERT INTO plan_drift_log (plan_id, item_id, change_type, to_keyword_id, reason, review_id)
                VALUES (?, ?, 'add', ?, ?, ?)")->execute([$plan_id, $new_item_id, $primary_kw_id,
                'monthly review: ' . ($add['reason'] ?? ''), $review_id]);
            $applied['additions']++;
        }

        // ── Removals ──
        foreach (($changes['removals'] ?? []) as $i => $rm) {
            if (!isset($approved_set["removal:{$i}"])) continue;
            $item_id = (int)($rm['item_id'] ?? 0);
            if (!$item_id) continue;
            $db->prepare("UPDATE content_plan_cluster_keywords SET is_scheduled=0, scheduled_item_id=NULL WHERE scheduled_item_id=?")
               ->execute([$item_id]);
            $db->prepare("INSERT INTO plan_drift_log (plan_id, item_id, change_type, reason, review_id)
                VALUES (?, ?, 'remove', ?, ?)")->execute([$plan_id, $item_id,
                'monthly review: ' . ($rm['reason'] ?? ''), $review_id]);
            $db->prepare("DELETE FROM content_plan_items WHERE id=?")->execute([$item_id]);
            $applied['removals']++;
        }

        // Update forecast on the plan
        $forecast = json_decode($review['forecast_update'] ?? 'null', true);
        if (is_array($forecast) && isset($forecast['updated_range']) && is_array($forecast['updated_range'])) {
            $lo = (int)($forecast['updated_range'][0] ?? 0);
            $hi = (int)($forecast['updated_range'][1] ?? 0);
            if ($lo > 0 || $hi > 0) {
                $db->prepare("UPDATE content_plans SET estimated_clicks_at_horizon_low=?, estimated_clicks_at_horizon_high=? WHERE id=?")
                   ->execute([$lo, $hi, $plan_id]);
            }
        }

        // Set review status
        $total_applied = array_sum($applied);
        $total_proposed = count($changes['swaps'] ?? []) + count($changes['additions'] ?? [])
                       + count($changes['removals'] ?? []) + count($changes['reschedules'] ?? []);
        $new_status = ($total_applied === $total_proposed) ? 'approved'
                    : (($total_applied > 0) ? 'partially_approved' : 'rejected');
        $db->prepare("UPDATE plan_reviews SET status=?, reviewed_at=NOW(), applied_change_count=? WHERE id=?")
           ->execute([$new_status, $total_applied, $review_id]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $applied;
}

/** Sweep proposed reviews past their expiry. */
function review_expire_stale(PDO $db): int
{
    $stmt = $db->prepare("UPDATE plan_reviews SET status='expired' WHERE status='proposed' AND expires_at < NOW()");
    $stmt->execute();
    return $stmt->rowCount();
}

// ─────────────────────────────────────────────────────────────────
// Internals
// ─────────────────────────────────────────────────────────────────

function _review_compute_summary(PDO $db, int $plan_id, string $period_start, string $period_end): array
{
    // Posts published from plan items in the window
    $posts_stmt = $db->prepare("SELECT i.id AS item_id, i.estimated_clicks_at_6mo AS predicted,
            p.title, p.slug, p.published_at, c.name AS cluster_name
        FROM content_plan_items i
        JOIN posts p ON i.post_id = p.id
        JOIN content_plan_clusters c ON i.cluster_id = c.id
        WHERE i.plan_id = ? AND p.status = 'published'
          AND p.published_at BETWEEN ? AND ?
        ORDER BY p.published_at DESC");
    $posts_stmt->execute([$plan_id, $period_start . ' 00:00:00', $period_end . ' 23:59:59']);
    $posts = $posts_stmt->fetchAll();

    // Aggregate clicks from post_performance (if available)
    $total_clicks = 0;
    $winners = [];
    $underperformers = [];
    foreach ($posts as $p) {
        // Latest performance row for this post
        try {
            $perf = $db->prepare("SELECT clicks FROM post_performance WHERE post_id = (SELECT id FROM posts WHERE slug=? LIMIT 1)
                ORDER BY measured_on DESC LIMIT 1");
            $perf->execute([$p['slug']]);
            $clicks = (int)$perf->fetchColumn();
        } catch (Throwable $e) {
            $clicks = 0;
        }
        $total_clicks += $clicks;
        $predicted = (int)$p['predicted'];
        $row = ['item_id' => (int)$p['item_id'], 'title' => $p['title'], 'cluster' => $p['cluster_name'],
                'actual_clicks' => $clicks, 'predicted' => $predicted];
        if ($predicted > 0 && $clicks > $predicted * 1.5) $winners[] = $row;
        elseif ($predicted > 0 && $clicks < $predicted * 0.3) $underperformers[] = $row;
    }

    return [
        'posts_published'    => count($posts),
        'total_clicks_gained'=> $total_clicks,
        'winners'            => $winners,
        'underperformers'    => $underperformers,
    ];
}

function _review_call_claude(array $plan, ?array $profile, array $summary, array $pipeline, array $pool,
                              string $period_start, string $period_end): ?array
{
    $sys = "You are a content strategist reviewing a 30-day content plan window.\n"
         . "Analyse what was published, identify patterns, and propose pipeline changes.\n\n"
         . "OUTPUT — strict JSON:\n"
         . "{\n"
         . "  \"summary\": { same shape as the input summary, optionally extended },\n"
         . "  \"learnings\": [ \"3-5 short patterns identified from the data\" ],\n"
         . "  \"proposed_changes\": {\n"
         . "    \"swaps\": [ {item_id, from_keyword: '..', to_keyword_id: N, reason: '..'} ],\n"
         . "    \"reschedules\": [ {item_id, from_date: '..', to_date: '..', reason: '..'} ],\n"
         . "    \"additions\": [ {cluster_id, primary_keyword_id, target_week, content_type, proposed_title, proposed_angle, reason} ],\n"
         . "    \"removals\": [ {item_id, reason} ]\n"
         . "  },\n"
         . "  \"forecast_update\": { previous_range: [lo, hi], updated_range: [lo, hi], drivers: [..] }\n"
         . "}\n\n"
         . "GUIDELINES:\n"
         . "  - Propose 0-5 swaps if underperformers reveal calibration issues; otherwise 0\n"
         . "  - Propose additions to fill the pipeline back to 12 weeks ahead (4-8 items)\n"
         . "  - Propose removals only for items in clusters that are clearly underperforming\n"
         . "  - Calibrate forecast based on observed actual vs predicted ratio\n"
         . "  - Be conservative: don't change everything at once. The user must approve every change.\n\n"
         . profile_prompt_block($profile ?: []);

    $context = "Plan: {$plan['id']} (site_id={$plan['site_id']})\n"
        . "Cadence: {$plan['cadence_posts_per_week']} posts/week\n"
        . "Period: {$period_start} → {$period_end}\n\n"
        . "## Performance summary\n" . json_encode($summary, JSON_PRETTY_PRINT) . "\n\n"
        . "## Current pipeline (next " . count($pipeline) . " items)\n" . json_encode(array_slice($pipeline, 0, 20), JSON_PRETTY_PRINT) . "\n\n"
        . "## Cluster pool — unscheduled keywords by cluster\n" . json_encode(array_slice($pool, 0, 60), JSON_PRETTY_PRINT) . "\n\n"
        . "Produce the review document now.";

    $resp = haiku_chat($sys, $context, 16000);
    if (empty($resp['success'])) {
        error_log('[plan review] Claude error: ' . ($resp['error'] ?? 'unknown'));
        return null;
    }
    $data = extract_json_from_text($resp['content'] ?? '');
    return is_array($data) ? $data : null;
}
