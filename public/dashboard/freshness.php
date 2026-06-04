<?php
/**
 * Dashboard — Content Freshness.
 *
 * Shows posts ranked by staleness score. One-click "Queue refresh" turns a
 * stale post into a Content Plan item with content_type=refresh, targeting
 * the existing URL.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/content_freshness.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

$summary = cf_site_summary($db, $site_id);

$filter = $_GET['filter'] ?? 'pending';
$where = "cf.site_id = ?"; $args = [$site_id];
if ($filter === 'pending')   { $where .= " AND cf.needs_refresh = 1 AND cf.queued_plan_item_id IS NULL AND cf.dismissed_at IS NULL"; }
if ($filter === 'queued')    { $where .= " AND cf.queued_plan_item_id IS NOT NULL"; }
if ($filter === 'dismissed') { $where .= " AND cf.dismissed_at IS NOT NULL"; }
if ($filter === 'all')       { /* no filter */ }

$stmt = $db->prepare("SELECT cf.id, cf.post_id, cf.staleness_score, cf.refresh_reason, cf.age_days, cf.queued_plan_item_id, cf.dismissed_at, p.title, p.slug
                      FROM content_freshness cf JOIN posts p ON p.id = cf.post_id
                      WHERE {$where} ORDER BY cf.staleness_score DESC LIMIT 200");
$stmt->execute($args);
$items = $stmt->fetchAll();

$page_title = 'Content Freshness — ' . $site['name'];
ob_start();
?>
<style>
.cf-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-bottom:14px; }
.cf-card { background:#fff; border:1px solid var(--border); border-radius:6px; padding:13px 14px; }
.cf-card .label { font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-light); margin-bottom:4px; }
.cf-card .num { font-size:26px; font-weight:700; line-height:1; }
.cf-card .num.bad { color:#dc2626; }
.cf-card .num.warn { color:#d97706; }
.cf-card .num.good { color:#059669; }
.cf-pills { display:flex; gap:0; border-bottom:1px solid var(--border); margin:14px 0 10px; flex-wrap:wrap; }
.cf-pill { padding:7px 14px; font-size:12px; color:var(--text-light); border-bottom:2px solid transparent; text-decoration:none; }
.cf-pill.active { color:var(--primary); border-bottom-color:var(--primary); font-weight:600; }
.cf-row { background:#fff; border:1px solid var(--border); border-radius:6px; padding:12px 14px; margin-bottom:6px; display:grid; grid-template-columns:1fr auto auto; gap:14px; align-items:center; }
.cf-row.hot { border-left:3px solid #dc2626; }
.cf-row.warm { border-left:3px solid #d97706; }
.cf-title { font-size:13px; font-weight:600; color:var(--text); }
.cf-meta { font-size:11px; color:var(--text-light); margin-top:3px; }
.cf-score { font-size:18px; font-weight:700; padding:3px 10px; border-radius:14px; }
.cf-score.hot { background:#fee2e2; color:#991b1b; }
.cf-score.warm { background:#fef3c7; color:#92400e; }
.cf-score.cool { background:#d1fae5; color:#065f46; }
.cf-actions { display:flex; gap:4px; }
.cf-actions button { font-size:11px; padding:3px 10px; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/plan.php?site=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">← Back to Content Plan</a>
</div>

<div class="setup-section" style="max-width:980px;">
    <h3 style="margin:0 0 3px; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--primary);">Content freshness</h3>
    <p class="desc" style="margin:0 0 8px; max-width:720px;">
        Posts ranked by staleness — age, stale-year mentions in body or title, and traffic-decline signals (when GSC data is available).
        One-click "Queue refresh" turns a row into a Content Plan item targeting the existing URL.
    </p>
    <button class="btn btn-accent btn-sm" onclick="runAudit(this)">↻ Audit posts now</button>
    <div id="cf-progress" style="display:none; font-size:12px; color:var(--text-light); margin-top:8px;"></div>
</div>

<div class="cf-stats" style="max-width:980px;">
    <div class="cf-card"><div class="label">Total audited</div><div class="num"><?= number_format((int)($summary['total'] ?? 0)) ?></div></div>
    <div class="cf-card"><div class="label">Needs refresh</div><div class="num bad"><?= number_format((int)($summary['pending'] ?? 0)) ?></div></div>
    <div class="cf-card"><div class="label">Queued in plan</div><div class="num warn"><?= number_format((int)($summary['queued'] ?? 0)) ?></div></div>
    <div class="cf-card"><div class="label">Dismissed</div><div class="num good"><?= number_format((int)($summary['dismissed'] ?? 0)) ?></div></div>
</div>

<div class="cf-pills" style="max-width:980px;">
    <a class="cf-pill <?= $filter === 'pending' ? 'active' : '' ?>" href="?site=<?= $site_id ?>">Pending (<?= (int)($summary['pending'] ?? 0) ?>)</a>
    <a class="cf-pill <?= $filter === 'queued' ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=queued">Queued (<?= (int)($summary['queued'] ?? 0) ?>)</a>
    <a class="cf-pill <?= $filter === 'dismissed' ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=dismissed">Dismissed (<?= (int)($summary['dismissed'] ?? 0) ?>)</a>
    <a class="cf-pill <?= $filter === 'all' ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=all">All (<?= (int)($summary['total'] ?? 0) ?>)</a>
</div>

<div style="max-width:980px;">
    <?php if (empty($items)): ?>
        <div style="font-size:13px; color:var(--text-light); padding:16px; background:#f8fafc; border-radius:6px; border:1px dashed var(--border);">
            <?php if ((int)($summary['total'] ?? 0) === 0): ?>
                No freshness audit yet. Click <strong>Audit posts now</strong> — we'll scan every published post and surface candidates for refresh.
            <?php else: ?>
                Nothing matches this filter.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($items as $it):
            $sc = (int)$it['staleness_score'];
            $band = $sc >= 80 ? 'hot' : ($sc >= 60 ? 'warm' : 'cool');
        ?>
        <div class="cf-row <?= $band ?>">
            <div>
                <div class="cf-title"><?= e($it['title']) ?></div>
                <div class="cf-meta">
                    <?= (int)$it['age_days'] ?> days old · /<?= e($it['slug']) ?>
                    <?php if ($it['refresh_reason']): ?> · <?= e($it['refresh_reason']) ?><?php endif; ?>
                </div>
            </div>
            <div><span class="cf-score <?= $band ?>"><?= $sc ?></span></div>
            <div class="cf-actions">
                <?php if ($it['queued_plan_item_id']): ?>
                    <a class="btn btn-outline" href="<?= url('/dashboard/plan-item.php?id=' . (int)$it['queued_plan_item_id']) ?>">Open plan item</a>
                <?php elseif ($it['dismissed_at']): ?>
                    <span style="font-size:11px; color:var(--text-light);">dismissed</span>
                <?php else: ?>
                    <button class="btn btn-accent" onclick="queueRefresh(<?= (int)$it['id'] ?>, this)">⚡ Queue refresh</button>
                    <button class="btn btn-outline" onclick="dismiss(<?= (int)$it['id'] ?>, this)">Dismiss</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
const SITE_ID = <?= $site_id ?>;
const API = '<?= url('/api/freshness-action.php') ?>';
async function call(action, body = {}) {
    const res = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action, site_id: SITE_ID, ...body})});
    const data = await res.json();
    if (!res.ok || !data.success && data.error) throw new Error(data.error || ('HTTP ' + res.status));
    return data;
}
async function runAudit(btn) {
    btn.disabled = true;
    document.getElementById('cf-progress').style.display = 'block';
    document.getElementById('cf-progress').textContent = 'Auditing posts in the background — refresh in a few seconds.';
    try { await call('run'); setTimeout(() => window.location.reload(), 6000); }
    catch (e) { alert(e.message); btn.disabled = false; }
}
async function queueRefresh(id, btn) {
    btn.disabled = true;
    try {
        const r = await call('queue_refresh', {freshness_id: id});
        window.location.href = r.item_url;
    } catch (e) { alert(e.message); btn.disabled = false; }
}
async function dismiss(id, btn) {
    if (!confirm('Dismiss this — never surface again?')) return;
    btn.disabled = true;
    try { await call('dismiss', {freshness_id: id}); window.location.reload(); }
    catch (e) { alert(e.message); btn.disabled = false; }
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
