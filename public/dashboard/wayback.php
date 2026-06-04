<?php
/**
 * Dashboard — Archive History (a.k.a. Wayback harvest).
 *
 * Customer-facing copy never says "Wayback" or "Internet Archive" — these are
 * implementation details. The user just sees "archive history" or "URLs we
 * found in search-engine indexes." Per feedback_no_vendor_leaks.md.
 *
 * Per-site page with: stats card · run-now button · list of historical URLs.
 * Drives the 301 redirect map builder (Module 3) — every dead URL here is
 * a candidate for redirect to a living target.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/wayback_harvester.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

$summary = wayback_site_summary($db, $site_id);

// Sample of historical URLs — top 50 by last_seen DESC, optionally filtered
$filter = $_GET['filter'] ?? 'all';
$where = 'site_id = ?';
$args  = [$site_id];
if ($filter === 'dead') { $where .= ' AND is_dead = 1'; }
if ($filter === 'unchecked') { $where .= ' AND current_checked_at IS NULL'; }
$stmt = $db->prepare("SELECT url, path, first_seen, last_seen, snapshot_count, current_status_code, current_checked_at, is_dead
                      FROM historical_urls
                      WHERE {$where}
                      ORDER BY last_seen DESC
                      LIMIT 50");
$stmt->execute($args);
$urls = $stmt->fetchAll();

$page_title = 'Archive History — ' . $site['name'];
ob_start();
?>
<style>
.wb-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; margin-bottom:14px; }
.wb-card { background:#fff; border:1px solid var(--border); border-radius:6px; padding:14px; }
.wb-card .label { font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-light); margin-bottom:4px; }
.wb-card .num { font-size:28px; font-weight:700; color:var(--primary); line-height:1; }
.wb-card .num.dead { color:#dc2626; }
.wb-card .sub { font-size:11px; color:var(--text-light); margin-top:6px; }
.wb-pills { display:flex; gap:0; border-bottom:1px solid var(--border); margin:14px 0 10px; }
.wb-pill { padding:7px 14px; font-size:12px; color:var(--text-light); border-bottom:2px solid transparent; text-decoration:none; }
.wb-pill.active { color:var(--primary); border-bottom-color:var(--primary); font-weight:600; }
.wb-table { width:100%; border-collapse:collapse; font-size:12px; }
.wb-table th { text-align:left; font-weight:600; color:var(--text-light); padding:8px 10px; border-bottom:1px solid var(--border); font-size:11px; text-transform:uppercase; letter-spacing:0.4px; }
.wb-table td { padding:8px 10px; border-bottom:1px solid var(--border); color:var(--text); }
.wb-table tr:hover { background:#f8fafc; }
.wb-url { font-family:ui-monospace, monospace; font-size:11px; color:#475569; word-break:break-all; }
.wb-status { font-size:11px; padding:2px 8px; border-radius:10px; display:inline-block; }
.wb-status.dead    { background:#fee2e2; color:#991b1b; }
.wb-status.alive   { background:#d1fae5; color:#065f46; }
.wb-status.unknown { background:#f1f5f9; color:#64748b; }
#wb-progress { display:none; font-size:12px; color:var(--text-light); margin-top:8px; padding:8px 10px; background:#f8fafc; border-radius:4px; border:1px dashed var(--border); }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/seo.php?site=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">← Back to SEO</a>
</div>

<div class="setup-section" style="max-width:980px;">
    <div style="display:flex; justify-content:space-between; align-items:start; gap:12px; margin-bottom:6px;">
        <div>
            <h3 style="margin:0 0 3px; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--primary);">Archive history</h3>
            <p class="desc" style="margin:0; max-width:720px;">
                URLs that search engines have indexed for <strong><?= e($site['domain']) ?></strong> over the years.
                Includes pages that no longer exist on your site but still appear in Google's index — every dead URL here
                is a candidate for a 301 redirect to a living page (Module 3 builds the redirect map).
            </p>
        </div>
        <button id="wb-run" class="btn btn-accent btn-sm" onclick="runHarvest()" style="white-space:nowrap;">
            <?= $summary['last_run'] ? 'Re-pull history' : 'Pull archive history' ?>
        </button>
    </div>
    <div id="wb-progress"></div>
</div>

<div class="wb-stats" style="max-width:980px;">
    <div class="wb-card">
        <div class="label">URLs in archive</div>
        <div class="num"><?= number_format($summary['total_urls']) ?></div>
        <div class="sub">Across all historical snapshots</div>
    </div>
    <div class="wb-card">
        <div class="label">Dead URLs</div>
        <div class="num dead"><?= number_format($summary['dead_urls']) ?></div>
        <div class="sub">Returned 4xx/5xx on last check</div>
    </div>
    <div class="wb-card">
        <div class="label">Unchecked</div>
        <div class="num"><?= number_format($summary['unchecked']) ?></div>
        <div class="sub">Live status not yet verified</div>
    </div>
    <div class="wb-card">
        <div class="label">Last harvest</div>
        <div class="num" style="font-size:18px; color:var(--text);">
            <?= $summary['last_run'] && $summary['last_run']['finished_at']
                ? e(date('d M H:i', strtotime($summary['last_run']['finished_at'])))
                : ($summary['last_run'] && $summary['last_run']['status'] === 'running' ? 'running…' : '—') ?>
        </div>
        <div class="sub">
            <?php if ($summary['last_run']): ?>
                <?= (int)$summary['last_run']['urls_fetched'] ?> fetched · <?= (int)$summary['last_run']['urls_new'] ?> new
                <?php if ($summary['last_run']['error']): ?>
                    <br><span style="color:#dc2626;"><?= e(mb_substr($summary['last_run']['error'], 0, 120)) ?></span>
                <?php endif; ?>
            <?php else: ?>
                Never run
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="wb-pills" style="max-width:980px;">
    <a class="wb-pill <?= $filter === 'all' ? 'active' : '' ?>" href="<?= url('/dashboard/wayback.php?site=' . $site_id) ?>">All (<?= number_format($summary['total_urls']) ?>)</a>
    <a class="wb-pill <?= $filter === 'dead' ? 'active' : '' ?>" href="<?= url('/dashboard/wayback.php?site=' . $site_id . '&filter=dead') ?>">Dead (<?= number_format($summary['dead_urls']) ?>)</a>
    <a class="wb-pill <?= $filter === 'unchecked' ? 'active' : '' ?>" href="<?= url('/dashboard/wayback.php?site=' . $site_id . '&filter=unchecked') ?>">Unchecked (<?= number_format($summary['unchecked']) ?>)</a>
</div>

<div style="max-width:980px; background:#fff; border:1px solid var(--border); border-radius:6px; overflow:hidden;">
    <?php if (empty($urls)): ?>
        <div style="padding:18px; font-size:13px; color:var(--text-light);">
            <?php if ($summary['total_urls'] === 0): ?>
                No archive history yet. Click <strong>Pull archive history</strong> above to harvest from search-engine archives. This takes 1-10 minutes depending on how long your domain has been around.
            <?php else: ?>
                No URLs match this filter.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <table class="wb-table">
            <thead>
                <tr>
                    <th style="width:auto;">URL</th>
                    <th style="width:110px;">First seen</th>
                    <th style="width:110px;">Last seen</th>
                    <th style="width:70px; text-align:center;">Snaps</th>
                    <th style="width:100px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($urls as $u):
                    if ($u['current_checked_at'] === null) {
                        $st_cls = 'unknown'; $st_label = 'not checked';
                    } elseif ((int)$u['current_status_code'] >= 400) {
                        $st_cls = 'dead'; $st_label = '✗ ' . (int)$u['current_status_code'];
                    } else {
                        $st_cls = 'alive'; $st_label = '✓ ' . (int)$u['current_status_code'];
                    }
                ?>
                <tr>
                    <td><span class="wb-url"><?= e(mb_substr($u['url'], 0, 140)) ?></span></td>
                    <td style="font-size:11px; color:var(--text-light);"><?= $u['first_seen'] ? e(date('M Y', strtotime($u['first_seen']))) : '—' ?></td>
                    <td style="font-size:11px; color:var(--text-light);"><?= $u['last_seen']  ? e(date('M Y', strtotime($u['last_seen']))) : '—' ?></td>
                    <td style="text-align:center; font-size:11px; color:var(--text-light);"><?= (int)$u['snapshot_count'] ?></td>
                    <td><span class="wb-status <?= $st_cls ?>"><?= e($st_label) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($summary['total_urls'] > 50): ?>
        <div style="padding:10px 14px; font-size:11px; color:var(--text-light); border-top:1px solid var(--border);">
            Showing 50 of <?= number_format($summary['total_urls']) ?>. Full list + bulk redirect mapping in Module 3.
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
const SITE_ID = <?= $site_id ?>;
let pollTimer = null;

async function runHarvest() {
    const btn = document.getElementById('wb-run');
    const prog = document.getElementById('wb-progress');
    btn.disabled = true;
    prog.style.display = 'block';
    prog.innerHTML = 'Starting harvest…';
    try {
        const res = await fetch('<?= url('/api/wayback-start.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({site_id: SITE_ID})
        });
        const data = await res.json();
        if (!res.ok || !data.success) { throw new Error(data.error || ('HTTP ' + res.status)); }
        prog.innerHTML = 'Harvest running in the background — this typically takes 1-10 minutes. Page will auto-refresh as new URLs come in.';
        startPolling();
    } catch (e) {
        prog.innerHTML = '<span style="color:#dc2626;">' + e.message + '</span>';
        btn.disabled = false;
    }
}

function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(pollStatus, 6000);
    pollStatus(); // immediate first check
}

async function pollStatus() {
    try {
        const res = await fetch('<?= url('/api/wayback-status.php?site_id=') ?>' + SITE_ID);
        const data = await res.json();
        if (data.last_run) {
            const lr = data.last_run;
            const prog = document.getElementById('wb-progress');
            if (lr.status === 'running') {
                prog.innerHTML = `<strong>Running…</strong> ${parseInt(lr.urls_fetched || 0).toLocaleString()} URLs fetched so far.`;
            } else {
                // finished — reload the page so the table + stats refresh
                window.location.reload();
            }
        }
    } catch (e) { /* swallow transient poll errors */ }
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
