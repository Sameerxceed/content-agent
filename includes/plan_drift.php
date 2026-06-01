<?php
/**
 * Plan Drift — silent keyword adaptation loop.
 *
 * Triggered at the end of Find Keywords if an active plan exists. Adapts
 * pipeline items to the refreshed keyword pool:
 *   - Locked items (committed/drafted/published OR within next 2 weeks): no change.
 *   - Pipeline items with a primary keyword that no longer exists → swap with
 *     best-match same-cluster keyword from the cluster pool.
 *   - Pipeline items where opportunity dropped sharply AND a much better
 *     same-cluster keyword sits unscheduled → swap.
 *   - Top 10 new opportunities not in any cluster → assign to the most-
 *     related cluster's pool (reserve role; monthly review schedules them).
 *
 * Logs every change to plan_drift_log. Alerts only fire for high-impact
 * (>5 swaps OR any quick_win swap). Otherwise silent — matches the user's
 * "auto-merge" preference.
 *
 * Public API:
 *   plan_apply_drift(PDO $db, int $plan_id): array  // {swaps, additions, removals, alerts_fired}
 */

require_once __DIR__ . '/helpers.php';

const PLAN_DRIFT_LOCK_HORIZON_WEEKS = 2;     // items publishing within N weeks are locked
const PLAN_DRIFT_SCORE_DROP_THRESHOLD = 20;  // swap pipeline item if score drops by this much AND a better one exists
const PLAN_DRIFT_HIGH_IMPACT_THRESHOLD = 5;  // alert user if >this many swaps in one run

