<?php
/**
 * Dashboard — Site Health hub.
 *
 * Phase 2 IA consolidation: rolls up the five hygiene modules (Redirects,
 * Page Speed, Schema, Freshness, Fast indexing) into one umbrella page so
 * the sidebar shrinks from 16 flat items to a "Maintain" group.
 *
 * Each capability gets its own card with live status numbers + an "Open"
 * link to the existing module page. The module pages stay where they are —
 * this page is a hub, not a rewrite. That keeps Phase 2 a low-risk IA
 * change instead of a 5-page refactor.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/redirect_map_builder.php';
require_once __DIR__ . '/../../includes/wayback_harvester.php';
require_once __DIR__ . '/../../includes/psi_runner.php';
require_once __DIR__ . '/../../includes/schema_auditor.php';
require_once __DIR__ . '/../../includes/content_freshness.php';
require_once __DIR__ . '/../../includes/sitemap_indexnow.php';
require_once __DIR__ . '/../../includes/image_auditor.php';
require_once __DIR__ . '/../../includes/outbound_link_checker.php';
require_once __DIR__ . '/../../includes/gmc_api.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

$rd      = rmb_site_summary($db, $site_id);
$archive = wayback_site_summary($db, $site_id);
$psi     = psi_site_summary($db, $site_id);
$sch     = sch_site_summary($db, $site_id);
$fresh   = cf_site_summary($db, $site_id);
// Just check if the key has been generated. We don't auto-create here —
// that side effect belongs on the IndexNow page itself.
$_notes = json_decode($site['notes'] ?? '{}', true) ?: [];
$indexnow_key = $_notes['indexnow_key'] ?? null;

// Phase 4 modules — wrap calls in try/catch because tables may not exist
// yet if Phase 4 migrations haven't been run.
$image_sum    = []; $outbound_sum = []; $gmc_sum = null;
try { $image_sum    = img_audit_site_summary($db, $site_id); }    catch (Throwable $e) {}
try { $outbound_sum = outbound_site_summary($db, $site_id); }     catch (Throwable $e) {}
try { $gmc_sum      = gmc_site_summary($db, $site_id); }          catch (Throwable $e) {}
$gmc_merchant_id = (string)($_notes['gmc_merchant_id'] ?? '');

$page_title = 'Site Health — ' . $site['name'];
ob_start();
?>
<style>
.sh-head { margin-bottom:14px; max-width:980px; }
.sh-head h2 { margin:0 0 4px; font-size:11px; text-transform:uppercase; letter-spacing:0.6px; color:var(--primary); }
.sh-head .desc { font-size:13px; color:#475569; margin:0; max-width:760px; line-height:1.5; }
.sh-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:10px; max-width:1100px; }
.sh-card { padding:14px 16px; background:#fff; border:1px solid var(--border); border-radius:8px; display:flex; flex-direction:column; gap:8px; }
.sh-card .top { display:flex; justify-content:space-between; align-items:start; gap:8px; }
.sh-card .title { font-size:14px; font-weight:600; color:#0f172a; line-height:1.3; }
.sh-card .title .ico { display:inline-block; width:22px; }
.sh-card .one-line { font-size:11px; color:#64748b; line-height:1.5; }
.sh-card .stats { display:grid; grid-template-columns:1fr 1fr; gap:6px; padding:8px 0; border-top:1px solid #f1f5f9; border-bottom:1px solid #f1f5f9; }
.sh-card .stat .k { font-size:10px; text-transform:uppercase; letter-spacing:0.4px; color:#94a3b8; }
.sh-card .stat .v { font-size:18px; font-weight:600; color:#0f172a; line-height:1.1; margin-top:2px; font-family:ui-monospace, monospace; }
.sh-card .stat .v.warn { color:#d97706; }
.sh-card .stat .v.bad  { color:#dc2626; }
.sh-card .stat .v.good { color:#059669; }
.sh-card .open { font-size:12px; padding:6px 12px; background:var(--primary); color:#fff; text-decoration:none; border-radius:4px; align-self:flex-start; font-weight:500; }
.sh-card .open:hover { opacity:0.9; }
.sh-card .pill { font-size:10px; padding:2px 7px; border-radius:10px; background:#f1f5f9; color:#475569; }
.sh-card .pill.ok { background:#d1fae5; color:#065f46; }
.sh-card .pill.warn { background:#fef3c7; color:#92400e; }
.sh-card .pill.bad { background:#fee2e2; color:#991b1b; }
</style>

<div class="sh-head">
    <h2>Maintain — Site Health</h2>
    <p class="desc">
        Keep <strong><?= e($site['domain']) ?></strong> healthy: route dead URLs to live ones, watch Core Web Vitals,
        keep schema valid, refresh stale content, ping search engines on every change.
        Each tile below opens its own workspace.
    </p>
</div>

<div class="sh-grid">

    <!-- Redirects -->
    <div class="sh-card">
        <div class="top">
            <div class="title"><span class="ico">&#8631;</span> 301 Redirects</div>
            <?php
                $pending = (int)($rd['by_status']['pending'] ?? 0);
                $approved = (int)($rd['by_status']['approved'] ?? 0);
                $applied  = (int)($rd['by_status']['applied']  ?? 0);
                $needs_attention = $pending;
                $pill_cls = $needs_attention > 0 ? 'warn' : ($approved + $applied > 0 ? 'ok' : '');
                $pill_txt = $needs_attention > 0 ? "{$needs_attention} pending" : ($approved + $applied > 0 ? 'up to date' : 'not run yet');
            ?>
            <span class="pill <?= $pill_cls ?>"><?= $pill_txt ?></span>
        </div>
        <div class="one-line">Map dead historical URLs to living targets so visitors and search engines never hit a 404.</div>
        <div class="stats">
            <div class="stat"><div class="k">Approved</div><div class="v good"><?= number_format($approved + $applied) ?></div></div>
            <div class="stat"><div class="k">Dead URLs queued</div><div class="v <?= ($archive['dead_urls'] ?? 0) > 100 ? 'warn' : '' ?>"><?= number_format((int)($archive['dead_urls'] ?? 0)) ?></div></div>
        </div>
        <a class="open" href="<?= url('/dashboard/redirects.php?site=' . $site_id) ?>">Open Redirects &rarr;</a>
    </div>

    <!-- Page Speed -->
    <div class="sh-card">
        <div class="top">
            <div class="title"><span class="ico">&#9889;</span> Page Speed (Core Web Vitals)</div>
            <?php
                $psi_urls = (int)($psi['urls_tracked'] ?? 0);
                $pill_cls = $psi_urls > 0 ? 'ok' : '';
                $pill_txt = $psi_urls > 0 ? 'baseline set' : 'not run yet';
            ?>
            <span class="pill <?= $pill_cls ?>"><?= $pill_txt ?></span>
        </div>
        <div class="one-line">Track LCP / INP / CLS for the URLs that matter. Field data from CrUX + lab data from Lighthouse.</div>
        <div class="stats">
            <div class="stat"><div class="k">URLs tracked</div><div class="v"><?= number_format((int)($psi['urls_tracked'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k">Avg perf (mobile)</div><div class="v" style="font-size:13px;"><?= $psi['avg_perf_mobile'] !== null ? (int)$psi['avg_perf_mobile'] . '/100' : '—' ?></div></div>
        </div>
        <a class="open" href="<?= url('/dashboard/cwv.php?site=' . $site_id) ?>">Open Page Speed &rarr;</a>
    </div>

    <!-- Schema Audit -->
    <div class="sh-card">
        <div class="top">
            <div class="title"><span class="ico">&#10070;</span> Schema (JSON-LD)</div>
            <?php
                $broken    = (int)($sch['by_status']['broken']       ?? 0);
                $degraded  = (int)($sch['by_status']['degraded']     ?? 0);
                $ok        = (int)($sch['by_status']['ok']           ?? 0);
                $fetch_failed = (int)($sch['by_status']['fetch_failed'] ?? 0);
                $issue_count = $broken + $degraded + $fetch_failed;
                $sch_total = (int)($sch['total'] ?? 0);
                $pill_cls = $broken > 0 ? 'bad' : ($ok > 0 ? 'ok' : '');
                $pill_txt = $broken > 0 ? "{$broken} broken" : ($ok > 0 ? 'all valid' : 'not audited');
            ?>
            <span class="pill <?= $pill_cls ?>"><?= $pill_txt ?></span>
        </div>
        <div class="one-line">Audit Article / Product / FAQ / Breadcrumb / Organization markup. Broken schema = lost rich snippets.</div>
        <div class="stats">
            <div class="stat"><div class="k">Valid</div><div class="v good"><?= number_format($ok) ?></div></div>
            <div class="stat"><div class="k">Issues</div><div class="v <?= $broken > 0 ? 'bad' : '' ?>"><?= number_format($issue_count) ?></div></div>
        </div>
        <a class="open" href="<?= url('/dashboard/schema.php?site=' . $site_id) ?>">Open Schema audit &rarr;</a>
    </div>

    <!-- Content Freshness -->
    <div class="sh-card">
        <div class="top">
            <div class="title"><span class="ico">&#127811;</span> Content Freshness</div>
            <?php
                $stale = (int)($fresh['pending'] ?? 0);
                $queued = (int)($fresh['queued'] ?? 0);
                $total_scored = (int)($fresh['total'] ?? 0);
                $pill_cls = $stale > 5 ? 'warn' : ($stale > 0 ? 'ok' : '');
                $pill_txt = $stale > 0 ? "{$stale} need refresh" : 'all fresh';
            ?>
            <span class="pill <?= $pill_cls ?>"><?= $pill_txt ?></span>
        </div>
        <div class="one-line">Score posts on age + year-in-text + traffic decline. Stale ones queue a refresh plan-item automatically.</div>
        <div class="stats">
            <div class="stat"><div class="k">Posts scored</div><div class="v"><?= number_format($total_scored) ?></div></div>
            <div class="stat"><div class="k">Refresh queue</div><div class="v <?= $stale > 5 ? 'warn' : '' ?>"><?= number_format($stale) ?></div></div>
        </div>
        <a class="open" href="<?= url('/dashboard/freshness.php?site=' . $site_id) ?>">Open Freshness &rarr;</a>
    </div>

    <!-- Fast indexing (IndexNow) -->
    <div class="sh-card">
        <div class="top">
            <div class="title"><span class="ico">&#128640;</span> Fast indexing (IndexNow)</div>
            <?php
                $key_set = !empty($indexnow_key);
                $pill_cls = $key_set ? 'ok' : 'warn';
                $pill_txt = $key_set ? 'key configured' : 'needs setup';
            ?>
            <span class="pill <?= $pill_cls ?>"><?= $pill_txt ?></span>
        </div>
        <div class="one-line">Push every publish to Bing / Yandex / Cloudflare / Naver the second it lands — minutes to indexed, not days.</div>
        <div class="stats">
            <div class="stat"><div class="k">Key</div><div class="v" style="font-size:12px;font-family:ui-monospace,monospace;"><?= $key_set ? substr($indexnow_key, 0, 10) . '…' : '—' ?></div></div>
            <div class="stat"><div class="k">Status</div><div class="v <?= $key_set ? 'good' : 'warn' ?>" style="font-size:13px;"><?= $key_set ? 'Active' : 'Setup' ?></div></div>
        </div>
        <a class="open" href="<?= url('/dashboard/indexnow.php?site=' . $site_id) ?>">Open Fast indexing &rarr;</a>
    </div>

    <!-- 404 page generator (lives under redirects but exposed separately for discoverability) -->
    <div class="sh-card">
        <div class="top">
            <div class="title"><span class="ico">&#10071;</span> Branded 404 page</div>
            <span class="pill">on-demand</span>
        </div>
        <div class="one-line">Generate a Next.js / Shopify Liquid 404 page that suggests live URLs, so missed redirects still land softly.</div>
        <div class="stats">
            <div class="stat"><div class="k">Platform</div><div class="v" style="font-size:13px;"><?= e(ucfirst($site['platform'] ?? 'custom')) ?></div></div>
            <div class="stat"><div class="k">Coverage</div><div class="v good">Auto</div></div>
        </div>
        <a class="open" href="<?= url('/dashboard/redirects.php?site=' . $site_id) ?>#notfound-help">Generate 404 page &rarr;</a>
    </div>

    <!-- Image SEO (Phase 4) -->
    <div class="sh-card">
        <div class="top">
            <div class="title"><span class="ico">&#127912;</span> Image SEO</div>
            <?php
                $img_total = (int)($image_sum['total'] ?? 0);
                $img_issues = $img_total - (int)($image_sum['by_status']['good'] ?? 0);
                $pill_cls = $img_total === 0 ? '' : ($img_issues > 0 ? 'warn' : 'ok');
                $pill_txt = $img_total === 0 ? 'not run yet' : ($img_issues > 0 ? "{$img_issues} issues" : 'all good');
            ?>
            <span class="pill <?= $pill_cls ?>"><?= $pill_txt ?></span>
        </div>
        <div class="one-line">Missing alt text, oversized files, missing dimensions — tank LCP, accessibility, and Google Image traffic.</div>
        <div class="stats">
            <div class="stat"><div class="k">Images checked</div><div class="v"><?= number_format($img_total) ?></div></div>
            <div class="stat"><div class="k">Issues</div><div class="v <?= $img_issues > 0 ? 'warn' : '' ?>"><?= number_format($img_issues) ?></div></div>
        </div>
        <a class="open" href="<?= url('/dashboard/image-seo.php?site=' . $site_id) ?>">Open Image SEO &rarr;</a>
    </div>

    <!-- Outbound links (Phase 4) -->
    <div class="sh-card">
        <div class="top">
            <div class="title"><span class="ico">&#128279;</span> Outbound links</div>
            <?php
                $ob_total = (int)($outbound_sum['total'] ?? 0);
                $ob_broken = (int)($outbound_sum['by_status']['broken'] ?? 0);
                $pill_cls = $ob_total === 0 ? '' : ($ob_broken > 0 ? 'bad' : 'ok');
                $pill_txt = $ob_total === 0 ? 'not run yet' : ($ob_broken > 0 ? "{$ob_broken} broken" : 'all healthy');
            ?>
            <span class="pill <?= $pill_cls ?>"><?= $pill_txt ?></span>
        </div>
        <div class="one-line">Every link in your posts that points to another site — broken outbounds erode reader trust and page SEO.</div>
        <div class="stats">
            <div class="stat"><div class="k">Links checked</div><div class="v"><?= number_format($ob_total) ?></div></div>
            <div class="stat"><div class="k">Broken</div><div class="v <?= $ob_broken > 0 ? 'bad' : '' ?>"><?= number_format($ob_broken) ?></div></div>
        </div>
        <a class="open" href="<?= url('/dashboard/outbound-links.php?site=' . $site_id) ?>">Open Outbound links &rarr;</a>
    </div>

    <!-- Merchant Center (Phase 4, Module 4) -->
    <div class="sh-card">
        <div class="top">
            <div class="title"><span class="ico">&#128722;</span> Merchant Center</div>
            <?php
                $gmc_products = (int)($gmc_sum['products'] ?? 0);
                $gmc_errors   = (int)($gmc_sum['errors']   ?? 0);
                $pill_cls = $gmc_merchant_id === '' ? 'warn' : ($gmc_errors > 0 ? 'bad' : ($gmc_products > 0 ? 'ok' : ''));
                $pill_txt = $gmc_merchant_id === '' ? 'needs setup' : ($gmc_errors > 0 ? "{$gmc_errors} errors" : ($gmc_products > 0 ? 'feed healthy' : 'not synced'));
            ?>
            <span class="pill <?= $pill_cls ?>"><?= $pill_txt ?></span>
        </div>
        <div class="one-line">Per-product diagnostics from Google Merchant Center — disapprovals, missing GTIN, image issues, policy violations.</div>
        <div class="stats">
            <div class="stat"><div class="k">Products synced</div><div class="v"><?= number_format($gmc_products) ?></div></div>
            <div class="stat"><div class="k">With issues</div><div class="v <?= ($gmc_sum['with_issues'] ?? 0) > 0 ? 'warn' : '' ?>"><?= number_format((int)($gmc_sum['with_issues'] ?? 0)) ?></div></div>
        </div>
        <a class="open" href="<?= url('/dashboard/gmc.php?site=' . $site_id) ?>">Open Merchant Center &rarr;</a>
    </div>

</div>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
