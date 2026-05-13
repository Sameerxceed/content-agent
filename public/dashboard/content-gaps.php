<?php
/**
 * Dashboard — Content Gaps (Phase 3)
 *
 * Shows topics that competitors cover but this site doesn't yet.
 * Customer clicks "Write a post" → opens AI Writer pre-filled with the gap topic.
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

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$site_id, $user_id]);
$site = $stmt->fetch();
if (!$site) { redirect('/dashboard/index.php'); }

$filter_status = $_GET['status'] ?? 'open';

// Counts
$cnt = ['open' => 0, 'planned' => 0, 'published' => 0, 'ignored' => 0];
try {
    $stmt = $db->prepare('SELECT status, COUNT(*) c FROM content_gaps WHERE site_id = ? GROUP BY status');
    $stmt->execute([$site_id]);
    foreach ($stmt->fetchAll() as $r) $cnt[$r['status']] = (int)$r['c'];
} catch (PDOException $e) {
    // tables not yet migrated
}

// List
$gaps = [];
try {
    $stmt = $db->prepare("SELECT * FROM content_gaps WHERE site_id = ? AND status = ? ORDER BY competitor_count DESC, estimated_demand DESC LIMIT 100");
    $stmt->execute([$site_id, $filter_status]);
    $gaps = $stmt->fetchAll();
} catch (PDOException $e) {}

// Competitor count for the site (active)
$stmt = $db->prepare("SELECT COUNT(*) FROM competitors WHERE site_id = ? AND status = 'active'");
$stmt->execute([$site_id]);
$active_competitors = (int)$stmt->fetchColumn();

// Latest run
$latest_run = null;
try {
    $stmt = $db->prepare('SELECT * FROM gap_runs WHERE site_id = ? ORDER BY started_at DESC LIMIT 1');
    $stmt->execute([$site_id]);
    $latest_run = $stmt->fetch() ?: null;
} catch (PDOException $e) {}

// All competitors for label lookup (for sample titles)
$comp_lookup = [];
$stmt = $db->prepare('SELECT id, domain FROM competitors WHERE site_id = ?');
$stmt->execute([$site_id]);
foreach ($stmt->fetchAll() as $c) $comp_lookup[(int)$c['id']] = $c['domain'];

$page_title = 'Content Gaps — ' . $site['name'];
ob_start();
?>

<style>
.tabs { display:flex; gap:4px; border-bottom:1px solid var(--border); margin-bottom:14px; }
.tabs a { padding:8px 14px; text-decoration:none; font-size:13px; color:#64748b; border-bottom:2px solid transparent; font-weight:500; }
.tabs a.active { color:var(--accent); border-bottom-color:var(--accent); font-weight:600; }
.tabs a span.count { font-size:11px; color:#94a3b8; }

.gap-card { background:#fff; border:1px solid var(--border); border-radius:8px; padding:12px 14px; margin-bottom:8px; }
.gap-card .head { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.gap-card .topic { font-weight:600; font-size:14px; color:var(--primary); }
.gap-card .meta { font-size:12px; color:#64748b; margin-top:4px; }
.gap-card .meta strong { color:#475569; }
.gap-card .demand { font-size:11px; color:#10b981; font-weight:600; }
.gap-card .titles { font-size:11px; color:#94a3b8; margin-top:6px; line-height:1.5; }
.gap-card .actions { display:flex; gap:6px; margin-top:10px; flex-wrap:wrap; }
.gap-card .actions .btn-write { background:var(--accent); color:#fff; padding:4px 12px; border:none; border-radius:4px; font-size:11px; cursor:pointer; text-decoration:none; font-weight:600; }
.gap-card .actions .btn-second { background:transparent; border:1px solid var(--border); color:#64748b; padding:4px 10px; border-radius:4px; font-size:11px; cursor:pointer; }
.gap-card .actions .btn-second.ignore { color:#f59e0b; border-color:#fed7aa; }

.run-banner { background:#dbeafe; border:1px solid #93c5fd; border-radius:8px; padding:10px 14px; margin-bottom:14px; }
.run-banner.success { background:#d1fae5; border-color:#86efac; }
.run-banner.error { background:#fecaca; border-color:#f87171; }
.run-banner .progress-bar { height:6px; background:rgba(0,0,0,0.1); border-radius:3px; overflow:hidden; margin-top:6px; }
.run-banner .progress-fill { height:100%; background:#3b82f6; transition:width 0.3s; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site['name']) ?></a>
</div>

<div style="text-align:center;margin-bottom:14px;">
    <h2 style="font-size:20px;color:var(--primary);margin-bottom:4px;">Content Gaps — <?= e($site['name']) ?></h2>
    <p style="font-size:13px;color:#64748b;">Topics your competitors cover but you haven't written about yet.</p>
</div>

<!-- Status banner for in-progress runs -->
<div id="run-banner-slot"></div>

<!-- Header: run analysis button -->
<div class="card" style="margin-bottom:14px;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
    <div style="font-size:12px;color:#64748b;">
        <strong><?= $active_competitors ?> active competitors</strong>
        · <strong><?= $cnt['open'] ?></strong> open gaps
        <?php if ($latest_run): ?>
            · Last analysis: <?= format_date($latest_run['started_at']) ?>
            (<?= $latest_run['gaps_found'] ?> gaps from <?= $latest_run['pages_scanned'] ?> pages)
        <?php endif; ?>
    </div>
    <div>
        <button onclick="runGapAnalysis()" id="run-btn" class="btn btn-accent btn-sm" <?= $active_competitors < 1 ? 'disabled' : '' ?>>
            🔍 <?= $latest_run ? 'Re-run Gap Analysis' : 'Run Gap Analysis' ?>
        </button>
        <?php if ($active_competitors < 1): ?>
            <span style="font-size:11px;color:#dc2626;margin-left:6px;">Need active competitors first</span>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <?php
    $tabs = [
        'open'      => ['Open', $cnt['open']],
        'planned'   => ['Planned', $cnt['planned']],
        'published' => ['Published', $cnt['published']],
        'ignored'   => ['Ignored', $cnt['ignored']],
    ];
    foreach ($tabs as $key => [$label, $count]):
        $is_active = $filter_status === $key;
    ?>
    <a href="<?= url('/dashboard/content-gaps.php?site=' . $site_id . '&status=' . $key) ?>" class="<?= $is_active ? 'active' : '' ?>"><?= $label ?> <span class="count">(<?= $count ?>)</span></a>
    <?php endforeach; ?>
</div>

<?php if (empty($gaps)): ?>
<div class="card" style="padding:24px;text-align:center;color:#94a3b8;font-size:13px;">
    <?php if ($filter_status === 'open'): ?>
        <?php if (array_sum($cnt) === 0): ?>
            No gaps detected yet. Click <strong>Run Gap Analysis</strong> above to scan your competitors' content.
        <?php else: ?>
            No open gaps. Check the Planned, Published, or Ignored tabs.
        <?php endif; ?>
    <?php else: ?>
        No <?= e($filter_status) ?> gaps.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php foreach ($gaps as $gap):
    $cids = json_decode($gap['competitor_ids'] ?? '[]', true) ?: [];
    $titles = json_decode($gap['sample_titles'] ?? '[]', true) ?: [];
    $covered_by = [];
    foreach ($cids as $cid) {
        if (isset($comp_lookup[$cid])) $covered_by[] = $comp_lookup[$cid];
    }
?>
<div class="gap-card" id="gap-<?= (int)$gap['id'] ?>">
    <div class="head">
        <div style="flex:1;min-width:0;">
            <div class="topic"><?= e($gap['topic']) ?></div>
            <div class="meta">
                <strong>Covered by <?= (int)$gap['competitor_count'] ?> competitor<?= $gap['competitor_count'] > 1 ? 's' : '' ?></strong>
                <?php if (!empty($covered_by)): ?>
                    : <?= e(implode(', ', array_slice($covered_by, 0, 4))) ?>
                <?php endif; ?>
                <?php if (!empty($gap['estimated_demand']) && $gap['estimated_demand'] > 0): ?>
                    · <span class="demand">~<?= number_format($gap['estimated_demand']) ?> imp/mo (from GSC overlap)</span>
                <?php endif; ?>
            </div>
            <?php if (!empty($titles)): ?>
            <div class="titles">Competitor titles: "<?= e(implode('" · "', array_slice($titles, 0, 3))) ?>"</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="actions">
        <?php if ($gap['status'] === 'open'): ?>
            <a href="<?= url('/dashboard/write.php?site=' . $site_id . '&gap_id=' . (int)$gap['id'] . '&step=write&topic=' . urlencode($gap['topic'])) ?>" class="btn-write">✍ Write a post →</a>
            <button onclick="setStatus(<?= (int)$gap['id'] ?>, 'planned')" class="btn-second">📌 Mark as planned</button>
            <button onclick="setStatus(<?= (int)$gap['id'] ?>, 'ignored')" class="btn-second ignore">👁 Ignore</button>
        <?php elseif ($gap['status'] === 'planned'): ?>
            <a href="<?= url('/dashboard/write.php?site=' . $site_id . '&gap_id=' . (int)$gap['id'] . '&step=write&topic=' . urlencode($gap['topic'])) ?>" class="btn-write">✍ Write now →</a>
            <button onclick="setStatus(<?= (int)$gap['id'] ?>, 'open')" class="btn-second">↺ Back to open</button>
        <?php elseif ($gap['status'] === 'ignored'): ?>
            <button onclick="setStatus(<?= (int)$gap['id'] ?>, 'open')" class="btn-second">↺ Restore</button>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<script>
const GAPS_RUN_API = '<?= url('/api/gaps-run.php') ?>';
const GAPS_STATUS_API = '<?= url('/api/gaps-status.php') ?>';
const GAPS_MANAGE_API = '<?= url('/api/gaps-manage.php') ?>';
const SITE_ID = <?= $site_id ?>;

let pollTimer = null;

async function runGapAnalysis() {
    const btn = document.getElementById('run-btn');
    btn.disabled = true;
    btn.textContent = '⏳ Starting...';
    try {
        const res = await fetch(GAPS_RUN_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({site_id: SITE_ID})});
        const data = await res.json();
        if (data.success) {
            startPolling();
        } else {
            alert('Failed: ' + (data.error || 'unknown'));
            btn.disabled = false;
            btn.textContent = '🔍 Run Gap Analysis';
        }
    } catch(e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
    }
}

function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(checkStatus, 3000);
    checkStatus();
}

async function checkStatus() {
    try {
        const res = await fetch(GAPS_STATUS_API + '?site_id=' + SITE_ID);
        const data = await res.json();
        if (!data.success || !data.run) return;
        renderRunBanner(data.run);
        if (data.run.status === 'done' || data.run.status === 'failed') {
            clearInterval(pollTimer); pollTimer = null;
            if (data.run.status === 'done') {
                setTimeout(() => location.reload(), 1200);
            }
        }
    } catch(e) {}
}

function renderRunBanner(run) {
    const slot = document.getElementById('run-banner-slot');
    let cls = 'run-banner';
    let icon = '⏳';
    if (run.status === 'done') { cls += ' success'; icon = '✓'; }
    if (run.status === 'failed') { cls += ' error'; icon = '✗'; }
    slot.innerHTML =
        '<div class="' + cls + '">' +
            '<div style="font-size:13px;font-weight:600;">' + icon + ' ' + escapeHtml(run.current_step || run.status) + '</div>' +
            (run.status === 'failed' && run.error
                ? '<div style="font-size:12px;margin-top:4px;color:#991b1b;">' + escapeHtml(run.error) + '</div>'
                : '<div style="font-size:11px;color:#64748b;margin-top:2px;">'
                    + 'Competitors: ' + run.competitors_scanned + ' · Pages scanned: ' + run.pages_scanned
                    + (run.gaps_found ? ' · Gaps found: ' + run.gaps_found : '')
                    + '</div>') +
            (run.status === 'running' || run.status === 'queued'
                ? '<div class="progress-bar"><div class="progress-fill" style="width:' + run.progress + '%;"></div></div>'
                : '') +
        '</div>';
}

async function setStatus(id, status) {
    try {
        const res = await fetch(GAPS_MANAGE_API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'update_status', id, status})});
        const data = await res.json();
        if (data.success) location.reload();
        else alert('Failed: ' + (data.error || 'unknown'));
    } catch(e) { alert('Error: ' + e.message); }
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
}

// Auto-resume polling if there's a running job
<?php if ($latest_run && in_array($latest_run['status'], ['queued', 'running'], true)): ?>
startPolling();
<?php endif; ?>
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
