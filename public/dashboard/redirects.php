<?php
/**
 * Dashboard — 301 Redirect Map.
 *
 * Single page covering: crawl live site → build map from dead historical URLs
 * → review queue with confidence buckets → export buttons per platform.
 *
 * Customer copy avoids "Wayback" and "Claude" — those are implementation
 * details (feedback_no_vendor_leaks.md).
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/redirect_map_builder.php';
require_once __DIR__ . '/../../includes/site_crawler.php';
require_once __DIR__ . '/../../includes/wayback_harvester.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

$summary  = rmb_site_summary($db, $site_id);
$crawl    = sc_site_summary($db, $site_id);
$archive  = wayback_site_summary($db, $site_id);

$filter = $_GET['filter'] ?? 'all';
$valid_filters = ['all', 'pending', 'approved', 'rejected', 'no_target', 'high', 'medium', 'low'];
if (!in_array($filter, $valid_filters, true)) $filter = 'all';

$where = 'site_id = ?'; $args = [$site_id];
$filter_sql = [
    'pending'   => " AND status = 'pending'",
    'approved'  => " AND status IN ('approved','applied')",
    'rejected'  => " AND status = 'rejected'",
    'no_target' => " AND to_path IS NULL",
    'high'      => " AND confidence >= 85",
    'medium'    => " AND confidence BETWEEN 60 AND 84",
    'low'      => " AND (confidence < 60 OR confidence IS NULL)",
];
if (isset($filter_sql[$filter])) $where .= $filter_sql[$filter];

$stmt = $db->prepare("SELECT id, from_path, to_path, confidence, match_method, reasoning, status, auto_approved
                      FROM redirect_map WHERE {$where} ORDER BY confidence DESC, id LIMIT 200");
$stmt->execute($args);
$redirects = $stmt->fetchAll();

// Inventory of live URL paths — drives the autocomplete datalist on every
// editable to_path field so the user can't misspell into a 404.
$stmt = $db->prepare("SELECT path FROM current_site_urls WHERE site_id = ? ORDER BY url_type, path");
$stmt->execute([$site_id]);
$live_paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

$platform = $site['platform'] ?? 'custom';

$page_title = '301 Redirects — ' . $site['name'];
ob_start();
?>
<style>
.rd-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-bottom:14px; }
.rd-card { background:#fff; border:1px solid var(--border); border-radius:6px; padding:13px 14px; }
.rd-card .label { font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-light); margin-bottom:4px; }
.rd-card .num { font-size:26px; font-weight:700; color:var(--primary); line-height:1; }
.rd-card .num.good { color:#059669; }
.rd-card .num.warn { color:#d97706; }
.rd-card .num.bad  { color:#dc2626; }
.rd-card .sub { font-size:11px; color:var(--text-light); margin-top:5px; }
.rd-pills { display:flex; gap:0; border-bottom:1px solid var(--border); margin:14px 0 10px; flex-wrap:wrap; }
.rd-pill { padding:7px 14px; font-size:12px; color:var(--text-light); border-bottom:2px solid transparent; text-decoration:none; }
.rd-pill.active { color:var(--primary); border-bottom-color:var(--primary); font-weight:600; }
.rd-row { background:#fff; border:1px solid var(--border); border-radius:6px; padding:10px 14px; margin-bottom:6px; display:grid; grid-template-columns: 1fr auto auto; gap:14px; align-items:center; }
.rd-row.high { border-left:3px solid #059669; }
.rd-row.med  { border-left:3px solid #d97706; }
.rd-row.low  { border-left:3px solid #dc2626; }
.rd-row.notarget { border-left:3px solid #94a3b8; background:#f8fafc; }
.rd-paths { font-family:ui-monospace, monospace; font-size:12px; line-height:1.5; }
.rd-from { color:#dc2626; }
.rd-arrow { color:var(--text-light); margin:0 6px; }
.rd-to   { color:#059669; }
.rd-to.editable { color:#0f172a; }
.rd-to-input { width:240px; padding:3px 8px; font-family:ui-monospace, monospace; font-size:12px; color:#059669; border:1px solid transparent; background:transparent; border-radius:4px; }
.rd-to-input:hover, .rd-to-input:focus { border-color:var(--border); background:#fff; color:#0f172a; outline:none; }
.rd-to-input.dirty { color:#0f172a; border-color:#fbbf24; }
.rd-to-input.saved { background:#d1fae5; }
.rd-meta { font-size:10px; color:var(--text-light); margin-top:2px; }
.rd-confidence { font-size:11px; padding:2px 9px; border-radius:10px; font-weight:600; white-space:nowrap; }
.rd-confidence.high { background:#d1fae5; color:#065f46; }
.rd-confidence.med  { background:#fef3c7; color:#92400e; }
.rd-confidence.low  { background:#fee2e2; color:#991b1b; }
.rd-confidence.none { background:#f1f5f9; color:#475569; }
.rd-actions { display:flex; gap:4px; }
.rd-actions button { font-size:11px; padding:3px 8px; }
.rd-status { font-size:10px; padding:2px 8px; border-radius:10px; }
.rd-status.pending  { background:#fef3c7; color:#92400e; }
.rd-status.approved { background:#d1fae5; color:#065f46; }
.rd-status.applied  { background:#bfdbfe; color:#1e40af; }
.rd-status.rejected { background:#f1f5f9; color:#64748b; }
#rd-progress { display:none; font-size:12px; color:var(--text-light); margin-top:8px; padding:8px 10px; background:#f8fafc; border-radius:4px; border:1px dashed var(--border); }
.rd-empty { color:var(--text-light); font-size:13px; padding:14px; background:#f8fafc; border-radius:6px; border:1px dashed var(--border); }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/wayback.php?site=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">← Archive history</a>
</div>

<!-- Native browser autocomplete: every editable to_path uses list="live-paths" -->
<datalist id="live-paths">
    <?php foreach ($live_paths as $p): ?>
        <option value="<?= e($p) ?>"></option>
    <?php endforeach; ?>
</datalist>

<div class="setup-section" style="max-width:980px;">
    <h3 style="margin:0 0 3px; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--primary);">301 redirect map</h3>
    <p class="desc" style="margin:0 0 8px; max-width:720px;">
        For each dead URL we found in your archive, ContentAgent picks the best living target on
        <strong><?= e($site['domain']) ?></strong> and proposes a 301 redirect. High-confidence
        matches auto-approve; lower-confidence go to the review queue below.
    </p>
    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
        <button class="btn btn-outline btn-sm" onclick="crawl(this)" title="Discover current URLs on the live site (sitemap-first)">
            ↻ Crawl live site
            <?php if ($crawl['total']): ?><span style="font-size:10px; color:var(--text-light);"> · <?= number_format($crawl['total']) ?> known</span><?php endif; ?>
        </button>
        <button class="btn btn-accent btn-sm" onclick="buildMap(this)" <?= $crawl['total'] === 0 ? 'disabled title="Crawl first to build the live URL inventory"' : '' ?>>
            ⚡ Build redirect map
            <?php if ($archive['dead_urls']): ?><span style="font-size:10px;"> · <?= number_format($archive['dead_urls']) ?> dead URLs queued</span><?php endif; ?>
        </button>
        <?php if (($summary['by_status']['pending'] ?? 0) > 0): ?>
            <button class="btn btn-outline btn-sm" onclick="approveAllPending(this)" title="Approve every pending redirect that has a target (skips no-target rows)">
                ✓ Approve all pending (<?= (int)($summary['by_status']['pending'] ?? 0) ?>)
            </button>
        <?php endif; ?>
        <?php if ($summary['by_status']['approved'] ?? 0): ?>
            <?php if ($platform === 'shopify'): ?>
                <button class="btn btn-primary btn-sm" onclick="applyShopify(this)" title="Push approved redirects directly via Shopify Admin API">
                    ⚡ Apply <?= (int)($summary['by_status']['approved'] ?? 0) ?> to Shopify
                </button>
                <a class="btn btn-outline btn-sm" href="<?= url('/api/redirect-action.php?action=export_csv&site_id=' . $site_id) ?>">↓ CSV backup</a>
            <?php else: ?>
                <a class="btn btn-primary btn-sm" href="<?= url('/api/redirect-action.php?action=export_next_config&site_id=' . $site_id) ?>">↓ next.config.js</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div id="rd-progress"></div>
</div>

<div class="rd-stats" style="max-width:980px;">
    <div class="rd-card">
        <div class="label">Live URLs known</div>
        <div class="num"><?= number_format($crawl['total']) ?></div>
        <div class="sub"><?= $crawl['last'] ? 'Last crawl ' . date('d M H:i', strtotime($crawl['last'])) : 'Not yet crawled' ?></div>
    </div>
    <div class="rd-card">
        <div class="label">Dead URLs to map</div>
        <div class="num bad"><?= number_format($archive['dead_urls']) ?></div>
        <div class="sub">From archive history</div>
    </div>
    <div class="rd-card">
        <div class="label">High-confidence</div>
        <div class="num good"><?= number_format($summary['high']) ?></div>
        <div class="sub">Auto-approved (≥85%)</div>
    </div>
    <div class="rd-card">
        <div class="label">Need review</div>
        <div class="num warn"><?= number_format($summary['medium']) ?></div>
        <div class="sub">Medium confidence (60-84%)</div>
    </div>
    <div class="rd-card">
        <div class="label">Low / no target</div>
        <div class="num bad"><?= number_format($summary['low']) ?></div>
        <div class="sub">Decide manually or 410</div>
    </div>
</div>

<div class="rd-pills" style="max-width:980px;">
    <a class="rd-pill <?= $filter === 'all'       ? 'active' : '' ?>" href="?site=<?= $site_id ?>">All (<?= $summary['total'] ?>)</a>
    <a class="rd-pill <?= $filter === 'pending'   ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=pending">Pending (<?= (int)($summary['by_status']['pending'] ?? 0) ?>)</a>
    <a class="rd-pill <?= $filter === 'approved'  ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=approved">Approved (<?= (int)($summary['by_status']['approved'] ?? 0) + (int)($summary['by_status']['applied'] ?? 0) ?>)</a>
    <a class="rd-pill <?= $filter === 'high'      ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=high">High conf (<?= $summary['high'] ?>)</a>
    <a class="rd-pill <?= $filter === 'medium'    ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=medium">Medium (<?= $summary['medium'] ?>)</a>
    <a class="rd-pill <?= $filter === 'low'       ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=low">Low (<?= $summary['low'] ?>)</a>
    <a class="rd-pill <?= $filter === 'no_target' ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=no_target">No target</a>
    <a class="rd-pill <?= $filter === 'rejected'  ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=rejected">Rejected (<?= (int)($summary['by_status']['rejected'] ?? 0) ?>)</a>
</div>

<div style="max-width:980px;">
    <?php if (empty($redirects)): ?>
        <div class="rd-empty">
            <?php if ($summary['total'] === 0): ?>
                No redirect map yet. <strong>1.</strong> Click "Crawl live site" to discover current URLs. <strong>2.</strong> Click "Build redirect map" to match dead URLs to living targets.
            <?php else: ?>
                No redirects match this filter.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($redirects as $r):
            $conf = (int)$r['confidence'];
            if ($r['to_path'] === null) { $cls = 'notarget'; $conf_cls = 'none'; $conf_label = 'no target'; }
            elseif ($conf >= 85)        { $cls = 'high'; $conf_cls = 'high'; $conf_label = $conf . '%'; }
            elseif ($conf >= 60)        { $cls = 'med';  $conf_cls = 'med';  $conf_label = $conf . '%'; }
            else                        { $cls = 'low';  $conf_cls = 'low';  $conf_label = $conf . '%'; }
        ?>
        <div class="rd-row <?= $cls ?>" data-id="<?= (int)$r['id'] ?>">
            <div>
                <div class="rd-paths">
                    <span class="rd-from"><?= e($r['from_path']) ?></span>
                    <span class="rd-arrow">→</span>
                    <input class="rd-to-input" list="live-paths" data-original="<?= e($r['to_path'] ?? '') ?>" value="<?= e($r['to_path'] ?? '') ?>" placeholder="(no target — consider 410)" onblur="saveTarget(this, <?= (int)$r['id'] ?>)">
                </div>
                <div class="rd-meta">
                    <?= e($r['match_method'] ?: '?') ?>
                    <?php if ($r['reasoning']): ?> · <?= e(mb_substr($r['reasoning'], 0, 140)) ?><?php endif; ?>
                </div>
            </div>
            <div>
                <span class="rd-confidence <?= $conf_cls ?>"><?= e($conf_label) ?></span>
                <span class="rd-status <?= e($r['status']) ?>" style="margin-left:6px;"><?= e($r['status']) ?></span>
            </div>
            <div class="rd-actions">
                <?php if ($r['status'] === 'pending'): ?>
                <button class="btn btn-outline" onclick="approve(<?= (int)$r['id'] ?>, this)" <?= $r['to_path'] === null ? 'disabled title="set a target first"' : '' ?>>✓ Approve</button>
                <button class="btn btn-outline" style="color:var(--danger);" onclick="reject(<?= (int)$r['id'] ?>, this)">✗ Reject</button>
                <?php else: ?>
                <button class="btn btn-outline" onclick="reject(<?= (int)$r['id'] ?>, this)" style="color:var(--text-light);">Revert</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if ($summary['total'] > count($redirects)): ?>
        <div style="font-size:11px;color:var(--text-light);padding:8px 0;">Showing <?= count($redirects) ?> of <?= $summary['total'] ?>. Filter to narrow.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
const SITE_ID = <?= $site_id ?>;
const API = '<?= url('/api/redirect-action.php') ?>';
let pollTimer = null;

async function call(action, body = {}) {
    const res = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action, site_id: SITE_ID, ...body})});
    const data = await res.json();
    if (!res.ok || !data.success && data.error) throw new Error(data.error || ('HTTP ' + res.status));
    return data;
}

async function crawl(btn) {
    btn.disabled = true;
    const prog = document.getElementById('rd-progress');
    prog.style.display = 'block';
    prog.innerHTML = 'Crawling live site (sitemap-first)…';
    try {
        await call('crawl_site');
        prog.innerHTML = 'Crawler running in the background — page will refresh when done.';
        pollUntilSettled();
    } catch (e) { prog.innerHTML = '<span style="color:#dc2626;">' + e.message + '</span>'; btn.disabled = false; }
}

async function buildMap(btn) {
    btn.disabled = true;
    const prog = document.getElementById('rd-progress');
    prog.style.display = 'block';
    prog.innerHTML = 'Building redirect map — Claude matching each dead URL to a living target. This can take a few minutes for big sites.';
    try {
        await call('build_map');
        pollUntilSettled();
    } catch (e) { prog.innerHTML = '<span style="color:#dc2626;">' + e.message + '</span>'; btn.disabled = false; }
}

let lastTotal = null;
async function pollUntilSettled() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(async () => {
        try {
            const data = await call('list', {filter: 'all', limit: 1});
            const total = (data.summary || {}).total || 0;
            if (lastTotal !== null && total === lastTotal) {
                // no new redirects in last 6s — assume settled
                window.location.reload();
                return;
            }
            lastTotal = total;
            const prog = document.getElementById('rd-progress');
            prog.innerHTML = 'Building… ' + total.toLocaleString() + ' redirects produced so far.';
        } catch (e) { /* poll errors ignored */ }
    }, 6000);
}

