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

// Paginated historical URL list — supports filter + per-page + page param
$filter   = $_GET['filter'] ?? 'all';
$per_page = (int)($_GET['per_page'] ?? 100);
if (!in_array($per_page, [50, 100, 250, 500], true)) $per_page = 100;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$where = 'site_id = ?';
$args  = [$site_id];
if ($filter === 'dead')      { $where .= ' AND is_dead = 1'; }
if ($filter === 'alive')     { $where .= ' AND current_status_code BETWEEN 200 AND 399'; }
if ($filter === 'unchecked') { $where .= ' AND current_checked_at IS NULL'; }

// total matching the filter (for the pagination footer)
$cnt = $db->prepare("SELECT COUNT(*) FROM historical_urls WHERE {$where}");
$cnt->execute($args);
$matching = (int)$cnt->fetchColumn();
$total_pages = max(1, (int)ceil($matching / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$stmt = $db->prepare("SELECT url, path, first_seen, last_seen, snapshot_count, current_status_code, current_checked_at, is_dead
                      FROM historical_urls
                      WHERE {$where}
                      ORDER BY last_seen DESC
                      LIMIT {$per_page} OFFSET {$offset}");
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
/* Bold "LIVE — check is running" indicator with a pulsing dot, rate, ETA. */
#wb-live-banner {
    display:none;
    padding:14px 18px;
    margin-bottom:14px;
    background:linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border:1px solid #059669;
    border-radius:8px;
    color:#064e3b;
    align-items:center;
    gap:14px;
    box-shadow:0 2px 8px rgba(5, 150, 105, 0.18);
}
#wb-live-banner.active { display:flex; }
.wb-live-dot {
    width:14px; height:14px; border-radius:50%; background:#059669; flex-shrink:0;
    animation:wb-pulse 1.4s infinite;
}
@keyframes wb-pulse {
    0%   { box-shadow:0 0 0 0 rgba(5,150,105,0.7); }
    70%  { box-shadow:0 0 0 14px rgba(5,150,105,0); }
    100% { box-shadow:0 0 0 0 rgba(5,150,105,0); }
}
.wb-live-title { font-weight:700; font-size:15px; margin-bottom:3px; color:#064e3b; }
.wb-live-sub   { font-size:12px; color:#065f46; line-height:1.5; }
.wb-live-stat  { font-weight:700; color:#022c22; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/seo.php?site=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">← Back to SEO</a>
</div>

<!-- Bold "LIVE" indicator. Shows up only when the live-check is actively running. -->
<div id="wb-live-banner" style="max-width:980px;">
    <div class="wb-live-dot"></div>
    <div style="flex:1;">
        <div class="wb-live-title">Live status check running <span id="wb-live-pct" style="font-weight:400;color:#065f46;"></span></div>
        <div class="wb-live-sub">
            <span class="wb-live-stat" id="wb-live-checked">0</span> of <span id="wb-live-total">0</span> URLs checked
            · <span class="wb-live-stat" id="wb-live-dead">0</span> dead found
            · <span class="wb-live-stat" id="wb-live-rate">—</span> URLs/min
            · ETA <span class="wb-live-stat" id="wb-live-eta">—</span>
            <br>
            <span style="opacity:0.8;">Page auto-refreshes as we work. Safe to navigate away or close this tab — the check keeps running on the server.</span>
        </div>
    </div>
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
        <div style="display:flex; gap:6px; white-space:nowrap;">
            <?php if ($summary['unchecked'] > 0): ?>
                <button id="wb-check" class="btn btn-outline btn-sm" onclick="runCheckStatus()" title="HEAD each historical URL to mark which are alive vs dead today">
                    Check live status (<?= number_format($summary['unchecked']) ?>)
                </button>
            <?php endif; ?>
            <button id="wb-run" class="btn btn-accent btn-sm" onclick="runHarvest()">
                <?= $summary['last_run'] ? 'Re-pull history' : 'Pull archive history' ?>
            </button>
        </div>
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

<?php
    $alive_count = $summary['total_urls'] - $summary['dead_urls'] - $summary['unchecked'];
    $base = '/dashboard/wayback.php?site=' . $site_id;
    $qs = function($extra) use ($base) {
        $params = [];
        parse_str(parse_url($base, PHP_URL_QUERY), $params);
        $params = array_merge($params, $extra);
        return strtok($base, '?') . '?' . http_build_query($params);
    };
?>
<div class="wb-pills" style="max-width:980px;">
    <a class="wb-pill <?= $filter === 'all'       ? 'active' : '' ?>" href="<?= url($base) ?>">All (<?= number_format($summary['total_urls']) ?>)</a>
    <a class="wb-pill <?= $filter === 'alive'     ? 'active' : '' ?>" href="<?= url($base . '&filter=alive') ?>">Alive (<?= number_format($alive_count) ?>)</a>
    <a class="wb-pill <?= $filter === 'dead'      ? 'active' : '' ?>" href="<?= url($base . '&filter=dead') ?>">Dead (<?= number_format($summary['dead_urls']) ?>)</a>
    <a class="wb-pill <?= $filter === 'unchecked' ? 'active' : '' ?>" href="<?= url($base . '&filter=unchecked') ?>">Unchecked (<?= number_format($summary['unchecked']) ?>)</a>
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
        <div style="padding:10px 14px; border-top:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; font-size:12px; color:var(--text-light);">
            <div>
                Showing <strong style="color:#0f172a;"><?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $matching)) ?></strong> of <strong style="color:#0f172a;"><?= number_format($matching) ?></strong>
                <?php if ($filter !== 'all'): ?> (<?= e($filter) ?>)<?php endif; ?>
            </div>
            <div style="display:flex; gap:6px; align-items:center;">
                <span>Per page:</span>
                <?php foreach ([50, 100, 250, 500] as $pp): ?>
                    <a href="<?= e(url($qs(['per_page' => $pp, 'page' => 1]))) ?>" style="padding:2px 8px; border:1px solid <?= $per_page === $pp ? 'var(--accent)' : 'var(--border)' ?>; border-radius:10px; text-decoration:none; color:<?= $per_page === $pp ? 'var(--accent)' : 'var(--text-light)' ?>; font-weight:<?= $per_page === $pp ? 600 : 400 ?>;">
                        <?= $pp ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div style="display:flex; gap:6px; align-items:center;">
                <?php if ($page > 1): ?>
                    <a href="<?= e(url($qs(['page' => 1]))) ?>" style="color:var(--primary); text-decoration:none;">«</a>
                    <a href="<?= e(url($qs(['page' => $page - 1]))) ?>" style="color:var(--primary); text-decoration:none;">‹ Prev</a>
                <?php else: ?>
                    <span style="color:#cbd5e1;">«</span>
                    <span style="color:#cbd5e1;">‹ Prev</span>
                <?php endif; ?>
                <span>Page <strong style="color:#0f172a;"><?= $page ?></strong> of <?= number_format($total_pages) ?></span>
                <?php if ($page < $total_pages): ?>
                    <a href="<?= e(url($qs(['page' => $page + 1]))) ?>" style="color:var(--primary); text-decoration:none;">Next ›</a>
                    <a href="<?= e(url($qs(['page' => $total_pages]))) ?>" style="color:var(--primary); text-decoration:none;">»</a>
                <?php else: ?>
                    <span style="color:#cbd5e1;">Next ›</span>
                    <span style="color:#cbd5e1;">»</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
const SITE_ID = <?= $site_id ?>;
let pollTimer = null;
let liveStatsTimer = null;

// Track previous reading to compute throughput (URLs/min) live.
let prevReading = null;

async function refreshLiveStats() {
    try {
        const r = await fetch('<?= url('/api/wayback-status.php?site_id=') ?>' + SITE_ID);
        const d = await r.json();
        const total = d.total_urls || 0;
        const dead  = d.dead_urls  || 0;
        const unck  = d.unchecked  || 0;
        const checked = total - unck;

        // Update the four stat cards (total / dead / unchecked / last-harvest)
        const cards = document.querySelectorAll('.wb-card .num');
        if (cards.length >= 3) {
            cards[0].textContent = total.toLocaleString();
            cards[1].textContent = dead.toLocaleString();
            cards[2].textContent = unck.toLocaleString();
        }

        const banner = document.getElementById('wb-live-banner');
        if (d.is_running && unck > 0) {
            banner.classList.add('active');
            document.getElementById('wb-live-checked').textContent = checked.toLocaleString();
            document.getElementById('wb-live-total').textContent   = total.toLocaleString();
            document.getElementById('wb-live-dead').textContent    = dead.toLocaleString();
            const pct = total > 0 ? Math.round((checked / total) * 100) : 0;
            document.getElementById('wb-live-pct').textContent = '· ' + pct + '%';

            // Rate from delta between polls
            const now = Date.now();
            if (prevReading) {
                const dtMs   = now - prevReading.t;
                const dCheck = checked - prevReading.checked;
                if (dtMs > 0 && dCheck > 0) {
                    const ratePerMin = (dCheck / dtMs) * 60000;
                    document.getElementById('wb-live-rate').textContent = Math.round(ratePerMin).toLocaleString();
                    const etaMin = unck / Math.max(1, ratePerMin);
                    let etaTxt;
                    if (etaMin < 1)        etaTxt = '<1 min';
                    else if (etaMin < 60)  etaTxt = Math.round(etaMin) + ' min';
                    else                   etaTxt = (etaMin / 60).toFixed(1) + ' hours';
                    document.getElementById('wb-live-eta').textContent = etaTxt;
                }
            }
            prevReading = { t: now, checked: checked };
        } else {
            banner.classList.remove('active');
        }

        // Finished — reload once to refresh table + pill counts
        if (unck === 0 && checked > 0) {
            clearInterval(liveStatsTimer);
            setTimeout(() => window.location.reload(), 1500);
        }
    } catch (e) { /* poll errors silenced */ }
}

// Always poll on this page — banner only shows when is_running flips true.
// Catches both the "user just clicked" case AND the "process started elsewhere".
refreshLiveStats();
liveStatsTimer = setInterval(refreshLiveStats, 6000);

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

async function runCheckStatus() {
    const btn = document.getElementById('wb-check');
    const prog = document.getElementById('wb-progress');
    if (btn) btn.disabled = true;
    prog.style.display = 'block';
    prog.innerHTML = 'Starting live-status check…';
    try {
        const res = await fetch('<?= url('/api/wayback-checkstatus.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({site_id: SITE_ID})
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || ('HTTP ' + res.status));
        prog.innerHTML = 'Checking each URL with HEAD requests — about 1.2s per URL. Page will auto-refresh as the counter drops.';
        startCheckPolling();
    } catch (e) {
        prog.innerHTML = '<span style="color:#dc2626;">' + e.message + '</span>';
        if (btn) btn.disabled = false;
    }
}

function startCheckPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(pollCheckStatus, 5000);
    pollCheckStatus();
}

let lastUnchecked = null;
async function pollCheckStatus() {
    try {
        const res = await fetch('<?= url('/api/wayback-status.php?site_id=') ?>' + SITE_ID);
        const data = await res.json();
        const prog = document.getElementById('wb-progress');
        const unchecked = data.unchecked || 0;
        if (unchecked === 0) {
            window.location.reload(); // done — refresh to show dead/alive counts + table
            return;
        }
        if (lastUnchecked === null || unchecked < lastUnchecked) {
            prog.innerHTML = `<strong>Checking…</strong> ${parseInt(unchecked).toLocaleString()} URLs left to check (${(data.total_urls - unchecked).toLocaleString()} done · ${data.dead_urls.toLocaleString()} dead found so far).`;
        }
        lastUnchecked = unchecked;
    } catch (e) { /* swallow */ }
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
