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

$stmt = $db->prepare("SELECT k.*, s.domain FROM keywords k JOIN sites s ON k.site_id = s.id WHERE {$where_sql} ORDER BY k.impressions DESC, k.priority DESC, k.keyword LIMIT 200");
$stmt->execute($params);
$keywords = $stmt->fetchAll();

// Check GSC integration when filtered to one site
$gsc_connected = false;
$gsc_last_sync = null;
if ($filter_site) {
    $stmt = $db->prepare('SELECT id FROM integrations WHERE site_id = ? AND platform = "google_search_console" AND is_active = 1');
    $stmt->execute([(int)$filter_site]);
    $gsc_connected = (bool)$stmt->fetch();

    $stmt = $db->prepare('SELECT MAX(gsc_synced_at) FROM keywords WHERE site_id = ?');
    $stmt->execute([(int)$filter_site]);
    $gsc_last_sync = $stmt->fetchColumn();
}

// Get clusters for filter
$cluster_stmt = $db->prepare("SELECT DISTINCT k.cluster FROM keywords k JOIN sites s ON k.site_id = s.id WHERE s.user_id = ? AND k.cluster IS NOT NULL ORDER BY k.cluster");
$cluster_stmt->execute([$user_id]);
$clusters = $cluster_stmt->fetchAll(PDO::FETCH_COLUMN);

// Stats
$stmt = $db->prepare("SELECT COUNT(*) as total, COUNT(DISTINCT cluster) as clusters, AVG(priority) as avg_priority FROM keywords k JOIN sites s ON k.site_id = s.id WHERE s.user_id = ?");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();

// Get site name if filtered
$site_name_kw = '';
if ($filter_site) {
    foreach ($sites as $s) {
        if ($s['id'] == $filter_site) { $site_name_kw = $s['name']; break; }
    }
}
?>

