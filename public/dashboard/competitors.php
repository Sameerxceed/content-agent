<?php
/**
 * Dashboard — Competitors (Phase 1: Discovery)
 *
 * Shows auto-detected and manually-added competitors for a site.
 * Lets the user discover, ignore, add manually, and drill into shared keywords.
 *
 * GET ?site=1
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { redirect('/dashboard/index.php'); }

$filter_status = $_GET['status'] ?? 'active'; // active | ignored | all

// Counts per status
$cnt = ['active' => 0, 'ignored' => 0, 'manual' => 0, 'all' => 0];
$stmt = $db->prepare('SELECT status, source, COUNT(*) c FROM competitors WHERE site_id = ? GROUP BY status, source');
$stmt->execute([$site_id]);
foreach ($stmt->fetchAll() as $r) {
    $cnt[$r['status']] = ($cnt[$r['status']] ?? 0) + (int)$r['c'];
    $cnt['all'] += (int)$r['c'];
    if ($r['source'] === 'manual') $cnt['manual'] += (int)$r['c'];
}

// Build list
$where = ['site_id = ?'];
$params = [$site_id];
if ($filter_status === 'active')  { $where[] = "status = 'active'"; }
elseif ($filter_status === 'ignored') { $where[] = "status = 'ignored'"; }
elseif ($filter_status === 'manual')  { $where[] = "source = 'manual'"; }

$where_sql = implode(' AND ', $where);
$stmt = $db->prepare("SELECT * FROM competitors WHERE {$where_sql} ORDER BY shared_keywords DESC, domain ASC LIMIT 100");
$stmt->execute($params);
$competitors = $stmt->fetchAll();

// Total active keyword count (for percentage display)
$stmt = $db->prepare("SELECT COUNT(*) FROM keywords WHERE site_id = ? AND status = 'active'");
$stmt->execute([$site_id]);
$total_active_kw = (int)$stmt->fetchColumn();

// Last discovery time
$stmt = $db->prepare('SELECT MAX(last_analysed_at) FROM competitors WHERE site_id = ? AND source = "auto"');
$stmt->execute([$site_id]);
$last_discovery = $stmt->fetchColumn();

// Settings status — show prompt to setup CSE if not configured
$cse_configured = !empty(config('google_cse_api_key')) && !empty(config('google_cse_cx'));

$page_title = 'Competitors — ' . $site['name'];
ob_start();
?>

<style>
.tabs { display:flex; gap:4px; border-bottom:1px solid var(--border); margin-bottom:14px; }
.tabs a { padding:8px 14px; text-decoration:none; font-size:13px; color:#64748b; border-bottom:2px solid transparent; font-weight:500; }
.tabs a.active { color:var(--accent); border-bottom-color:var(--accent); font-weight:600; }
.tabs a span.count { font-size:11px; color:#94a3b8; }

.comp-card { background:#fff; border:1px solid var(--border); border-radius:8px; padding:12px 14px; margin-bottom:8px; }
.comp-card.ignored { opacity:0.6; }
.comp-card .head { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.comp-card .domain { font-weight:600; font-size:14px; color:var(--primary); }
.comp-card .source-badge { font-size:10px; font-weight:600; padding:2px 8px; border-radius:10px; }
.comp-card .source-auto { background:#dbeafe; color:#1e40af; }
.comp-card .source-manual { background:#ede9fe; color:#7c3aed; }
.comp-card .overlap { font-size:12px; color:#64748b; margin-top:4px; }
.comp-card .keywords-preview { font-size:11px; color:#94a3b8; margin-top:6px; line-height:1.5; }
.comp-card .actions { display:flex; gap:6px; margin-top:8px; }
.comp-card .actions button, .comp-card .actions a {
    background:transparent; border:1px solid var(--border); color:#64748b;
    padding:3px 10px; border-radius:4px; font-size:11px; cursor:pointer; text-decoration:none;
}
.comp-card .actions button:hover, .comp-card .actions a:hover { background:#f8fafc; color:var(--primary); }
.comp-card .actions .ignore-btn { color:#f59e0b; border-color:#fed7aa; }
.comp-card .actions .restore-btn { color:#10b981; border-color:#86efac; }
.comp-card .actions .delete-btn { color:#dc2626; border-color:#fecaca; }

.kw-drawer { display:none; padding:10px 14px; background:#f8fafc; border-top:1px solid var(--border); }
.kw-drawer.open { display:block; }
.kw-drawer table { width:100%; font-size:12px; }
.kw-drawer th { text-align:left; font-weight:600; padding:4px 8px; color:#64748b; }
.kw-drawer td { padding:4px 8px; border-bottom:1px solid #f1f5f9; }
.kw-drawer .pos-good { color:#10b981; font-weight:600; }
.kw-drawer .pos-mid { color:#f59e0b; font-weight:600; }
.kw-drawer .pos-bad { color:#dc2626; font-weight:600; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site['name']) ?></a>
</div>

<div style="text-align:center;margin-bottom:14px;">
    <h2 style="font-size:20px;color:var(--primary);margin-bottom:4px;">Competitors — <?= e($site['name']) ?></h2>
    <p style="font-size:13px;color:#64748b;">Sites that rank on Google for the same keywords as you.</p>
</div>

<?php if (!$cse_configured): ?>
<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px 16px;margin-bottom:14px;">
    <div style="font-weight:600;color:#92400e;margin-bottom:4px;">⚠ Google Custom Search not configured</div>
    <div style="font-size:12px;color:#92400e;">Competitor discovery uses Google Custom Search Engine (free, 100 queries/day) to find who else ranks for your keywords. <a href="<?= url('/dashboard/settings.php?tab=api') ?>" style="color:#92400e;font-weight:600;">Set it up in Settings →</a></div>
</div>
<?php endif; ?>

<!-- Header strip with action buttons -->
<div class="card" style="margin-bottom:14px;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
    <div style="font-size:12px;color:#64748b;">
        <strong><?= $cnt['active'] ?> active</strong>
        · <?= $cnt['ignored'] ?> ignored
        · <?= $total_active_kw ?> keywords analysed
        <?php if ($last_discovery): ?>
            · Last discovery: <?= format_date($last_discovery) ?>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;">
        <button onclick="discoverCompetitors()" id="discover-btn" class="btn btn-accent btn-sm" <?= !$cse_configured ? 'disabled' : '' ?>>
            🔍 Discover Competitors
        </button>
        <button onclick="document.getElementById('add-form').style.display = document.getElementById('add-form').style.display === 'none' ? 'block' : 'none';" class="btn btn-outline btn-sm">+ Add Manually</button>
    </div>
</div>

<!-- Add manually form -->
<div id="add-form" class="card" style="margin-bottom:14px;padding:12px 14px;display:none;">
    <div style="font-weight:600;font-size:13px;margin-bottom:6px;">Add a competitor</div>
    <div style="font-size:11px;color:#64748b;margin-bottom:8px;">Type one or more domains (e.g. <strong>hotjar.com, segment.com</strong>) — comma-separated.</div>
    <div style="display:flex;gap:6px;">
        <input type="text" id="add-domains" class="form-control" placeholder="competitor1.com, competitor2.com" style="font-size:13px;flex:1;" onkeydown="if(event.key==='Enter'){event.preventDefault();addCompetitors();}">
        <button onclick="addCompetitors()" class="btn btn-accent" style="font-size:12px;white-space:nowrap;">Add</button>
    </div>
    <div id="add-msg" style="font-size:11px;margin-top:6px;"></div>
</div>

<!-- Tabs -->
<div class="tabs">
    <?php
    $tab_items = [
        'active'  => ['Active', $cnt['active']],
        'ignored' => ['Ignored', $cnt['ignored']],
        'manual'  => ['Manual only', $cnt['manual']],
        'all'     => ['All', $cnt['all']],
    ];
    foreach ($tab_items as $key => [$label, $count]):
        $is_active = $filter_status === $key;
    ?>
    <a href="<?= url('/dashboard/competitors.php?site=' . $site_id . '&status=' . $key) ?>" class="<?= $is_active ? 'active' : '' ?>"><?= $label ?> <span class="count">(<?= $count ?>)</span></a>
    <?php endforeach; ?>
</div>

<!-- Competitor list -->
<?php if (empty($competitors)): ?>
<div class="card" style="padding:24px;text-align:center;color:#94a3b8;font-size:13px;">
    <?php if ($filter_status === 'ignored'): ?>
        No ignored competitors.
    <?php elseif ($filter_status === 'manual'): ?>
        No manually-added competitors yet. Click "+ Add Manually" above.
    <?php elseif ($cnt['all'] === 0): ?>
        No competitors yet. Click <strong>🔍 Discover Competitors</strong> above to find them automatically.
    <?php else: ?>
        No active competitors. Check the Ignored tab or run Discover again.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php foreach ($competitors as $comp):
    $is_ignored = $comp['status'] === 'ignored';
    $src_class = $comp['source'] === 'manual' ? 'source-manual' : 'source-auto';

    // Get sample keywords for this competitor
    $stmt = $db->prepare('SELECT k.keyword, ckr.position FROM competitor_keyword_rankings ckr JOIN keywords k ON ckr.keyword_id = k.id WHERE ckr.competitor_id = ? ORDER BY ckr.position ASC LIMIT 5');
    $stmt->execute([$comp['id']]);
    $sample_kws = $stmt->fetchAll();
?>
<div class="comp-card <?= $is_ignored ? 'ignored' : '' ?>" id="comp-<?= (int)$comp['id'] ?>">
    <div class="head">
        <div style="flex:1;min-width:0;">
            <div>
                <a href="https://<?= e($comp['domain']) ?>" target="_blank" class="domain" style="text-decoration:none;"><?= e($comp['domain']) ?> ↗</a>
                <span class="source-badge <?= $src_class ?>"><?= $comp['source'] === 'manual' ? 'Manual' : 'Auto' ?></span>
                <?php if ($is_ignored): ?>
                    <span class="source-badge" style="background:#f1f5f9;color:#64748b;">Ignored</span>
                <?php endif; ?>
            </div>
            <?php if ($comp['shared_keywords'] > 0): ?>
            <div class="overlap">
                <strong><?= $comp['shared_keywords'] ?>/<?= max($total_active_kw, $comp['shared_keywords']) ?> keywords</strong>
                <?php if ($comp['overlap_score'] > 0): ?>
                    · <?= $comp['overlap_score'] ?>% overlap
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="overlap"><em>No shared keywords yet. Run Discover to analyse.</em></div>
            <?php endif; ?>
            <?php if (!empty($sample_kws)): ?>
            <div class="keywords-preview">
                Sample: <?= implode(' · ', array_map(fn($k) => '<span>' . e($k['keyword']) . ' (#' . (int)$k['position'] . ')</span>', $sample_kws)) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="actions">
        <?php if ($comp['shared_keywords'] > 0): ?>
            <button onclick="toggleDrawer(<?= (int)$comp['id'] ?>)">View shared keywords ▾</button>
        <?php endif; ?>
        <?php if (!$is_ignored && !empty(config('dataforseo_login'))): ?>
            <button onclick="importCompetitorKeywords(<?= (int)$comp['id'] ?>, this)" title="Pull every keyword this competitor ranks for on Google (DataForSEO) and add them as target keywords for your site.">⬇ Import their keywords</button>
        <?php endif; ?>
        <?php if ($is_ignored): ?>
            <button onclick="restoreOne(<?= (int)$comp['id'] ?>)" class="restore-btn">↺ Restore</button>
        <?php else: ?>
            <button onclick="ignoreOne(<?= (int)$comp['id'] ?>)" class="ignore-btn">👁 Ignore</button>
        <?php endif; ?>
        <button onclick="deleteOne(<?= (int)$comp['id'] ?>)" class="delete-btn">✕ Delete</button>
    </div>
    <div class="kw-drawer" id="drawer-<?= (int)$comp['id'] ?>">
        <?php
        $stmt = $db->prepare('SELECT k.keyword, k.impressions, k.gsc_position, ckr.position as their_position, ckr.url FROM competitor_keyword_rankings ckr JOIN keywords k ON ckr.keyword_id = k.id WHERE ckr.competitor_id = ? ORDER BY ckr.position ASC');
        $stmt->execute([$comp['id']]);
        $all_kws = $stmt->fetchAll();
        if (!empty($all_kws)):
        ?>
        <table>
            <thead>
                <tr>
                    <th>Keyword</th>
                    <th>Their position</th>
                    <th>Your position</th>
                    <th>Your impressions</th>
                    <th>Their ranking URL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_kws as $kw):
                    $their = (int)$kw['their_position'];
                    $pc = $their <= 3 ? 'pos-good' : ($their <= 7 ? 'pos-mid' : 'pos-bad');
                    $yours = $kw['gsc_position'] ?? null;
                ?>
                <tr>
                    <td><?= e($kw['keyword']) ?></td>
                    <td><span class="<?= $pc ?>">#<?= $their ?></span></td>
                    <td><?= $yours !== null ? '#' . (int)round($yours) : '—' ?></td>
                    <td><?= $kw['impressions'] !== null ? number_format($kw['impressions']) : '—' ?></td>
                    <td><a href="<?= e($kw['url']) ?>" target="_blank" style="font-size:11px;color:var(--primary);text-decoration:none;"><?= e(parse_url($kw['url'], PHP_URL_PATH) ?: '/') ?> ↗</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<script>
const DISCOVER_API = '<?= url('/api/competitors-discover.php') ?>';
const MANAGE_API = '<?= url('/api/competitors-manage.php') ?>';
const SITE_ID = <?= $site_id ?>;

function toggleDrawer(id) {
    const el = document.getElementById('drawer-' + id);
    if (el) el.classList.toggle('open');
}

async function discoverCompetitors() {
    const btn = document.getElementById('discover-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="display:inline-block;width:12px;height:12px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin 0.6s linear infinite;"></span> Analysing your keywords...';
    try {
        const res = await fetch(DISCOVER_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({site_id: SITE_ID})});
        const data = await res.json();
        if (data.success) {
            // If the CSE itself returned errors for every call, the response
            // carries a real reason (quota / API disabled / bad key). Without
            // this, the user just sees "0 found" and has no clue what's wrong.
            if (data.error_headline) {
                var msg = data.error_headline;
                if (data.fix_hint) msg += '\n\nFix: ' + data.fix_hint;
                alert(msg);
                btn.disabled = false;
                btn.innerHTML = '🔍 Discover Competitors';
                return;
            }
            alert('Discovered ' + data.competitors_found + ' competitors from ' + data.keywords_analysed + ' keywords (' + data.cse_calls + ' searches used).');
            location.reload();
        } else {
            alert('Failed: ' + (data.error || 'unknown'));
            btn.disabled = false;
            btn.innerHTML = '🔍 Discover Competitors';
        }
    } catch(e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '🔍 Discover Competitors';
    }
}

async function addCompetitors() {
    const input = document.getElementById('add-domains');
    const raw = input.value.trim();
    if (!raw) return;
    const domains = raw.split(/[,\n]/).map(s => s.trim()).filter(Boolean);
    if (!domains.length) return;
    const msg = document.getElementById('add-msg');
    msg.innerHTML = '<span style="color:#64748b;">Adding...</span>';
    try {
        const res = await fetch(MANAGE_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'add', site_id: SITE_ID, domains})});
        const data = await res.json();
        if (data.success) {
            msg.innerHTML = '<span style="color:#065f46;">✓ Added ' + data.added + ', already existed: ' + data.existing + '</span>';
            input.value = '';
            setTimeout(() => location.reload(), 600);
        } else {
            msg.innerHTML = '<span style="color:#dc2626;">' + (data.error || 'Failed') + '</span>';
        }
    } catch(e) {
        msg.innerHTML = '<span style="color:#dc2626;">Error: ' + e.message + '</span>';
    }
}

async function call(body) {
    try {
        const res = await fetch(MANAGE_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
        const data = await res.json();
        if (data.success) location.reload();
        else alert('Failed: ' + (data.error || 'unknown'));
    } catch(e) { alert('Error: ' + e.message); }
}
function ignoreOne(id)  { if (confirm('Ignore this competitor? AI will skip them in analyses. You can restore later.')) call({action:'ignore', ids:[id]}); }
function restoreOne(id) { call({action:'restore', ids:[id]}); }
function deleteOne(id)  { if (confirm('Delete this competitor permanently? Their shared keyword data will also be removed.')) call({action:'delete', ids:[id]}); }

async function importCompetitorKeywords(id, btn) {
    if (!confirm('Pull every keyword this competitor ranks for on Google (top 100) and add them as YOUR target keywords?\n\nCost: ~$0.05 (DataForSEO charges ~$0.0001 per keyword × ~500).')) return;
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Importing…';
    try {
        const res = await fetch('<?= url('/api/competitor-keywords-import.php') ?>', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ site_id: SITE_ID, competitor_id: id })
        });
        const data = await res.json();
        if (data.success) {
            alert('Done.\n\nCompetitor: ' + data.competitor + '\nKeywords returned by DataForSEO: ' + data.returned + '\nImported as new targets: ' + data.imported + '\nSkipped (already tracked): ' + data.skipped);
            location.reload();
        } else {
            alert('Failed: ' + (data.error || 'unknown'));
            btn.disabled = false; btn.textContent = orig;
        }
    } catch(e) { alert('Error: ' + e.message); btn.disabled = false; btn.textContent = orig; }
}
</script>

<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
