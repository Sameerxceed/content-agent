<?php
/**
 * Dashboard — Analytics overview.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$filter_site = $_GET['site'] ?? '';

$page_title = 'Analytics';

ob_start();

if (empty($filter_site)): ?>
<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/index.php') ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to Dashboard</a>
</div>
<?php endif;

// Get sites
$stmt = $db->prepare('SELECT id, name, domain FROM sites WHERE user_id = ? ORDER BY name');
$stmt->execute([$user_id]);
$sites = $stmt->fetchAll();

$site_filter_sql = '';
$params = [$user_id];
if ($filter_site) {
    $site_filter_sql = ' AND s.id = ?';
    $params[] = (int)$filter_site;
}

// Posts by week (last 8 weeks)
$stmt = $db->prepare("
    SELECT
        YEARWEEK(p.created_at, 1) as yw,
        DATE_FORMAT(MIN(p.created_at), '%d %b') as week_start,
        SUM(CASE WHEN p.type = 'blog' THEN 1 ELSE 0 END) as blogs,
        SUM(CASE WHEN p.type = 'news' THEN 1 ELSE 0 END) as news,
        COUNT(*) as total
    FROM posts p
    JOIN sites s ON p.site_id = s.id
    WHERE s.user_id = ? {$site_filter_sql}
      AND p.created_at > DATE_SUB(NOW(), INTERVAL 8 WEEK)
    GROUP BY yw
    ORDER BY yw
");
$stmt->execute($params);
$weekly_posts = $stmt->fetchAll();

// Posts by status
$stmt = $db->prepare("SELECT p.status, COUNT(*) as cnt FROM posts p JOIN sites s ON p.site_id = s.id WHERE s.user_id = ? {$site_filter_sql} GROUP BY p.status");
$stmt->execute($params);
$status_counts = [];
foreach ($stmt->fetchAll() as $r) $status_counts[$r['status']] = $r['cnt'];

// SEO score trend
$stmt = $db->prepare("
    SELECT a.score, a.total_issues, a.critical, DATE_FORMAT(a.run_at, '%d %b') as date_label, s.domain
    FROM seo_audits a
    JOIN sites s ON a.site_id = s.id
    WHERE s.user_id = ? {$site_filter_sql}
    ORDER BY a.run_at DESC
    LIMIT 20
");
$stmt->execute($params);
$seo_trend = $stmt->fetchAll();

// Top keywords
$stmt = $db->prepare("SELECT k.keyword, k.priority, k.difficulty, k.current_rank, s.domain FROM keywords k JOIN sites s ON k.site_id = s.id WHERE s.user_id = ? {$site_filter_sql} ORDER BY k.priority DESC LIMIT 10");
$stmt->execute($params);
$top_keywords = $stmt->fetchAll();

// Agent activity summary (last 30 days)
$stmt = $db->prepare("
    SELECT al.action, COUNT(*) as runs,
           SUM(CASE WHEN al.status = 'success' THEN 1 ELSE 0 END) as successes,
           SUM(CASE WHEN al.status = 'fail' THEN 1 ELSE 0 END) as failures,
           ROUND(AVG(al.duration_ms)) as avg_ms
    FROM agent_log al
    LEFT JOIN sites s ON al.site_id = s.id
    WHERE s.user_id = ? {$site_filter_sql}
      AND al.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY al.action
    ORDER BY runs DESC
");
$stmt->execute($params);
$agent_summary = $stmt->fetchAll();
?>

<!-- Site filter -->
<div class="card" style="padding: 10px 16px;">
    <form method="GET" class="flex gap-4 items-center">
        <select name="site" class="form-control" style="width: auto; min-width: 150px;">
            <option value="">All Sites</option>
            <?php foreach ($sites as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filter_site == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
    </form>
</div>

<!-- Post status overview -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Published</div>
        <div class="stat-value"><?= $status_counts['published'] ?? 0 ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Approved</div>
        <div class="stat-value"><?= $status_counts['approved'] ?? 0 ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Drafts</div>
        <div class="stat-value"><?= $status_counts['draft'] ?? 0 ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Rejected</div>
        <div class="stat-value"><?= $status_counts['rejected'] ?? 0 ?></div>
    </div>
</div>

<div class="grid-2">
    <!-- Weekly content output -->
    <div class="card">
        <div class="card-header">Content Output (Last 8 Weeks)</div>
        <?php if (empty($weekly_posts)): ?>
            <p class="text-muted text-sm">No post data yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Week</th><th>Blogs</th><th>News</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($weekly_posts as $w): ?>
                    <tr>
                        <td class="text-sm"><?= e($w['week_start']) ?></td>
                        <td><?= $w['blogs'] ?></td>
                        <td><?= $w['news'] ?></td>
                        <td style="font-weight: 600;"><?= $w['total'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- SEO Score Trend -->
    <div class="card">
        <div class="card-header">SEO Score Trend</div>
        <?php if (empty($seo_trend)): ?>
            <p class="text-muted text-sm">No audit data yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Date</th><th>Site</th><th>Score</th><th>Issues</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($seo_trend as $t): ?>
                    <tr>
                        <td class="text-sm"><?= e($t['date_label']) ?></td>
                        <td class="text-sm"><?= e($t['domain']) ?></td>
                        <td>
                            <?php
                            $sc = 'score-bad';
                            if ($t['score'] >= 80) $sc = 'score-good';
                            elseif ($t['score'] >= 50) $sc = 'score-ok';
                            ?>
                            <span class="score-circle <?= $sc ?>" style="width:30px;height:30px;font-size:11px;"><?= $t['score'] ?></span>
                        </td>
                        <td><?= $t['total_issues'] ?> <?= $t['critical'] > 0 ? '<span class="badge badge-critical">' . $t['critical'] . '</span>' : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <!-- Top Keywords -->
    <div class="card">
        <div class="card-header">Top Keywords</div>
        <?php if (empty($top_keywords)): ?>
            <p class="text-muted text-sm">No keywords yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Keyword</th><th>Priority</th><th>Difficulty</th><th>Rank</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($top_keywords as $kw): ?>
                    <tr>
                        <td class="text-sm"><?= e($kw['keyword']) ?></td>
                        <td><span style="font-weight:600;"><?= $kw['priority'] ?></span></td>
                        <td><?= $kw['difficulty'] ?? '—' ?></td>
                        <td><?= $kw['current_rank'] ?? '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Agent Performance -->
    <div class="card">
        <div class="card-header">Agent Performance (30 days)</div>
        <?php if (empty($agent_summary)): ?>
            <p class="text-muted text-sm">No activity yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Action</th><th>Runs</th><th>Success</th><th>Failures</th><th>Avg Time</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($agent_summary as $ag): ?>
                    <tr>
                        <td class="text-sm"><?= e($ag['action']) ?></td>
                        <td><?= $ag['runs'] ?></td>
                        <td style="color:var(--success);"><?= $ag['successes'] ?></td>
                        <td><?= $ag['failures'] > 0 ? '<span style="color:var(--danger)">' . $ag['failures'] . '</span>' : '0' ?></td>
                        <td class="text-sm"><?= $ag['avg_ms'] ? round($ag['avg_ms'] / 1000, 1) . 's' : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
