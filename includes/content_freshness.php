<?php
/**
 * Content Freshness scanner — flags published posts that have decayed
 * enough that a refresh is worth doing. "Decay" is a composite signal:
 *
 *   1. Age — older posts are more likely stale; weight ramps after 6 months
 *   2. Stale year mentions — posts that say "in 2024" when we're in 2026
 *      are an instant credibility hit (and we now auto-inject current date
 *      into the autopilot, but legacy posts predate that change)
 *   3. Performance decline — if GSC traffic is dropping vs the previous
 *      90 days, that's the strongest signal (we use post_performance rows
 *      if available)
 *
 * Output: rows in content_freshness with staleness_score 0-100, needs_refresh
 * flag at >=60, and a human-readable refresh_reason. The dashboard turns
 * any needs_refresh row into a one-click "queue refresh in Content Plan" action.
 */

require_once __DIR__ . '/helpers.php';

const CF_REFRESH_THRESHOLD = 60;

function cf_audit_post(PDO $db, array $post, array $site): array
{
    $signals = ['age_pts' => 0, 'year_pts' => 0, 'traffic_pts' => 0];
    $reasons = [];

    // 1. Age
    $age_days = max(0, (int)((time() - strtotime($post['published_at'] ?: $post['created_at'])) / 86400));
    if      ($age_days > 365) { $signals['age_pts'] = 40; $reasons[] = 'over 12 months old (' . round($age_days/30) . ' months)'; }
    elseif  ($age_days > 180) { $signals['age_pts'] = 25; $reasons[] = 'over 6 months old'; }
    elseif  ($age_days > 90)  { $signals['age_pts'] = 10; }

    // 2. Stale year mentions in title or body
    $current_year = (int)date('Y');
    $body = (string)($post['body'] ?? '');
    $title = (string)($post['title'] ?? '');
    $stale_years = [];
    for ($y = $current_year - 5; $y < $current_year; $y++) {
        if (str_contains($title, (string)$y) || preg_match('/\b' . $y . '\b/', $body)) {
            $stale_years[] = $y;
        }
    }
    if (!empty($stale_years)) {
        $signals['year_pts'] = min(30, count($stale_years) * 12);
        $reasons[] = 'mentions ' . implode(', ', $stale_years) . ' (current year is ' . $current_year . ')';
    }

    // 3. Performance decline — uses post_performance if present
    try {
        $stmt = $db->prepare("SELECT clicks, impressions, snapshot_date FROM post_performance
                              WHERE post_id = ? ORDER BY snapshot_date DESC LIMIT 180");
        $stmt->execute([(int)$post['id']]);
        $perf = $stmt->fetchAll();
        if (count($perf) >= 60) {
            $recent_clicks = 0; $older_clicks = 0; $count_recent = 0; $count_older = 0;
            foreach ($perf as $i => $p) {
                if ($i < 30)      { $recent_clicks += (int)$p['clicks']; $count_recent++; }
                elseif ($i < 90)  { $older_clicks  += (int)$p['clicks']; $count_older++;  }
            }
            if ($count_recent > 0 && $count_older > 0) {
                $r_avg = $recent_clicks / $count_recent;
                $o_avg = $older_clicks / $count_older;
                if ($r_avg > 0 || $o_avg > 0) {
                    $decline = ($o_avg - $r_avg) / max(1, $o_avg);
                    if ($decline >= 0.30) {
                        $signals['traffic_pts'] = min(40, (int)($decline * 80));
                        $reasons[] = 'traffic down ' . (int)($decline * 100) . '% vs prior 60 days';
                    }
                }
            }
        }
    } catch (Throwable $e) { /* table optional */ }

    $score = min(100, array_sum($signals));
    $needs = $score >= CF_REFRESH_THRESHOLD ? 1 : 0;

    return [
        'post_id'         => (int)$post['id'],
        'age_days'        => $age_days,
        'staleness_score' => $score,
        'signals'         => $signals,
        'needs_refresh'   => $needs,
        'refresh_reason'  => implode('; ', $reasons) ?: null,
    ];
}

function cf_audit_site(PDO $db, int $site_id, ?int $limit = null): array
{
    $sql = "SELECT id, title, body, published_at, created_at FROM posts
            WHERE site_id = ? AND status IN ('published','approved')";
    if ($limit) $sql .= " LIMIT " . max(1, (int)$limit);
    $stmt = $db->prepare($sql);
    $stmt->execute([$site_id]);
    $posts = $stmt->fetchAll();

    $site = $db->query("SELECT id, domain, blog_path FROM sites WHERE id = {$site_id}")->fetch();

    $upsert = $db->prepare("INSERT INTO content_freshness
        (site_id, post_id, last_audited_at, age_days, staleness_score, signals, needs_refresh, refresh_reason, created_at)
        VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            last_audited_at = NOW(),
            age_days = VALUES(age_days),
            staleness_score = VALUES(staleness_score),
            signals = VALUES(signals),
            needs_refresh = VALUES(needs_refresh),
            refresh_reason = VALUES(refresh_reason)");

    $counters = ['audited' => 0, 'needs_refresh' => 0];
    foreach ($posts as $p) {
        $r = cf_audit_post($db, $p, $site ?: []);
        $upsert->execute([
            $site_id, $r['post_id'], $r['age_days'], $r['staleness_score'],
            json_encode($r['signals']), $r['needs_refresh'], $r['refresh_reason'],
        ]);
        $counters['audited']++;
        $counters['needs_refresh'] += $r['needs_refresh'];
    }
    return $counters;
}

/** Queue a refresh as a new plan item. Returns plan_item_id on success. */
function cf_queue_refresh(PDO $db, int $site_id, int $freshness_id): array
{
    $stmt = $db->prepare("SELECT cf.*, p.title, p.slug FROM content_freshness cf
                          JOIN posts p ON p.id = cf.post_id
                          WHERE cf.id = ? AND cf.site_id = ?");
    $stmt->execute([$freshness_id, $site_id]);
    $row = $stmt->fetch();
    if (!$row) return ['success' => false, 'error' => 'not found'];

    // Active plan
    $plan_id = (int)$db->query("SELECT id FROM content_plans WHERE site_id={$site_id} AND status='active' ORDER BY generated_at DESC LIMIT 1")->fetchColumn();
    if (!$plan_id) return ['success' => false, 'error' => 'no active plan'];

    // Find-or-create cluster
    $cname = 'Refresh Queue';
    $cid = (int)$db->query("SELECT id FROM content_plan_clusters WHERE plan_id={$plan_id} AND name=" . $db->quote($cname) . " LIMIT 1")->fetchColumn();
    if (!$cid) {
        $pos = (int)$db->query("SELECT COALESCE(MAX(position),0)+1 FROM content_plan_clusters WHERE plan_id={$plan_id}")->fetchColumn();
        $db->prepare("INSERT INTO content_plan_clusters (plan_id, site_id, position, name, angle)
                      VALUES (?, ?, ?, ?, ?)")
           ->execute([$plan_id, $site_id, $pos, $cname, 'Posts surfaced as stale by the freshness scanner. Refreshes target the same URL; goal is to reclaim declining traffic + update factual content.']);
        $cid = (int)$db->lastInsertId();
    }

    // Reuse existing keyword id (any one tied to a high-relevance match) — for simplicity, find a keyword that matches the title slug
    $kid = (int)$db->query("SELECT id FROM keywords WHERE site_id={$site_id} ORDER BY priority DESC LIMIT 1")->fetchColumn();
    if (!$kid) return ['success' => false, 'error' => 'no keywords available for plan item primary_keyword_id'];

    $position = (int)$db->query("SELECT COALESCE(MAX(position),0)+1 FROM content_plan_items WHERE plan_id={$plan_id}")->fetchColumn();
    $week = (int)date('W');
    $publish = date('Y-m-d', strtotime('+10 days'));
    $title = 'Refresh: ' . $row['title'];

    $db->prepare("INSERT INTO content_plan_items
        (plan_id, cluster_id, site_id, position, target_week, target_publish_date,
         role, content_type, bucket, primary_keyword_id, refresh_target_url,
         proposed_title, proposed_angle, recommended_word_count, channels, lock_state)
        VALUES (?, ?, ?, ?, ?, ?, 'supporting', 'blog', 'quick_win', ?, ?, ?, ?, 1800, ?, 'committed')")
       ->execute([
            $plan_id, $cid, $site_id, $position, $week, $publish,
            $kid,
            '/' . trim($row['slug'] ?: '', '/'),
            $title,
            'Refresh the existing post to reflect current data, update stale year mentions, and reclaim declining traffic. Stale signals: ' . ($row['refresh_reason'] ?: 'age'),
            json_encode(['cms', 'schema', 'llms']),
       ]);
    $item_id = (int)$db->lastInsertId();
    $db->prepare("UPDATE content_freshness SET queued_plan_item_id = ? WHERE id = ?")->execute([$item_id, $freshness_id]);

    return ['success' => true, 'plan_item_id' => $item_id, 'item_url' => '/dashboard/plan-item.php?id=' . $item_id];
}

function cf_site_summary(PDO $db, int $site_id): array
{
    $stmt = $db->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN needs_refresh = 1 AND queued_plan_item_id IS NULL AND dismissed_at IS NULL THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN queued_plan_item_id IS NOT NULL THEN 1 ELSE 0 END) AS queued,
        SUM(CASE WHEN dismissed_at IS NOT NULL THEN 1 ELSE 0 END) AS dismissed,
        MAX(last_audited_at) AS last_run
        FROM content_freshness WHERE site_id = ?");
    $stmt->execute([$site_id]);
    return $stmt->fetch() ?: [];
}