async function approve(id, btn) {
    btn.disabled = true;
    try { await call('approve', {redirect_id: id}); window.location.reload(); }
    catch (e) { alert(e.message); btn.disabled = false; }
}

async function reject(id, btn) {
    if (!confirm('Reject this redirect?')) return;
    btn.disabled = true;
    try { await call('reject', {redirect_id: id}); window.location.reload(); }
    catch (e) { alert(e.message); btn.disabled = false; }
}

async function approveAllPending(btn) {
    if (!confirm('Approve every pending redirect that already has a target? Rows with no target stay pending — they need a manual call.')) return;
    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = 'Approving…';
    try {
        const r = await call('bulk_approve');
        alert(`Approved ${r.approved} redirects.`);
        window.location.reload();
    } catch (e) {
        alert(e.message);
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

async function applyShopify(btn) {
    if (!confirm('Push all approved redirects to Shopify Admin? Duplicates will be skipped safely.')) return;
    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = 'Pushing…';
    try {
        const r = await call('apply_to_shopify');
        alert(`Pushed: ${r.pushed} new\nDuplicates (already on Shopify): ${r.duplicates}\nErrors: ${r.errors}\nTotal processed: ${r.total}`);
        window.location.reload();
    } catch (e) {
        alert(e.message);
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

async function saveTarget(el, id) {
    const newVal = el.value.trim();
    if (newVal === el.dataset.original) return;
    if (newVal && !newVal.startsWith('/')) { alert('Target must start with /'); el.value = el.dataset.original; return; }
    try {
        await call('set_target', {redirect_id: id, to_path: newVal});
        el.dataset.original = newVal;
        el.classList.remove('dirty');
        el.classList.add('saved');
        setTimeout(() => el.classList.remove('saved'), 800);
    } catch (e) { alert(e.message); el.value = el.dataset.original; }
}

// Mark inputs as dirty as the user types so the colour cue tells them an
// unsaved change is pending — saves on blur.
document.addEventListener('input', (e) => {
    if (e.target.classList && e.target.classList.contains('rd-to-input')) {
        if (e.target.value !== e.target.dataset.original) e.target.classList.add('dirty');
        else e.target.classList.remove('dirty');
    }
});
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
