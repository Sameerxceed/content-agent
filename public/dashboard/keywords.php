<?php
/**
 * Dashboard — Keywords management.
 * Add custom keywords, ignore off-brand ones, filter, bulk-edit.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$filter_site = $_GET['site'] ?? '';
$filter_cluster = $_GET['cluster'] ?? '';
$filter_status = $_GET['status'] ?? 'active'; // active | ignored | quick_wins | all

$page_title = 'Keywords';

ob_start();

// Get sites
$stmt = $db->prepare('SELECT id, name FROM sites WHERE user_id = ? ORDER BY name');
$stmt->execute([$user_id]);
$sites = $stmt->fetchAll();

// Build query
$where = ['s.user_id = ?'];
$params = [$user_id];

if ($filter_site)    { $where[] = 'k.site_id = ?'; $params[] = (int)$filter_site; }
if ($filter_cluster) { $where[] = 'k.cluster = ?'; $params[] = $filter_cluster; }

if ($filter_status === 'active') {
    $where[] = "k.status = 'active'";
} elseif ($filter_status === 'ignored') {
    $where[] = "k.status = 'ignored'";
} elseif ($filter_status === 'quick_wins') {
    // Page 2-3 with real impressions = quick wins
    $where[] = "k.status = 'active' AND k.gsc_position BETWEEN 11 AND 30 AND k.impressions > 0";
}
// 'all' = no extra filter

$where_sql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT k.*, s.domain FROM keywords k JOIN sites s ON k.site_id = s.id WHERE {$where_sql} ORDER BY k.impressions DESC, k.priority DESC, k.keyword LIMIT 300");
$stmt->execute($params);
$keywords = $stmt->fetchAll();

// Per-site status counts
$status_counts = ['active' => 0, 'ignored' => 0, 'quick_wins' => 0, 'all' => 0];
if ($filter_site) {
    $stmt = $db->prepare("SELECT status, COUNT(*) c FROM keywords WHERE site_id = ? GROUP BY status");
    $stmt->execute([(int)$filter_site]);
    foreach ($stmt->fetchAll() as $r) { $status_counts[$r['status']] = (int)$r['c']; }
    $status_counts['all'] = $status_counts['active'] + $status_counts['ignored'];

    $stmt = $db->prepare("SELECT COUNT(*) FROM keywords WHERE site_id = ? AND status = 'active' AND gsc_position BETWEEN 11 AND 30 AND impressions > 0");
    $stmt->execute([(int)$filter_site]);
    $status_counts['quick_wins'] = (int)$stmt->fetchColumn();
}

// GSC integration when filtered to one site
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

// Clusters for filter
$cluster_stmt = $db->prepare("SELECT DISTINCT k.cluster FROM keywords k JOIN sites s ON k.site_id = s.id WHERE s.user_id = ? AND k.cluster IS NOT NULL ORDER BY k.cluster");
$cluster_stmt->execute([$user_id]);
$clusters = $cluster_stmt->fetchAll(PDO::FETCH_COLUMN);

// Top-line stats (always active scope)
$stmt = $db->prepare("SELECT COUNT(*) FROM keywords k JOIN sites s ON k.site_id = s.id WHERE s.user_id = ? AND k.status = 'active'");
$stmt->execute([$user_id]);
$total_active = (int)$stmt->fetchColumn();

// Site name if filtered
$site_name_kw = '';
if ($filter_site) {
    foreach ($sites as $s) {
        if ($s['id'] == $filter_site) { $site_name_kw = $s['name']; break; }
    }
}

function tab_url($current_filters, $status) {
    $q = array_merge($current_filters, ['status' => $status]);
    return url('/dashboard/keywords.php?' . http_build_query(array_filter($q)));
}

$current_filters = ['site' => $filter_site, 'cluster' => $filter_cluster];
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

<!-- GSC banner -->
<?php if ($filter_site): ?>
<div style="margin-bottom:10px;padding:10px 14px;background:<?= $gsc_connected ? '#f0fdf4' : '#fef3c7' ?>;border:1px solid <?= $gsc_connected ? '#86efac' : '#fcd34d' ?>;border-radius:6px;display:flex;justify-content:space-between;align-items:center;gap:10px;">
    <div style="font-size:12px;color:<?= $gsc_connected ? '#065f46' : '#92400e' ?>;">
        <?php if ($gsc_connected): ?>
            <strong>Real data from Google Search Console</strong> · Last synced: <?= $gsc_last_sync ? format_date($gsc_last_sync) : 'never — click Sync now' ?>
        <?php else: ?>
            <strong>⚠ Showing AI estimates only.</strong> Connect Google Search Console for real impressions, clicks, position, and CTR.
        <?php endif; ?>
    </div>
    <a href="<?= url('/dashboard/search-console.php?site=' . (int)$filter_site . ($gsc_connected ? '&action=sync' : '')) ?>" class="btn btn-sm btn-accent" style="text-decoration:none;white-space:nowrap;">
        <?= $gsc_connected ? '🔄 Sync Now' : 'Connect Google →' ?>
    </a>
</div>
<?php endif; ?>

<!-- Add custom keyword (only when site is selected) -->
<?php if ($filter_site): ?>
<div class="card" style="margin-bottom:10px;padding:12px 14px;">
    <div style="font-weight:600;font-size:13px;margin-bottom:6px;">Add keywords you want to target</div>
    <div style="font-size:11px;color:#64748b;margin-bottom:8px;">e.g. <strong>xceed imagination</strong>. Google Search Console will start tracking impressions/position on the next sync if anyone searches for it.</div>
    <div style="display:flex;gap:6px;">
        <input type="text" id="add-keyword-input" class="form-control" placeholder="Type one keyword, or several separated by commas" style="font-size:13px;flex:1;" onkeydown="if(event.key==='Enter'){event.preventDefault();addKeywords();}">
        <button onclick="addKeywords()" class="btn btn-accent" style="font-size:12px;white-space:nowrap;">+ Add</button>
    </div>
    <div id="add-msg" style="font-size:11px;margin-top:6px;"></div>
</div>
<?php endif; ?>

<!-- Filters: site + cluster -->
<div class="card" style="padding: 10px 16px;margin-bottom:10px;">
    <form method="GET" class="flex gap-4 items-center" style="flex-wrap: wrap;">
        <input type="hidden" name="status" value="<?= e($filter_status) ?>">
        <?php if ($filter_site): ?>
            <input type="hidden" name="site" value="<?= (int)$filter_site ?>">
        <?php else: ?>
        <select name="site" class="form-control" style="width: auto; min-width: 180px;">
            <option value="">All Sites</option>
            <?php foreach ($sites as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filter_site == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if (!empty($clusters)): ?>
        <select name="cluster" class="form-control" style="width: auto; min-width: 150px;">
            <option value="">All Clusters</option>
            <?php foreach ($clusters as $c): ?>
                <option value="<?= e($c) ?>" <?= $filter_cluster === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-outline btn-sm">Apply</button>
        <?php if ($filter_site || $filter_cluster): ?>
            <a href="<?= url('/dashboard/keywords.php') ?>" class="text-sm text-muted">Clear all</a>
        <?php endif; ?>
    </form>
</div>

<!-- Status tabs -->
<?php if ($filter_site): ?>
<div style="display:flex;gap:4px;margin-bottom:10px;border-bottom:1px solid var(--border);">
    <?php
    $tabs = [
        'active'     => ['Active', $status_counts['active']],
        'quick_wins' => ['Quick Wins', $status_counts['quick_wins']],
        'ignored'    => ['Ignored', $status_counts['ignored']],
        'all'        => ['All', $status_counts['all']],
    ];
    foreach ($tabs as $key => [$label, $count]):
        $is_active = $filter_status === $key;
    ?>
    <a href="<?= tab_url($current_filters, $key) ?>" style="text-decoration:none;padding:8px 14px;font-size:13px;border-bottom:2px solid <?= $is_active ? 'var(--accent)' : 'transparent' ?>;color:<?= $is_active ? 'var(--accent)' : '#64748b' ?>;font-weight:<?= $is_active ? '600' : '500' ?>;">
        <?= $label ?> <span style="font-size:11px;color:#94a3b8;">(<?= $count ?>)</span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Column legend -->
<details style="margin-bottom:10px;font-size:12px;color:#64748b;">
    <summary style="cursor:pointer;color:var(--primary);font-weight:600;">What do these columns mean?</summary>
    <div style="padding:8px 12px;background:#f8fafc;border:1px solid var(--border);border-radius:6px;margin-top:6px;line-height:1.6;">
        <div><strong>Impressions:</strong> Times this keyword showed your site in Google (last 30 days).</div>
        <div><strong>Clicks:</strong> People who clicked through to your site.</div>
        <div><strong>CTR:</strong> Click-through rate. Low CTR + high impressions = your title/description needs work.</div>
        <div><strong>Position:</strong> Avg rank on Google. 1-10 = page 1, 11-30 = page 2-3, 30+ = barely visible.</div>
        <div><strong>Priority:</strong> Auto-calculated from clicks + impressions.</div>
        <div><strong>Source:</strong> AI = autocomplete research · GSC = real Google data · Manual = you added it.</div>
        <div><strong>Quick Wins tab:</strong> Keywords ranked 11-30 with real impressions — small content tweaks can push these to page 1.</div>
    </div>
</details>

<div class="card">
    <?php if (empty($keywords)): ?>
        <p class="text-muted text-sm" style="padding: 20px; text-align: center;">
        <?php if ($filter_status === 'ignored'): ?>
            No ignored keywords. Use the 👁 button to ignore off-brand ones.
        <?php elseif ($filter_status === 'quick_wins'): ?>
            No quick wins yet. Sync Google Search Console to see keywords ranked 11-30 that could be pushed to page 1.
        <?php else: ?>
            No keywords yet. Use <strong>Find Keywords</strong> from your site page, or add custom keywords above.
        <?php endif; ?>
        </p>
    <?php else: ?>
        <!-- Bulk action bar -->
        <div id="kw-actions-bar" style="padding:8px 12px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">
            <div style="font-size:12px;color:#64748b;">
                <span id="kw-selected-count">0</span> selected
            </div>
            <div style="display:flex;gap:6px;">
                <?php if ($filter_status !== 'ignored'): ?>
                <button onclick="bulkIgnore()" id="kw-ignore-btn" class="btn btn-sm" style="background:#f59e0b;color:#fff;border:none;font-size:11px;" disabled>Ignore Selected</button>
                <?php else: ?>
                <button onclick="bulkRestore()" id="kw-restore-btn" class="btn btn-sm" style="background:#10b981;color:#fff;border:none;font-size:11px;" disabled>Restore Selected</button>
                <?php endif; ?>
                <button onclick="bulkDelete()" id="kw-delete-btn" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;font-size:11px;" disabled>Delete Selected</button>
                <?php if ($filter_site): ?>
                <button onclick="deleteAll(<?= (int)$filter_site ?>)" class="btn btn-sm" style="background:transparent;border:1px solid #dc2626;color:#dc2626;font-size:11px;">Clear All AI Estimates</button>
                <?php endif; ?>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th style="width:32px;"><input type="checkbox" id="kw-select-all" onchange="toggleAll(this)"></th>
                    <th>Keyword</th>
                    <th title="Times shown in Google">Impressions</th>
                    <th>Clicks</th>
                    <th>CTR</th>
                    <th title="Your average position">Position</th>
                    <th title="0-100 score">Priority</th>
                    <th>Source</th>
                    <th>Cluster</th>
                    <th style="width:90px;text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keywords as $kw):
                    $has_gsc = !empty($kw['gsc_synced_at']);
                    $source = $kw['source'] ?? 'autocomplete';
                    $is_ignored = ($kw['status'] ?? 'active') === 'ignored';
                ?>
                <tr id="kw-row-<?= (int)$kw['id'] ?>" style="<?= $is_ignored ? 'opacity:0.55;' : '' ?>">
                    <td><input type="checkbox" class="kw-check" data-id="<?= (int)$kw['id'] ?>" onchange="updateSelectedCount()"></td>
                    <td style="font-weight: 500;<?= $is_ignored ? 'text-decoration:line-through;' : '' ?>"><?= e($kw['keyword']) ?>
                        <?php if ($is_ignored && !empty($kw['ignored_reason'])): ?>
                            <div style="font-size:10px;color:#94a3b8;font-style:italic;">— <?= e($kw['ignored_reason']) ?></div>
                        <?php endif; ?>
                    </td>
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
                    <td>
                        <?php
                        $src_styles = [
                            'gsc'          => ['Google', '#10b981', '#d1fae5'],
                            'manual'       => ['Manual', '#7c3aed', '#ede9fe'],
                            'autocomplete' => ['AI', '#64748b', '#f1f5f9'],
                            'paa'          => ['PAA', '#0284c7', '#e0f2fe'],
                        ];
                        [$slabel, $sfg, $sbg] = $src_styles[$source] ?? $src_styles['autocomplete'];
                        ?>
                        <span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;background:<?= $sbg ?>;color:<?= $sfg ?>;"><?= $slabel ?></span>
                    </td>
                    <td class="text-sm text-muted"><?= e($kw['cluster'] ?? '—') ?></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <?php if ($is_ignored): ?>
                            <button onclick="restoreOne(<?= (int)$kw['id'] ?>)" title="Restore (un-ignore)" style="background:transparent;border:none;color:#10b981;cursor:pointer;font-size:14px;padding:2px 4px;">↺</button>
                        <?php else: ?>
                            <button onclick="ignoreOne(<?= (int)$kw['id'] ?>)" title="Ignore this keyword" style="background:transparent;border:none;color:#f59e0b;cursor:pointer;font-size:14px;padding:2px 4px;">👁</button>
                        <?php endif; ?>
                        <button onclick="deleteOne(<?= (int)$kw['id'] ?>)" title="Delete this keyword permanently" style="background:transparent;border:none;color:#dc2626;cursor:pointer;font-size:14px;padding:2px 4px;">✕</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
const KW_API = '<?= url('/api/keywords-manage.php') ?>';
const SITE_ID = <?= $filter_site ? (int)$filter_site : 'null' ?>;

function toggleAll(cb) {
    document.querySelectorAll('.kw-check').forEach(c => c.checked = cb.checked);
    updateSelectedCount();
}
function updateSelectedCount() {
    const n = document.querySelectorAll('.kw-check:checked').length;
    document.getElementById('kw-selected-count').textContent = n;
    ['kw-delete-btn','kw-ignore-btn','kw-restore-btn'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = n === 0;
    });
}
function selectedIds() {
    return Array.from(document.querySelectorAll('.kw-check:checked')).map(c => parseInt(c.dataset.id));
}
async function call(body) {
    try {
        const res = await fetch(KW_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
        const data = await res.json();
        if (data.success) location.reload();
        else alert('Failed: ' + (data.error || 'unknown'));
    } catch(e) { alert('Error: ' + e.message); }
}

async function addKeywords() {
    if (!SITE_ID) return;
    const input = document.getElementById('add-keyword-input');
    const raw = input.value.trim();
    if (!raw) return;
    const keywords = raw.split(/[,\n]/).map(s => s.trim()).filter(Boolean);
    if (!keywords.length) return;
    const msg = document.getElementById('add-msg');
    msg.innerHTML = '<span style="color:#64748b;">Adding...</span>';
    try {
        const res = await fetch(KW_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'add', site_id: SITE_ID, keywords})});
        const data = await res.json();
        if (data.success) {
            msg.innerHTML = '<span style="color:#065f46;">✓ Added ' + data.added + ', already existed: ' + data.skipped + '</span>';
            input.value = '';
            setTimeout(() => location.reload(), 600);
        } else {
            msg.innerHTML = '<span style="color:#dc2626;">' + (data.error || 'Failed') + '</span>';
        }
    } catch(e) { msg.innerHTML = '<span style="color:#dc2626;">Error: ' + e.message + '</span>'; }
}

function ignoreOne(id) {
    const reason = prompt('Why are you ignoring this keyword? (optional — e.g. "wrong brand", "irrelevant")', '');
    if (reason === null) return; // cancelled
    call({action:'ignore', ids:[id], reason});
}
function restoreOne(id) { call({action:'restore', ids:[id]}); }
function deleteOne(id)  { if (confirm('Delete this keyword permanently?')) call({action:'delete', ids:[id]}); }

function bulkIgnore() {
    const ids = selectedIds(); if (!ids.length) return;
    const reason = prompt('Reason for ignoring ' + ids.length + ' keywords? (optional)', '');
    if (reason === null) return;
    call({action:'ignore', ids, reason});
}
function bulkRestore() {
    const ids = selectedIds(); if (!ids.length) return;
    if (!confirm('Restore ' + ids.length + ' keyword' + (ids.length>1?'s':'') + '?')) return;
    call({action:'restore', ids});
}
function bulkDelete() {
    const ids = selectedIds(); if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' keyword' + (ids.length>1?'s':'') + ' permanently?')) return;
    call({action:'delete', ids});
}
function deleteAll(siteId) {
    if (!confirm('Delete all AI-estimate keywords for this site?\n\nManual entries (purple) and Google-synced data (green) will be preserved.')) return;
    call({action:'delete_all', site_id: siteId});
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
