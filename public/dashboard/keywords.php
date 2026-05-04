<?php
/**
 * Dashboard — Keywords management.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$filter_site = $_GET['site'] ?? '';
$filter_cluster = $_GET['cluster'] ?? '';

$page_title = 'Keywords';

ob_start();

// Get sites
$stmt = $db->prepare('SELECT id, name FROM sites WHERE user_id = ? ORDER BY name');
$stmt->execute([$user_id]);
$sites = $stmt->fetchAll();

// Build query
$where = ['s.user_id = ?'];
$params = [$user_id];

if ($filter_site) { $where[] = 'k.site_id = ?'; $params[] = (int)$filter_site; }
if ($filter_cluster) { $where[] = 'k.cluster = ?'; $params[] = $filter_cluster; }

$where_sql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT k.*, s.domain FROM keywords k JOIN sites s ON k.site_id = s.id WHERE {$where_sql} ORDER BY k.priority DESC, k.keyword LIMIT 200");
$stmt->execute($params);
$keywords = $stmt->fetchAll();

// Get clusters for filter
$cluster_stmt = $db->prepare("SELECT DISTINCT k.cluster FROM keywords k JOIN sites s ON k.site_id = s.id WHERE s.user_id = ? AND k.cluster IS NOT NULL ORDER BY k.cluster");
$cluster_stmt->execute([$user_id]);
$clusters = $cluster_stmt->fetchAll(PDO::FETCH_COLUMN);

// Stats
$stmt = $db->prepare("SELECT COUNT(*) as total, COUNT(DISTINCT cluster) as clusters, AVG(priority) as avg_priority FROM keywords k JOIN sites s ON k.site_id = s.id WHERE s.user_id = ?");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Keywords</div>
        <div class="stat-value"><?= $stats['total'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Clusters</div>
        <div class="stat-value"><?= $stats['clusters'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Avg Priority</div>
        <div class="stat-value"><?= round($stats['avg_priority'] ?? 0) ?></div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="padding: 10px 16px;">
    <form method="GET" class="flex gap-4 items-center" style="flex-wrap: wrap;">
        <select name="site" class="form-control" style="width: auto; min-width: 150px;">
            <option value="">All Sites</option>
            <?php foreach ($sites as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filter_site == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="cluster" class="form-control" style="width: auto; min-width: 150px;">
            <option value="">All Clusters</option>
            <?php foreach ($clusters as $c): ?>
                <option value="<?= e($c) ?>" <?= $filter_cluster === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <?php if ($filter_site || $filter_cluster): ?>
            <a href="<?= url('/dashboard/keywords.php') ?>" class="text-sm text-muted">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if (empty($keywords)): ?>
        <p class="text-muted text-sm" style="padding: 20px; text-align: center;">No keywords yet. Run: <code>php agent/keyword-research.php --site=ID</code></p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Keyword</th>
                    <th>Site</th>
                    <th>Cluster</th>
                    <th>Priority</th>
                    <th>Difficulty</th>
                    <th>Rank</th>
                    <th>Last Checked</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keywords as $kw): ?>
                <tr>
                    <td style="font-weight: 500;"><?= e($kw['keyword']) ?></td>
                    <td class="text-sm"><?= e($kw['domain']) ?></td>
                    <td class="text-sm"><?= e($kw['cluster'] ?? '—') ?></td>
                    <td>
                        <?php
                        $p_color = '#64748b';
                        if ($kw['priority'] >= 70) $p_color = 'var(--success)';
                        elseif ($kw['priority'] >= 40) $p_color = 'var(--warning)';
                        ?>
                        <span style="font-weight: 600; color: <?= $p_color ?>;"><?= $kw['priority'] ?></span>
                    </td>
                    <td>
                        <?php if ($kw['difficulty'] !== null): ?>
                            <?php
                            $d_color = 'var(--success)';
                            if ($kw['difficulty'] >= 70) $d_color = 'var(--danger)';
                            elseif ($kw['difficulty'] >= 40) $d_color = 'var(--warning)';
                            ?>
                            <span style="color: <?= $d_color ?>;"><?= $kw['difficulty'] ?></span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="text-sm"><?= $kw['current_rank'] ?? '—' ?></td>
                    <td class="text-sm text-muted"><?= $kw['last_checked'] ? format_date($kw['last_checked'], 'd M') : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