function plan_apply_drift(PDO $db, int $plan_id): array
{
    $swaps = 0; $additions = 0; $removals = 0; $reschedules = 0;
    $quick_win_swap = false;

    $plan = $db->prepare("SELECT * FROM content_plans WHERE id = ?");
    $plan->execute([$plan_id]);
    $plan = $plan->fetch();
    if (!$plan) return ['skipped' => 'plan not found'];

    $site_id = (int)$plan['site_id'];
    $lock_cutoff = (new DateTime('today'))->modify('+' . PLAN_DRIFT_LOCK_HORIZON_WEEKS . ' weeks')->format('Y-m-d');

    // ── Pull pipeline items (eligible for re-evaluation) ──
    $items = $db->prepare("SELECT i.*
        FROM content_plan_items i
        WHERE i.plan_id = ? AND i.lock_state = 'pipeline' AND i.target_publish_date > ?");
    $items->execute([$plan_id, $lock_cutoff]);
    $pipeline_items = $items->fetchAll();

    // Per cluster: gather active keyword pool (unscheduled, by opportunity_score desc)
    // We'll need this for swaps.
    $pool_by_cluster = [];
    $pool_stmt = $db->prepare("SELECT ck.cluster_id, ck.keyword_id, k.opportunity_score, k.search_volume, k.intent
        FROM content_plan_cluster_keywords ck
        JOIN keywords k ON ck.keyword_id = k.id
        JOIN content_plan_clusters c ON ck.cluster_id = c.id
        WHERE c.plan_id = ? AND ck.is_scheduled = 0 AND k.status = 'active'
        ORDER BY ck.cluster_id, k.opportunity_score DESC, k.search_volume DESC");
    $pool_stmt->execute([$plan_id]);
    foreach ($pool_stmt->fetchAll() as $row) {
        $pool_by_cluster[(int)$row['cluster_id']][] = $row;
    }

    // ── For each pipeline item, check primary keyword still exists + score ──
    foreach ($pipeline_items as $it) {
        $kw_check = $db->prepare("SELECT id, opportunity_score, status FROM keywords WHERE id = ?");
        $kw_check->execute([(int)$it['primary_keyword_id']]);
        $kw = $kw_check->fetch();

        $needs_swap = false;
        $swap_reason = '';

        if (!$kw || $kw['status'] === 'ignored') {
            // Primary keyword gone — must swap
            $needs_swap = true;
            $swap_reason = 'primary keyword no longer active';
        } else {
            // Check for sharp drop AND a much better replacement available in cluster pool
            $best_in_pool = $pool_by_cluster[(int)$it['cluster_id']][0] ?? null;
            if ($best_in_pool) {
                $current_score = (int)($kw['opportunity_score'] ?? 0);
                $best_score    = (int)($best_in_pool['opportunity_score'] ?? 0);
                if ($best_score - $current_score >= PLAN_DRIFT_SCORE_DROP_THRESHOLD) {
                    $needs_swap = true;
                    $swap_reason = "better opportunity available: was {$current_score}, now {$best_score}";
                }
            }
        }

        if ($needs_swap) {
            $pool = $pool_by_cluster[(int)$it['cluster_id']] ?? [];
            $replacement = array_shift($pool);
            if (!$replacement) {
                // No replacement in cluster pool — remove the item entirely
                _drift_remove_item($db, $plan_id, (int)$it['id'], 'no replacement available in cluster pool · ' . $swap_reason);
                $removals++;
                continue;
            }
            _drift_swap_item_keyword($db, $plan_id, (int)$it['id'], (int)$it['primary_keyword_id'],
                (int)$replacement['keyword_id'], $swap_reason);
            $swaps++;
            if ($it['bucket'] === 'quick_win') $quick_win_swap = true;
            $pool_by_cluster[(int)$it['cluster_id']] = $pool; // keep pool in sync
        }
    }

    // ── Look at top new keywords NOT in any cluster ──
    $top_new = $db->prepare("SELECT k.id, k.keyword, k.intent, k.opportunity_score, k.search_volume
        FROM keywords k
        WHERE k.site_id = ? AND k.status = 'active' AND k.opportunity_score IS NOT NULL
          AND k.id NOT IN (
              SELECT keyword_id FROM content_plan_cluster_keywords ck
              JOIN content_plan_clusters c ON ck.cluster_id = c.id
              WHERE c.plan_id = ?
          )
        ORDER BY k.opportunity_score DESC, k.search_volume DESC
        LIMIT 10");
    $top_new->execute([$site_id, $plan_id]);
    $candidates = $top_new->fetchAll();

    if (!empty($candidates)) {
        // Need each cluster's intent profile to assign — pull pillar keyword's intent as proxy
        $clusters = $db->prepare("SELECT c.id, c.name, k.intent FROM content_plan_clusters c
            LEFT JOIN keywords k ON c.pillar_keyword_id = k.id WHERE c.plan_id = ?");
        $clusters->execute([$plan_id]);
        $cluster_intent_by_id = [];
        foreach ($clusters->fetchAll() as $cr) $cluster_intent_by_id[(int)$cr['id']] = (string)($cr['intent'] ?? '');

        $insert_pool = $db->prepare("INSERT IGNORE INTO content_plan_cluster_keywords
            (cluster_id, keyword_id, role, is_scheduled) VALUES (?, ?, 'reserve', 0)");

        foreach ($candidates as $cand) {
            $best_cluster = null;
            foreach ($cluster_intent_by_id as $cid => $cintent) {
                if ($cintent === $cand['intent']) { $best_cluster = $cid; break; }
            }
            // Fallback: first cluster
            if (!$best_cluster) $best_cluster = array_key_first($cluster_intent_by_id);
            if (!$best_cluster) break;
            $insert_pool->execute([$best_cluster, (int)$cand['id']]);
            $additions++;
            $db->prepare("INSERT INTO plan_drift_log (plan_id, change_type, to_keyword_id, reason)
                VALUES (?, 'add', ?, ?)")->execute([$plan_id, (int)$cand['id'], 'added to cluster pool from new top opportunity']);
        }
    }

    // Update last_drift_applied_at
    $db->prepare("UPDATE content_plans SET last_drift_applied_at = NOW() WHERE id = ?")->execute([$plan_id]);

    // High-impact alert
    $alerts_fired = 0;
    if ($swaps >= PLAN_DRIFT_HIGH_IMPACT_THRESHOLD || $quick_win_swap) {
        $db->prepare("INSERT INTO alerts (site_id, type, severity, title, detail, link_url, detected_at)
            VALUES (?, 'plan_drift', ?, ?, ?, ?, NOW())")
           ->execute([
               $site_id,
               $quick_win_swap ? 'warning' : 'info',
               "📋 Plan adapted: {$swaps} keyword swap" . ($swaps === 1 ? '' : 's'),
               'Find Keywords brought in fresh opportunities — pipeline items have been silently updated to use the higher-scoring keywords. Review on the Plan page.',
               '/dashboard/plan.php?site=' . $site_id,
           ]);
        $alerts_fired++;
    }

    return [
        'swaps'        => $swaps,
        'reschedules'  => $reschedules,
        'additions'    => $additions,
        'removals'     => $removals,
        'alerts_fired' => $alerts_fired,
    ];
}

// ─────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────

function _drift_swap_item_keyword(PDO $db, int $plan_id, int $item_id, int $from_kw_id, int $to_kw_id, string $reason): void
{
    $db->beginTransaction();
    try {
        // Update the plan item
        $db->prepare("UPDATE content_plan_items SET primary_keyword_id = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$to_kw_id, $item_id]);

        // Update the cluster_keywords pool: unschedule the old, schedule the new
        $db->prepare("UPDATE content_plan_cluster_keywords SET is_scheduled = 0, scheduled_item_id = NULL
            WHERE keyword_id = ? AND scheduled_item_id = ?")->execute([$from_kw_id, $item_id]);
        $db->prepare("INSERT INTO content_plan_cluster_keywords (cluster_id, keyword_id, role, is_scheduled, scheduled_item_id)
            VALUES ((SELECT cluster_id FROM content_plan_items WHERE id = ?), ?, 'supporting', 1, ?)
            ON DUPLICATE KEY UPDATE is_scheduled = 1, scheduled_item_id = ?")->execute([$item_id, $to_kw_id, $item_id, $item_id]);

        // Log
        $db->prepare("INSERT INTO plan_drift_log (plan_id, item_id, change_type, from_keyword_id, to_keyword_id, reason)
            VALUES (?, ?, 'swap', ?, ?, ?)")->execute([$plan_id, $item_id, $from_kw_id, $to_kw_id, $reason]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function _drift_remove_item(PDO $db, int $plan_id, int $item_id, string $reason): void
{
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE content_plan_cluster_keywords SET is_scheduled = 0, scheduled_item_id = NULL
            WHERE scheduled_item_id = ?")->execute([$item_id]);
        $db->prepare("INSERT INTO plan_drift_log (plan_id, item_id, change_type, reason)
            VALUES (?, ?, 'remove', ?)")->execute([$plan_id, $item_id, $reason]);
        $db->prepare("DELETE FROM content_plan_items WHERE id = ?")->execute([$item_id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
