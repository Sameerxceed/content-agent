<?php
/**
 * Dashboard — Core Web Vitals + Page Speed.
 *
 * Per-site page surface: baseline URL list, latest snapshot table,
 * trend chart slot, run-now button. Customer copy never says "PSI" or
 * "Lighthouse" — they read "page speed" and "Core Web Vitals."
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/psi_runner.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

$summary = psi_site_summary($db, $site_id);
$latest  = psi_latest_per_url($db, $site_id);

$stmt = $db->prepare("SELECT url, label, priority FROM cwv_baseline_urls WHERE site_id = ? ORDER BY priority DESC, id");
$stmt->execute([$site_id]);
$baseline_urls = $stmt->fetchAll();

$has_key = !empty(config('google_psi_api_key'));

$page_title = 'Page Speed — ' . $site['name'];
ob_start();
?>
<style>
.cwv-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; margin-bottom:14px; }
.cwv-card { background:#fff; border:1px solid var(--border); border-radius:6px; padding:14px; }
.cwv-card .label { font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-light); margin-bottom:4px; }
.cwv-card .num { font-size:30px; font-weight:700; line-height:1; }
.cwv-card .num.good { color:#059669; }
.cwv-card .num.warn { color:#d97706; }
.cwv-card .num.bad  { color:#dc2626; }
.cwv-card .num.gray { color:#94a3b8; }
.cwv-card .sub { font-size:11px; color:var(--text-light); margin-top:5px; }
.cwv-table { width:100%; border-collapse:collapse; font-size:12px; background:#fff; border:1px solid var(--border); border-radius:6px; overflow:hidden; }
.cwv-table th { text-align:left; font-weight:600; color:var(--text-light); padding:8px 10px; border-bottom:1px solid var(--border); font-size:11px; text-transform:uppercase; letter-spacing:0.4px; }
.cwv-table td { padding:10px 10px; border-bottom:1px solid var(--border); }
.cwv-table tr:last-child td { border-bottom:0; }
.cwv-url { font-family:ui-monospace, monospace; font-size:11px; color:#475569; }
.cwv-score { font-size:14px; font-weight:700; padding:3px 9px; border-radius:10px; display:inline-block; min-width:36px; text-align:center; }
.cwv-score.good { background:#d1fae5; color:#065f46; }
.cwv-score.warn { background:#fef3c7; color:#92400e; }
.cwv-score.bad  { background:#fee2e2; color:#991b1b; }
.cwv-score.none { background:#f1f5f9; color:#64748b; }
.cwv-metric { font-size:11px; color:#475569; }
.cwv-metric strong { font-size:12px; color:#0f172a; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/seo.php?site=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">← Back to SEO</a>
</div>

<div class="setup-section" style="max-width:980px;">
    <h3 style="margin:0 0 3px; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--primary);">Page Speed &amp; Core Web Vitals</h3>
    <p class="desc" style="margin:0 0 8px; max-width:720px;">
        Real-user + lab measurements for your most important pages. We track
        Largest Contentful Paint, Interaction to Next Paint, and Cumulative
        Layout Shift weekly so regressions show up before they hurt traffic.
    </p>
    <button id="psi-run" class="btn btn-accent btn-sm" onclick="runBaseline(this)">
        <?= empty($latest) ? '⚡ Run first baseline' : '↻ Refresh baseline now' ?>
    </button>
    <?php if (!$has_key): ?>
        <span style="font-size:11px; color:#92400e; margin-left:10px;">
            ⚠ No PSI API key configured — runs use the unauthenticated quota (~25/day). Add a key under Integrations for daily/weekly cron.
        </span>
    <?php endif; ?>
    <div id="psi-progress" style="display:none; font-size:12px; color:var(--text-light); margin-top:8px; padding:8px 10px; background:#f8fafc; border-radius:4px; border:1px dashed var(--border);"></div>
</div>

<div class="cwv-stats" style="max-width:980px;">
    <div class="cwv-card">
        <div class="label">URLs tracked</div>
        <div class="num"><?= number_format(count($baseline_urls)) ?></div>
        <div class="sub">Mobile + Desktop snapshots</div>
    </div>
    <div class="cwv-card">
        <div class="label">Avg perf score (mobile)</div>
        <?php
            $sc = $summary['avg_perf_mobile'];
            $cls = $sc === null ? 'gray' : ($sc >= 75 ? 'good' : ($sc >= 50 ? 'warn' : 'bad'));
        ?>
        <div class="num <?= $cls ?>"><?= $sc === null ? '—' : $sc ?></div>
        <div class="sub">Out of 100</div>
    </div>
    <div class="cwv-card">
        <div class="label">Good CWV (mobile)</div>
        <div class="num good"><?= (int)$summary['mobile']['good'] ?></div>
        <div class="sub">LCP &lt; 2.5s</div>
    </div>
    <div class="cwv-card">
        <div class="label">Needs work</div>
        <div class="num warn"><?= (int)$summary['mobile']['needs_improvement'] ?></div>
        <div class="sub">LCP 2.5–4s</div>
    </div>
    <div class="cwv-card">
        <div class="label">Poor</div>
        <div class="num bad"><?= (int)$summary['mobile']['poor'] ?></div>
        <div class="sub">LCP &gt; 4s — fix first</div>
    </div>
</div>

<div style="max-width:980px;">
    <?php if (empty($latest) && empty($baseline_urls)): ?>
        <div style="font-size:13px; color:var(--text-light); padding:16px; background:#f8fafc; border-radius:6px; border:1px dashed var(--border);">
            No baseline yet. Click <strong>Run first baseline</strong> above — we'll auto-pick 10 representative URLs from your site and snapshot them. Takes ~5-7 minutes per site.
        </div>
    <?php elseif (empty($latest)): ?>
        <div style="font-size:13px; color:var(--text-light); padding:16px; background:#f8fafc; border-radius:6px; border:1px dashed var(--border);">
            Baseline URLs configured but no snapshots yet. Click <strong>Run first baseline</strong>.
        </div>
    <?php else: ?>
        <table class="cwv-table">
            <thead>
                <tr>
                    <th>URL</th>
                    <th style="width:90px;">Device</th>
                    <th style="width:80px;">Perf</th>
                    <th style="width:100px;">LCP</th>
                    <th style="width:100px;">INP</th>
                    <th style="width:80px;">CLS</th>
                    <th style="width:100px;">Real-user</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($latest as $url => $by_device):
                    foreach (['mobile', 'desktop'] as $device):
                        $r = $by_device[$device] ?? null;
                        if (!$r) continue;
                        $perf = $r['perf_score'] ?? null;
                        $pcls = $perf === null ? 'none' : ($perf >= 75 ? 'good' : ($perf >= 50 ? 'warn' : 'bad'));
                        $lcp = (int)($r['field_lcp_ms'] ?? $r['lcp_ms'] ?? 0);
                        $lcp_band = $lcp === 0 ? 'none' : ($lcp <= 2500 ? 'good' : ($lcp <= 4000 ? 'warn' : 'bad'));
                        $cls_v = (float)($r['field_cls'] ?? $r['cls'] ?? 0);
                        $cls_band = $cls_v == 0 ? 'none' : ($cls_v <= 0.1 ? 'good' : ($cls_v <= 0.25 ? 'warn' : 'bad'));
                        $inp = (int)($r['field_inp_ms'] ?? 0);
                ?>
                <tr>
                    <?php if ($device === 'mobile'): ?>
                    <td rowspan="2"><span class="cwv-url"><?= e(parse_url($url, PHP_URL_PATH) ?: $url) ?></span></td>
                    <?php endif; ?>
                    <td style="font-size:11px; color:#64748b;"><?= e($device) ?></td>
                    <td>
                        <?php if ($r['error']): ?>
                            <span class="cwv-score none" title="<?= e($r['error']) ?>">err</span>
                        <?php else: ?>
                            <span class="cwv-score <?= $pcls ?>"><?= $perf ?? '—' ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="cwv-metric"><strong><?= $lcp ? number_format($lcp / 1000, 1) . 's' : '—' ?></strong></span></td>
                    <td><span class="cwv-metric"><strong><?= $inp ? number_format($inp) . 'ms' : '—' ?></strong></span></td>
                    <td><span class="cwv-metric"><strong><?= $cls_v ? number_format($cls_v, 2) : '—' ?></strong></span></td>
                    <td>
                        <?php if (!empty($r['field_loading'])): ?>
                            <span class="cwv-score <?= $r['field_loading'] === 'FAST' ? 'good' : ($r['field_loading'] === 'AVERAGE' ? 'warn' : 'bad') ?>"><?= e(strtolower($r['field_loading'])) ?></span>
                        <?php else: ?>
                            <span style="font-size:10px; color:#94a3b8;">lab only</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endforeach; ?>
            </tbody>
        </table>
        <div style="font-size:11px; color:var(--text-light); margin-top:8px;">
            <strong>Real-user</strong> data comes from Chrome users who actually visited the URL in the last 28 days (Google CrUX). Pages with low traffic show "lab only" — accurate but synthetic.
        </div>
    <?php endif; ?>
</div>

<script>
const SITE_ID = <?= $site_id ?>;
async function runBaseline(btn) {
    if (!confirm('Run a fresh page-speed baseline for this site? Takes ~5-7 minutes for 10 URLs across mobile + desktop.')) return;
    btn.disabled = true;
    const prog = document.getElementById('psi-progress');
    prog.style.display = 'block';
    prog.innerHTML = 'Launching baseline run in the background — page will refresh when results land.';
    try {
        const res = await fetch('<?= url('/api/psi-action.php') ?>', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'run_baseline', site_id: SITE_ID})
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || ('HTTP ' + res.status));
        // Poll every 15s for new snapshots
        setInterval(async () => {
            try {
                const r = await fetch('<?= url('/api/psi-action.php') ?>', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'list', site_id: SITE_ID})});
                const d = await r.json();
                const tracked = (d.summary || {}).urls_tracked || 0;
                if (tracked > 0) { window.location.reload(); }
                else { prog.innerHTML = 'Still running… (typically 5-7 min total)'; }
            } catch(e){}
        }, 15000);
    } catch (e) { prog.innerHTML = '<span style="color:#dc2626;">' + e.message + '</span>'; btn.disabled = false; }
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
