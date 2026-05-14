<?php
/**
 * Performance Loop — fetch metrics, snapshot them daily, surface winners/losers.
 *
 * Two metric sources today:
 *   - GSC (channel='cms')          : impressions, clicks, CTR, avg position keyed by post URL
 *   - post_channels.metrics (JSON) : engagement totals from social channel adapters
 *
 * Snapshots go into `post_performance` (one row per post per channel per day).
 *
 * The analyzer surfaces three buckets:
 *   - winners   : trending up — recommend more in the topic
 *   - decay     : was good, now slipping — refresh candidate
 *   - dead_air  : published, no traction after 14 days — kill or rethink
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/integrations/google.php';

/**
 * Build the canonical public URL we expect GSC to see for a post.
 */
function performance_post_url(array $site, array $post): string
{
    $domain = preg_replace('#^https?://#i', '', trim($site['domain'] ?? ''));
    $domain = rtrim($domain, '/');
    if (empty($domain) || empty($post['slug'])) return '';
    return 'https://' . $domain . '/blog/' . $post['slug'];
}

/**
 * Snapshot today's GSC data into post_performance.
 * Returns number of post-level rows written.
 */
function performance_snapshot_organic(PDO $db, int $site_id, int $days = 28): array
{
    $access_token = google_get_token($db, $site_id);
    if (!$access_token) return ['success' => false, 'error' => 'GSC not connected for site', 'rows' => 0];

    $stmt = $db->prepare('SELECT id, domain FROM sites WHERE id = ?');
    $stmt->execute([$site_id]);
    $site = $stmt->fetch();
    if (!$site) return ['success' => false, 'error' => 'Site not found', 'rows' => 0];

    $bare = preg_replace('#^https?://#i', '', $site['domain']);
    $bare = preg_replace('#^www\.#i', '', $bare);
    $bare = rtrim($bare, '/');

    $end_date   = date('Y-m-d', strtotime('-2 days'));
    $start_date = date('Y-m-d', strtotime("-{$days} days"));

    $candidates = [
        'sc-domain:' . $bare,
        'https://www.' . $bare . '/',
        'https://' . $bare . '/',
    ];

    $pages = null;
    foreach ($candidates as $candidate) {
        $resp = google_search_analytics($access_token, $candidate, $start_date, $end_date, 'page', 500);
        if ($resp && !empty($resp['rows'])) { $pages = $resp; break; }
    }
    if (!$pages) return ['success' => false, 'error' => 'No GSC page data', 'rows' => 0];

    // Index posts by canonical URL fragment for matching
    $stmt = $db->prepare('SELECT id, slug FROM posts WHERE site_id = ? AND status = "published"');
    $stmt->execute([$site_id]);
    $posts_by_slug = [];
    foreach ($stmt->fetchAll() as $p) {
        $posts_by_slug[$p['slug']] = (int)$p['id'];
    }

    $today = date('Y-m-d');
    $written = 0;
    foreach ($pages['rows'] as $row) {
        $url  = $row['keys'][0] ?? '';
        if (!$url) continue;
        if (!preg_match('#/blog/([^/?#]+)/?$#i', $url, $m)) continue;
        $slug = $m[1];
        if (!isset($posts_by_slug[$slug])) continue;

        $post_id     = $posts_by_slug[$slug];
        $impressions = (int)($row['impressions'] ?? 0);
        $clicks      = (int)($row['clicks'] ?? 0);
        $ctr         = (float)($row['ctr'] ?? 0);
        $pos         = (float)($row['position'] ?? 0);

        $db->prepare('INSERT INTO post_performance (post_id, channel, snapshot_date, impressions, clicks, ctr, avg_position, raw_metrics)
                      VALUES (?, "cms", ?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE impressions = VALUES(impressions), clicks = VALUES(clicks),
                                              ctr = VALUES(ctr), avg_position = VALUES(avg_position),
                                              raw_metrics = VALUES(raw_metrics)')
           ->execute([$post_id, $today, $impressions, $clicks, $ctr, $pos, json_encode($row)]);
        $written++;
    }

    return ['success' => true, 'rows' => $written];
}

/**
 * Snapshot today's social metrics from post_channels.metrics JSON.
 * Each channel adapter is responsible for populating metrics — we just snapshot.
 */
function performance_snapshot_social(PDO $db, int $site_id): array
{
    $today = date('Y-m-d');
    $stmt = $db->prepare('
        SELECT pc.post_id, pc.channel, pc.metrics
        FROM post_channels pc
        JOIN posts p ON pc.post_id = p.id
        WHERE p.site_id = ? AND pc.channel <> "cms"
          AND pc.status = "published" AND pc.metrics IS NOT NULL
    ');
    $stmt->execute([$site_id]);

    $written = 0;
    foreach ($stmt->fetchAll() as $row) {
        $metrics = json_decode($row['metrics'] ?: '{}', true) ?: [];
        $eng = (int)(($metrics['likes'] ?? 0) + ($metrics['comments'] ?? 0) + ($metrics['shares'] ?? 0) + ($metrics['reactions'] ?? 0));
        $impr = (int)($metrics['impressions'] ?? 0);
        $clicks = (int)($metrics['clicks'] ?? 0);

        $db->prepare('INSERT INTO post_performance (post_id, channel, snapshot_date, impressions, clicks, engagement, raw_metrics)
                      VALUES (?, ?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE impressions = VALUES(impressions), clicks = VALUES(clicks),
                                              engagement = VALUES(engagement), raw_metrics = VALUES(raw_metrics)')
           ->execute([$row['post_id'], $row['channel'], $today, $impr, $clicks, $eng, json_encode($metrics)]);
        $written++;
    }
    return ['success' => true, 'rows' => $written];
}

/**
 * Aggregate per-post performance for the site.
 * Returns array of { post_id, title, slug, published_at, channel_breakdown, total_impressions, total_clicks, avg_ctr, trend }
 *
 * @param string $window '7d', '28d', '90d'
 */
function performance_overview(PDO $db, int $site_id, string $window = '28d'): array
{
    $days = (int)preg_replace('/[^0-9]/', '', $window) ?: 28;

    $stmt = $db->prepare('
        SELECT
            p.id            AS post_id,
            p.title,
            p.slug,
            p.published_at,
            pp.channel,
            SUM(pp.impressions) AS impressions,
            SUM(pp.clicks)      AS clicks,
            AVG(pp.ctr)         AS ctr,
            AVG(pp.avg_position) AS avg_position,
            SUM(pp.engagement)  AS engagement,
            MAX(pp.snapshot_date) AS last_snapshot
        FROM posts p
        LEFT JOIN post_performance pp
               ON pp.post_id = p.id
              AND pp.snapshot_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        WHERE p.site_id = ? AND p.status = "published"
        GROUP BY p.id, pp.channel
        ORDER BY impressions DESC
    ');
    $stmt->execute([$days, $site_id]);
    $rows = $stmt->fetchAll();

    $by_post = [];
    foreach ($rows as $r) {
        $pid = (int)$r['post_id'];
        if (!isset($by_post[$pid])) {
            $by_post[$pid] = [
                'post_id'      => $pid,
                'title'        => $r['title'],
                'slug'         => $r['slug'],
                'published_at' => $r['published_at'],
                'channels'     => [],
                'total_impressions' => 0,
                'total_clicks'      => 0,
                'total_engagement'  => 0,
                'last_snapshot'     => $r['last_snapshot'],
            ];
        }
        if ($r['channel']) {
            $by_post[$pid]['channels'][$r['channel']] = [
                'impressions' => (int)$r['impressions'],
                'clicks'      => (int)$r['clicks'],
                'ctr'         => $r['ctr'] !== null ? round((float)$r['ctr'] * 100, 2) : null,
                'avg_position'=> $r['avg_position'] !== null ? round((float)$r['avg_position'], 1) : null,
                'engagement'  => (int)$r['engagement'],
            ];
            $by_post[$pid]['total_impressions'] += (int)$r['impressions'];
            $by_post[$pid]['total_clicks']      += (int)$r['clicks'];
            $by_post[$pid]['total_engagement']  += (int)$r['engagement'];
        }
    }
    return array_values($by_post);
}

/**
 * Detect trend by comparing the last 7 days to the prior 7 days for the cms channel.
 * Returns 'up' | 'down' | 'flat' | 'new'.
 */
function performance_trend(PDO $db, int $post_id): string
{
    $stmt = $db->prepare('
        SELECT
            SUM(CASE WHEN snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN impressions ELSE 0 END) AS recent,
            SUM(CASE WHEN snapshot_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                     AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN impressions ELSE 0 END) AS prior
        FROM post_performance
        WHERE post_id = ? AND channel = "cms"
    ');
    $stmt->execute([$post_id]);
    $row = $stmt->fetch();
    $recent = (int)($row['recent'] ?? 0);
    $prior  = (int)($row['prior']  ?? 0);
    if ($recent === 0 && $prior === 0) return 'new';
    if ($prior === 0) return 'up';
    $delta = ($recent - $prior) / max(1, $prior);
    if ($delta > 0.2) return 'up';
    if ($delta < -0.2) return 'down';
    return 'flat';
}

/**
 * Bucket all posts for a site into actionable groups.
 */
function performance_buckets(PDO $db, int $site_id): array
{
    $posts = performance_overview($db, $site_id, '28d');

    $winners = []; $decay = []; $dead_air = [];

    foreach ($posts as $p) {
        $cms = $p['channels']['cms'] ?? null;
        $impr = $cms['impressions'] ?? 0;
        $clicks = $cms['clicks'] ?? 0;
        $ctr  = $cms['ctr'] ?? null;
        $pos  = $cms['avg_position'] ?? null;
        $trend = performance_trend($db, (int)$p['post_id']);

        $age_days = $p['published_at'] ? (int)((time() - strtotime($p['published_at'])) / 86400) : 0;

        // Winner: meaningful traffic AND trending up (or strong CTR)
        if ($clicks >= 5 && ($trend === 'up' || ($ctr !== null && $ctr > 3))) {
            $winners[] = $p + ['trend' => $trend, 'reason' => $trend === 'up' ? 'Trending up' : 'High CTR'];
            continue;
        }
        // Decay: had decent impressions but trend down OR low CTR with high impressions (title problem)
        if ($impr >= 100 && ($trend === 'down' || ($ctr !== null && $ctr < 1.0))) {
            $why = $trend === 'down' ? 'Traffic dropping' : 'Low CTR — title/meta needs work';
            $decay[] = $p + ['trend' => $trend, 'reason' => $why];
            continue;
        }
        // Dead air: published > 14 days ago, < 50 impressions
        if ($age_days > 14 && $impr < 50) {
            $dead_air[] = $p + ['trend' => $trend, 'reason' => 'No traction after ' . $age_days . ' days'];
        }
    }

    return [
        'winners'  => $winners,
        'decay'    => $decay,
        'dead_air' => $dead_air,
        'all'      => $posts,
    ];
}

/**
 * Site-wide summary numbers for the topcards.
 */
function performance_site_summary(PDO $db, int $site_id, int $days = 28): array
{
    $stmt = $db->prepare('
        SELECT
            SUM(impressions) AS impressions,
            SUM(clicks)      AS clicks,
            AVG(ctr)         AS ctr,
            AVG(avg_position) AS avg_position
        FROM post_performance pp
        JOIN posts p ON pp.post_id = p.id
        WHERE p.site_id = ? AND pp.channel = "cms"
          AND pp.snapshot_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ');
    $stmt->execute([$site_id, $days]);
    $row = $stmt->fetch() ?: [];
    return [
        'impressions' => (int)($row['impressions'] ?? 0),
        'clicks'      => (int)($row['clicks'] ?? 0),
        'ctr'         => $row['ctr'] !== null ? round((float)$row['ctr'] * 100, 2) : null,
        'avg_position'=> $row['avg_position'] !== null ? round((float)$row['avg_position'], 1) : null,
    ];
}

/**
 * Log a user action so we know what's been handled.
 */
function performance_log_action(PDO $db, int $post_id, string $action, ?string $note = null): void
{
    $db->prepare('INSERT INTO performance_actions (post_id, action, note) VALUES (?, ?, ?)')
       ->execute([$post_id, $action, $note]);
}

function performance_actions_for_post(PDO $db, int $post_id): array
{
    $stmt = $db->prepare('SELECT action, note, created_at FROM performance_actions WHERE post_id = ? ORDER BY created_at DESC');
    $stmt->execute([$post_id]);
    return $stmt->fetchAll();
}