<?php if ($filter_site && $site_name_kw): ?>
<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/site.php?id=' . (int)$filter_site) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site_name_kw) ?></a>
</div>
<?php else: ?>
<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/index.php') ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to Dashboard</a>
</div>
<?php endif; ?>

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
        <?php if ($filter_site): ?>
            <input type="hidden" name="site" value="<?= (int)$filter_site ?>">
        <?php else: ?>
        <select name="site" class="form-control" style="width: auto; min-width: 150px;">
            <option value="">All Sites</option>
            <?php foreach ($sites as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filter_site == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
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

<!-- GSC banner -->
<?php if ($filter_site): ?>
<div style="margin-bottom:10px;padding:10px 14px;background:<?= $gsc_connected ? '#f0fdf4' : '#fef3c7' ?>;border:1px solid <?= $gsc_connected ? '#86efac' : '#fcd34d' ?>;border-radius:6px;display:flex;justify-content:space-between;align-items:center;gap:10px;">
    <div style="font-size:12px;color:<?= $gsc_connected ? '#065f46' : '#92400e' ?>;">
        <?php if ($gsc_connected): ?>
            <strong>Real data from Google Search Console</strong> · Last synced: <?= $gsc_last_sync ? format_date($gsc_last_sync) : 'never' ?>
        <?php else: ?>
            <strong>⚠ Showing AI estimates only.</strong> Connect Google Search Console for real impressions, clicks, position, and CTR.
        <?php endif; ?>
    </div>
    <a href="<?= url('/dashboard/search-console.php?site=' . (int)$filter_site . ($gsc_connected ? '&action=sync' : '')) ?>" class="btn btn-sm btn-accent" style="text-decoration:none;white-space:nowrap;">
        <?= $gsc_connected ? '🔄 Sync Now' : 'Connect Google →' ?>
    </a>
</div>
<?php endif; ?>

<!-- Column legend -->
<details style="margin-bottom:10px;font-size:12px;color:#64748b;">
    <summary style="cursor:pointer;color:var(--primary);font-weight:600;">What do these columns mean?</summary>
    <div style="padding:8px 12px;background:#f8fafc;border:1px solid var(--border);border-radius:6px;margin-top:6px;line-height:1.6;">
        <div><strong>Impressions:</strong> How many times this keyword showed your site in Google search results (last 30 days). Higher = more search demand.</div>
        <div><strong>Clicks:</strong> How many people clicked through to your site. Higher = your snippet is compelling.</div>
        <div><strong>CTR:</strong> Click-through rate (clicks ÷ impressions). Low CTR with high impressions = your title/description needs work.</div>
        <div><strong>Position:</strong> Your average rank on Google. 1-10 = first page, 11-30 = page 2-3, 30+ = barely visible.</div>
        <div><strong>Priority:</strong> Auto-calculated from clicks + impressions. Higher = focus here first when writing content.</div>
        <div><strong>Cluster:</strong> Related keywords grouped together. One piece of content can target a whole cluster.</div>
        <div style="margin-top:6px;font-style:italic;">Real data shown when Google Search Console is connected. Otherwise these are AI estimates.</div>
    </div>
</details>

<div class="card">
    <?php if (empty($keywords)): ?>
        <p class="text-muted text-sm" style="padding: 20px; text-align: center;">No keywords yet. Use <strong>Find Keywords</strong> from your site page.</p>
    <?php else: ?>
        <!-- Bulk action bar -->
        <div id="kw-actions-bar" style="padding:8px 12px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">
            <div style="font-size:12px;color:#64748b;">
                <span id="kw-selected-count">0</span> selected
            </div>
            <div style="display:flex;gap:6px;">
                <button onclick="deleteSelected()" id="kw-delete-btn" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;font-size:11px;" disabled>Delete Selected</button>
                <?php if ($filter_site): ?>
                <button onclick="deleteAll(<?= (int)$filter_site ?>)" class="btn btn-sm" style="background:transparent;border:1px solid #dc2626;color:#dc2626;font-size:11px;">Clear All for This Site</button>
                <?php endif; ?>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th style="width:32px;"><input type="checkbox" id="kw-select-all" onchange="toggleAll(this)"></th>
                    <th>Keyword</th>
                    <th title="How many times this keyword showed your site in Google">Impressions</th>
                    <th title="How many people clicked through to your site">Clicks</th>
                    <th title="Click-through rate (%)">CTR</th>
                    <th title="Your average position on Google">Position</th>
                    <th title="0-100, higher = focus first">Priority</th>
                    <th>Cluster</th>
                    <th>Synced</th>
                    <th style="width:60px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keywords as $kw):
                    $has_gsc = !empty($kw['gsc_synced_at']);
                ?>
                <tr id="kw-row-<?= (int)$kw['id'] ?>">
                    <td><input type="checkbox" class="kw-check" data-id="<?= (int)$kw['id'] ?>" onchange="updateSelectedCount()"></td>
                    <td style="font-weight: 500;"><?= e($kw['keyword']) ?></td>
                    <td class="text-sm"><?= $kw['impressions'] !== null ? number_format($kw['impressions']) : '<span style="color:#cbd5e1;">—</span>' ?></td>
                    <td class="text-sm"><?= $kw['clicks'] !== null ? number_format($kw['clicks']) : '<span style="color:#cbd5e1;">—</span>' ?></td>
                    <td class="text-sm"><?= $kw['ctr'] !== null ? $kw['ctr'] . '%' : '<span style="color:#cbd5e1;">—</span>' ?></td>
                    <td>
                        <?php
                        $pos = $kw['gsc_position'] ?? $kw['current_rank'] ?? null;
                        if ($pos !== null && $pos > 0):
                            $pc = $pos <= 10 ? 'var(--success)' : ($pos <= 30 ? 'var(--warning)' : 'var(--danger)');
                        ?>
                            <span style="font-weight:600;color:<?= $pc ?>;">#<?= is_numeric($pos) ? (floor($pos) == $pos ? (int)$pos : number_format($pos, 1)) : $pos ?></span>
                        <?php else: ?>
                            <span style="color:#cbd5e1;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $p_color = '#64748b';
                        if ($kw['priority'] >= 70) $p_color = 'var(--success)';
                        elseif ($kw['priority'] >= 40) $p_color = 'var(--warning)';
                        ?>
                        <span style="font-weight: 600; color: <?= $p_color ?>;"><?= $kw['priority'] ?></span>
                    </td>
                    <td class="text-sm text-muted"><?= e($kw['cluster'] ?? '—') ?></td>
                    <td class="text-sm" style="<?= $has_gsc ? 'color:#10b981;' : 'color:#cbd5e1;' ?>"><?= $has_gsc ? format_date($kw['gsc_synced_at'], 'd M') : 'AI est.' ?></td>
                    <td>
                        <button onclick="deleteOne(<?= (int)$kw['id'] ?>)" title="Delete this keyword" style="background:transparent;border:none;color:#dc2626;cursor:pointer;font-size:14px;padding:2px 6px;">✕</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
const KW_API = '<?= url('/api/keywords-delete.php') ?>';

function toggleAll(cb) {
    document.querySelectorAll('.kw-check').forEach(c => c.checked = cb.checked);
    updateSelectedCount();
}
function updateSelectedCount() {
    const n = document.querySelectorAll('.kw-check:checked').length;
    document.getElementById('kw-selected-count').textContent = n;
    document.getElementById('kw-delete-btn').disabled = n === 0;
}
async function callDelete(body, label) {
    try {
        const res = await fetch(KW_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
        const data = await res.json();
        if (data.success) location.reload();
        else alert('Failed: ' + (data.error || 'unknown'));
    } catch(e) { alert('Error: ' + e.message); }
}
function deleteOne(id) {
    if (!confirm('Delete this keyword?')) return;
    callDelete({action:'delete', ids:[id]});
}
function deleteSelected() {
    const ids = Array.from(document.querySelectorAll('.kw-check:checked')).map(c => parseInt(c.dataset.id));
    if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' keyword' + (ids.length>1?'s':'') + '?')) return;
    callDelete({action:'delete', ids});
}
function deleteAll(siteId) {
    if (!confirm('Delete ALL keywords for this site? This cannot be undone. You can re-run Find Keywords any time.')) return;
    callDelete({action:'delete_all', site_id: siteId});
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
