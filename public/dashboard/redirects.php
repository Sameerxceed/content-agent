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
.rd-quick { font-size:11px; padding:2px 8px; margin-left:4px; border:1px solid var(--border); background:#f8fafc; border-radius:10px; cursor:pointer; color:#475569; }
.rd-quick:hover { background:#e0e7ff; border-color:#6366f1; color:#312e81; }
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
/* Preflight cost-estimate modal */
.pf-mask { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:1000; align-items:center; justify-content:center; padding:20px; }
.pf-mask.open { display:flex; }
.pf-card { background:#fff; border-radius:8px; max-width:560px; width:100%; max-height:90vh; overflow:auto; box-shadow:0 20px 60px -10px rgba(0,0,0,0.3); }
.pf-head { padding:16px 20px 10px; border-bottom:1px solid var(--border); }
.pf-head h3 { margin:0; font-size:15px; color:var(--primary); }
.pf-head .sub { font-size:12px; color:var(--text-light); margin-top:3px; }
.pf-body { padding:14px 20px; }
.pf-step { padding:10px 0; border-bottom:1px solid #f1f5f9; display:grid; grid-template-columns: 22px 1fr auto; gap:10px; align-items:start; }
.pf-step:last-child { border-bottom:0; }
.pf-step .ix { font-size:11px; font-weight:600; color:#94a3b8; padding-top:1px; }
.pf-step .lbl { font-size:13px; color:#0f172a; font-weight:500; }
.pf-step .det { font-size:11px; color:var(--text-light); margin-top:2px; line-height:1.5; }
.pf-step .cost { font-family:ui-monospace, monospace; font-size:12px; color:#0f172a; white-space:nowrap; padding-top:1px; }
.pf-step .cost.free { color:#059669; }
.pf-step .cost.admin { color:#dc2626; font-weight:600; }
.pf-totals { background:#f8fafc; padding:11px 14px; border-radius:6px; margin-top:10px; display:grid; grid-template-columns: 1fr auto; gap:8px; font-size:12px; }
.pf-totals .k { color:var(--text-light); }
.pf-totals .v { font-family:ui-monospace, monospace; color:#0f172a; text-align:right; }
.pf-totals .v.admin { color:#dc2626; font-weight:700; }
.pf-totals .v.big { font-size:14px; font-weight:600; }
.pf-admin-banner { font-size:11px; background:#fef3c7; color:#92400e; padding:6px 10px; border-radius:4px; margin-top:8px; }
.pf-actions { padding:14px 20px; border-top:1px solid var(--border); display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap; }
.pf-actions .spacer { flex:1; }
.pf-loading { padding:30px; text-align:center; color:var(--text-light); font-size:13px; }
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
        <?php if ($crawl['total'] > 0): ?>
            <button class="btn btn-outline btn-sm" onclick="showNotFoundHelp()" title="On-brand 404 page that catches URLs not covered by your redirect map">
                ↓ 404 page
            </button>
        <?php endif; ?>
        <?php if ($summary['by_status']['approved'] ?? 0): ?>
            <?php if ($platform === 'shopify'): ?>
                <button class="btn btn-primary btn-sm" onclick="applyShopify(this)" title="Push approved redirects directly via Shopify Admin API">
                    ⚡ Apply <?= (int)($summary['by_status']['approved'] ?? 0) ?> to Shopify
                </button>
                <a class="btn btn-outline btn-sm" href="<?= url('/api/redirect-action.php?action=export_csv&site_id=' . $site_id) ?>">↓ CSV backup</a>
            <?php else: ?>
                <button class="btn btn-primary btn-sm" onclick="showDeployHelp()">↓ Download redirects</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div id="rd-progress"></div>

    <!-- Preflight cost-estimate modal. Opens when user clicks "Build redirect map".
         Shows scope + steps to everyone; dollar cost only to super-admins. -->
    <div id="pf-mask" class="pf-mask" onclick="if(event.target===this)pfClose()">
      <div class="pf-card">
        <div class="pf-head">
          <h3>Build redirect map — preview before we start</h3>
          <div class="sub">We'll check what's already cheap before spending on AI. You see what we'll do and (admin only) what it costs.</div>
        </div>
        <div id="pf-content" class="pf-body">
          <div class="pf-loading">Estimating…</div>
        </div>
        <div id="pf-actions" class="pf-actions" style="display:none;">
          <button class="btn btn-outline btn-sm" onclick="pfClose()">Cancel</button>
          <span class="spacer"></span>
          <button class="btn btn-outline btn-sm" onclick="pfRun(100)" id="pf-test-btn" title="Process 100 URLs first — cheap safety check that the AI matches look right for this site">Run 100 first as test</button>
          <button class="btn btn-accent btn-sm" onclick="pfRun(null)" id="pf-full-btn">Run the full job</button>
        </div>
      </div>
    </div>

    <?php if (($summary['by_status']['approved'] ?? 0) > 0 && $platform !== 'shopify'): ?>
    <!-- Platform-aware deploy panel — pick your stack, get the right download + steps -->
    <?php
        // Per-platform instructions. Keys are the platform_choice ids; each is
        // {file, action, steps[]}. Customer's site.platform pre-selects the right tab.
        $platform_help = [
            'nextjs' => [
                'label' => 'Next.js', 'file' => 'next.config.js', 'action' => 'export_next_config',
                'steps' => [
                    'Open your website code repo (the Next.js project for <strong>' . e($site['domain']) . '</strong>).',
                    'Find <code>next.config.js</code> at the project root. If you have no other settings, replace the whole file with our download. If you have existing settings, copy only the <code>async redirects() { … }</code> block into your existing config.',
                    'Commit + push. Vercel (or your host) auto-deploys.',
                ],
            ],
            'wordpress' => [
                'label' => 'WordPress', 'file' => 'wp-redirects.txt', 'action' => 'export_wordpress',
                'steps' => [
                    'Download <code>wp-redirects.txt</code> — it contains <strong>three options</strong> ranked easiest first.',
                    '<strong>Easiest:</strong> install the free "Redirection" plugin → Tools → Redirection → Import/Export → upload the CSV we also generate.',
                    'Or paste the <code>.htaccess</code> block from the file into your WP root <code>.htaccess</code> ABOVE the <code># BEGIN WordPress</code> line.',
                    'Or paste the <code>functions.php</code> snippet into your active child theme.',
                ],
            ],
            'apache' => [
                'label' => 'Apache / plain PHP / .htaccess', 'file' => '.htaccess', 'action' => 'export_apache',
                'steps' => [
                    'Download <code>.htaccess</code>.',
                    'SFTP or SSH into your server. Open the <code>.htaccess</code> file at your web root (the same directory as <code>index.php</code> or <code>index.html</code>).',
                    'If no <code>.htaccess</code> exists, upload ours. If one exists, paste the <code>&lt;IfModule mod_rewrite.c&gt;</code> block from our file into yours.',
                    'No restart needed — Apache reads <code>.htaccess</code> on every request.',
                ],
            ],
            'nginx' => [
                'label' => 'nginx', 'file' => 'redirects.conf', 'action' => 'export_nginx',
                'steps' => [
                    'Download <code>redirects.conf</code>.',
                    'Paste the location blocks inside your <code>server { … }</code> stanza — typically in <code>/etc/nginx/sites-available/&lt;your-site&gt;</code>.',
                    'Run <code>sudo nginx -t</code> to test the config, then <code>sudo systemctl reload nginx</code>.',
                ],
            ],
            'netlify' => [
                'label' => 'Netlify / Cloudflare Pages', 'file' => '_redirects', 'action' => 'export_netlify',
                'steps' => [
                    'Download <code>_redirects</code> (no file extension — keep it that way).',
                    'Place it at the root of your project (next to <code>index.html</code> or your build output directory like <code>public/</code> or <code>dist/</code>).',
                    'Commit + push. Netlify and Cloudflare Pages both auto-deploy.',
                ],
            ],
            'vercel' => [
                'label' => 'Vercel (non-Next.js)', 'file' => 'vercel.json', 'action' => 'export_vercel',
                'steps' => [
                    'Download <code>vercel.json</code>.',
                    'Place at your project root. If you already have a <code>vercel.json</code>, merge the <code>redirects</code> array into yours.',
                    'Commit + push. Vercel auto-deploys.',
                ],
            ],
            'custom' => [
                'label' => 'Other / hosted (Webflow, Wix, Squarespace, etc.)', 'file' => 'redirects.csv', 'action' => 'export_csv',
                'steps' => [
                    'Download <code>redirects.csv</code> — clean two-column from/to format.',
                    '<strong>Webflow:</strong> Project Settings → Publishing → 301 redirects → bulk import the CSV.',
                    '<strong>Wix:</strong> SEO menu → URL Redirect Manager → add each row manually (Wix has no bulk import).',
                    '<strong>Squarespace:</strong> Settings → Advanced → URL Mappings → paste each line in their format <code>/old-path -&gt; /new-path 301</code>.',
                    '<strong>Any developer:</strong> hand them the CSV — works for any stack.',
                ],
            ],
        ];
        $current_platform = isset($platform_help[$platform]) ? $platform : 'custom';
    ?>
    <div id="deploy-help" style="display:none; margin-top:14px; padding:14px 16px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; font-size:13px; line-height:1.6;">
        <div style="font-weight:600; color:#0c4a6e; margin-bottom:10px;">Deploy your <?= number_format((int)($summary['by_status']['approved'] ?? 0)) ?> redirects — pick your platform</div>
        <div style="display:flex; flex-wrap:wrap; gap:4px; margin-bottom:12px;">
            <?php foreach ($platform_help as $pkey => $p): ?>
                <button class="dh-pill <?= $pkey === $current_platform ? 'active' : '' ?>" onclick="dhSwitch('<?= e($pkey) ?>')">
                    <?= e($p['label']) ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($platform_help as $pkey => $p): ?>
            <div class="dh-panel" data-platform="<?= e($pkey) ?>" style="display:<?= $pkey === $current_platform ? 'block' : 'none' ?>;">
                <ol style="margin:0 0 8px; padding-left:18px; color:#075985;">
                    <?php foreach ($p['steps'] as $step): ?>
                        <li><?= $step ?></li>
                    <?php endforeach; ?>
                </ol>
                <a class="btn btn-primary btn-sm" href="<?= url('/api/redirect-action.php?action=' . $p['action'] . '&site_id=' . $site_id) ?>" style="margin-top:4px;">↓ Download <?= e($p['file']) ?></a>
            </div>
        <?php endforeach; ?>
        <button class="btn btn-outline btn-sm" onclick="document.getElementById('deploy-help').style.display='none'" style="margin-top:10px;">Close</button>
    </div>
    <style>
        .dh-pill { font-size:11px; padding:5px 10px; border:1px solid #bae6fd; background:#fff; color:#075985; border-radius:14px; cursor:pointer; }
        .dh-pill:hover { background:#e0f2fe; }
        .dh-pill.active { background:#0284c7; color:#fff; border-color:#0284c7; }
    </style>
    <script>
        function dhSwitch(key) {
            document.querySelectorAll('.dh-pill').forEach(b => b.classList.toggle('active', b.textContent.trim() === document.querySelector('.dh-pill[onclick*="' + key + '"]').textContent.trim()));
            document.querySelectorAll('.dh-panel').forEach(p => p.style.display = p.dataset.platform === key ? 'block' : 'none');
        }
    </script>
    <?php endif; ?>

    <?php if ($crawl['total'] > 0): ?>
    <!-- 404 page companion: catches URLs not in the redirect map (mistypes, bots, long-tail) -->
    <div id="notfound-help" style="display:none; margin-top:14px; padding:14px 16px; background:#fefce8; border:1px solid #fde047; border-radius:6px; font-size:13px; line-height:1.6;">
        <div style="font-weight:600; color:#713f12; margin-bottom:8px;">Branded 404 page — catches the URLs your redirect map doesn't cover</div>
        <p style="margin:0 0 8px; color:#713f12;">Even with redirects in place, some URLs will still hit your site as 404s — mistyped links, bot scans, very old long-tail URLs not yet in any archive. The default Next.js / Shopify 404 page looks generic and tells visitors nothing. <strong>This download is a custom 404 page on your brand</strong> with links to your most useful pages so visitors who land there have somewhere to go.</p>
        <?php if ($platform === 'shopify'): ?>
            <ol style="margin:8px 0; padding-left:18px; color:#713f12;">
                <li>Download the <code>ca-404.liquid</code> file below.</li>
                <li>In your Shopify admin → Online Store → Themes → <strong>Edit code</strong> on your published theme.</li>
                <li>Under <strong>Sections</strong>, click <strong>Add a new section</strong>, name it <code>ca-404</code>, paste the contents.</li>
                <li>In <code>templates/404.json</code> (or <code>templates/404.liquid</code> for older themes), add the section to the layout.</li>
                <li>Save. New 404s now render your on-brand page.</li>
            </ol>
        <?php else: ?>
            <ol style="margin:8px 0; padding-left:18px; color:#713f12;">
                <li>Download the <code>not-found.tsx</code> file below.</li>
                <li>Open your Next.js project repo and place it at <code>app/not-found.tsx</code> (App Router) or <code>pages/404.tsx</code> if you're still on Pages router (rename file accordingly).</li>
                <li>Commit + push. Vercel auto-deploys.</li>
                <li>Test by visiting any random URL like <code><?= e('https://' . $site['domain']) ?>/this-does-not-exist</code> — you should see your branded page.</li>
            </ol>
        <?php endif; ?>
        <a class="btn btn-primary btn-sm" href="<?= url('/api/redirect-action.php?action=export_not_found&site_id=' . $site_id) ?>" style="margin-top:4px;">↓ Download 404 page</a>
        <button class="btn btn-outline btn-sm" onclick="document.getElementById('notfound-help').style.display='none'" style="margin-left:6px;">Close</button>
    </div>
    <?php endif; ?>

    <?php if (($summary['by_status']['approved'] ?? 0) > 0 && $platform === 'shopify'): ?>
    <div style="margin-top:14px; padding:12px 14px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; font-size:12px; color:#075985; line-height:1.5;">
        <strong>Apply</strong> pushes redirects directly into your Shopify store via the Admin API — no manual upload needed. The <strong>CSV backup</strong> is in case you ever want a copy or want to import manually under Shopify admin → Online Store → Navigation → URL Redirects.
    </div>
    <?php endif; ?>
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

<?php
// Per-tab bulk approve — counts pending rows in the current bucket that have a
// target. Skips the All/Approved/Rejected/No-target tabs (nothing to approve
// there in one safe sweep). The CSV-of-this-tab button is broader: shows up
// on any filtered tab with rows that have a target, so the user can stage
// uploads bucket-by-bucket.
$bucket_approvable = 0;
$bucket_label      = '';
$bucket_total      = 0;
if (in_array($filter, ['pending','high','medium','low','approved'], true)) {
    $bucket_filter = [
        'pending'  => " AND status = 'pending'",
        'high'     => " AND confidence >= 85",
        'medium'   => " AND confidence BETWEEN 60 AND 84",
        'low'      => " AND (confidence < 60 OR confidence IS NULL)",
        'approved' => " AND status IN ('approved','applied')",
    ][$filter];
    // Approvable subset = pending with a target (skip Approved tab — those
    // are already approved).
    if ($filter !== 'approved') {
        $cnt = $db->prepare("SELECT COUNT(*) FROM redirect_map
                              WHERE site_id = ? AND status = 'pending' AND to_path IS NOT NULL{$bucket_filter}");
        $cnt->execute([$site_id]);
        $bucket_approvable = (int)$cnt->fetchColumn();
    }
    // Total exportable = any row in this bucket with a target (regardless of status).
    $cnt = $db->prepare("SELECT COUNT(*) FROM redirect_map
                          WHERE site_id = ? AND to_path IS NOT NULL{$bucket_filter}");
    $cnt->execute([$site_id]);
    $bucket_total = (int)$cnt->fetchColumn();
    $bucket_label = ['pending'=>'pending','high'=>'high-confidence','medium'=>'medium-confidence','low'=>'low-confidence','approved'=>'approved'][$filter];
}
?>
<?php if ($bucket_approvable > 0 || $bucket_total > 0): ?>
<div style="max-width:980px; margin:0 0 10px; display:flex; justify-content:flex-end; gap:6px;">
    <?php if ($bucket_total > 0): ?>
        <a class="btn btn-outline btn-sm"
           href="<?= url('/api/redirect-action.php?action=export_csv&site_id=' . $site_id . '&filter=' . urlencode($filter)) ?>"
           title="Download a CSV of just the <?= e($bucket_label) ?> rows (for staged Shopify import)">
            ↓ CSV (<?= $bucket_total ?> <?= e($bucket_label) ?>)
        </a>
    <?php endif; ?>
    <?php if ($bucket_approvable > 0): ?>
        <button class="btn btn-outline btn-sm" onclick="approveBucket(this, '<?= e($filter) ?>', <?= $bucket_approvable ?>)"
                title="Bulk-approve every pending row in this tab that already has a target">
            ✓ Approve all <?= $bucket_approvable ?> <?= e($bucket_label) ?>
        </button>
    <?php endif; ?>
</div>
<?php endif; ?>

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
                    <input class="rd-to-input" list="live-paths" data-original="<?= e($r['to_path'] ?? '') ?>" value="<?= e($r['to_path'] ?? '') ?>" placeholder="type a target page, e.g. /about" onblur="saveTarget(this, <?= (int)$r['id'] ?>)">
                    <?php if ($r['to_path'] === null): ?>
                        <span style="margin-left:8px; font-size:11px; color:var(--text-light);">Quick pick:</span>
                        <button type="button" class="rd-quick" onclick="setQuickTarget(this, <?= (int)$r['id'] ?>, '/')">Homepage</button>
                        <?php
                        // surface the most useful common destinations if the site has them
                        $common_targets = [];
                        foreach (['/contact', '/about', '/services', '/work', '/shop', '/collections/all'] as $c) {
                            if (in_array($c, $live_paths, true)) $common_targets[] = $c;
                        }
                        foreach ($common_targets as $c): ?>
                            <button type="button" class="rd-quick" onclick="setQuickTarget(this, <?= (int)$r['id'] ?>, '<?= e($c) ?>')"><?= e($c) ?></button>
                        <?php endforeach; ?>
                    <?php endif; ?>
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

// "Build redirect map" no longer fires immediately — it opens the preflight
// modal first. The modal shows what we're about to do, estimated runtime,
// and (admin only) the dollar cost. User picks "full" / "100 first" / cancel.
let pfBuildBtn = null;
let pfData = null;

async function buildMap(btn) {
    pfBuildBtn = btn;
    btn.disabled = true;
    const mask = document.getElementById('pf-mask');
    const content = document.getElementById('pf-content');
    const actions = document.getElementById('pf-actions');
    content.innerHTML = '<div class="pf-loading">Estimating… running the free heuristic pass over your queue.</div>';
    actions.style.display = 'none';
    mask.classList.add('open');

    try {
        const data = await call('preflight_build');
        pfData = data;
        renderPreflight(data);
        actions.style.display = 'flex';
    } catch (e) {
        content.innerHTML = '<div style="color:#dc2626;">' + e.message + '</div>';
        actions.style.display = 'flex';
        document.getElementById('pf-test-btn').disabled = true;
        document.getElementById('pf-full-btn').disabled = true;
    }
}

function pfClose() {
    document.getElementById('pf-mask').classList.remove('open');
    if (pfBuildBtn) { pfBuildBtn.disabled = false; pfBuildBtn = null; }
    pfData = null;
}

async function pfRun(limit) {
    const mask = document.getElementById('pf-mask');
    mask.classList.remove('open');
    const prog = document.getElementById('rd-progress');
    prog.style.display = 'block';
    const scopeLabel = limit ? `${limit}-URL test run` : 'full job';
    prog.innerHTML = `Building redirect map (${scopeLabel})… Claude is matching each dead URL to a living target. This runs in the background — you can leave this page.`;
    try {
        const body = limit ? { limit } : {};
        await call('build_map', body);
        pollUntilSettled();
    } catch (e) {
        prog.innerHTML = '<span style="color:#dc2626;">' + e.message + '</span>';
        if (pfBuildBtn) pfBuildBtn.disabled = false;
    }
}

function pfFmtUsd(n) {
    if (n === null || n === undefined) return '—';
    if (n === 0) return '$0.00';
    if (n < 0.01) return '$' + n.toFixed(4);
    if (n < 1)    return '$' + n.toFixed(3);
    return '$' + n.toFixed(2);
}

function pfFmtTime(sec) {
    if (sec < 60)  return sec + 's';
    if (sec < 3600) return Math.round(sec / 60) + ' min';
    const h = Math.floor(sec / 3600);
    const m = Math.round((sec % 3600) / 60);
    return h + 'h ' + (m ? m + 'm' : '');
}

function renderPreflight(data) {
    const dry = data.dry_run || {};
    const est = data.estimate || {};
    const isAdmin = !!data.is_admin;
    const steps = est.steps || [];

    let html = '';
    html += `<div style="font-size:12px; color:var(--text-light); margin-bottom:10px;">
        <strong style="color:#0f172a;">${(dry.to_process || 0).toLocaleString()} URLs</strong> queued to process
        ${dry.already_done > 0 ? `· <span title="Already approved/applied/rejected from a previous run">${dry.already_done.toLocaleString()} already done, skipped</span>` : ''}
        · matched against <strong>${(dry.live_inventory_size || 0).toLocaleString()}</strong> live URLs
    </div>`;

    steps.forEach((s, i) => {
        const isFree = !!s.free;
        const costClass = isFree ? 'free' : (isAdmin ? 'admin' : '');
        const costTxt   = isFree ? 'Free' : (isAdmin ? pfFmtUsd(s.est_cost) : (s.calls ? '—' : 'Free'));
        html += `<div class="pf-step">
            <div class="ix">${i + 1}.</div>
            <div>
                <div class="lbl">${s.label || ''}</div>
                <div class="det">${s.detail || ''}</div>
            </div>
            <div class="cost ${costClass}">${costTxt}</div>
        </div>`;
    });

    html += `<div class="pf-totals">
        <div class="k">Total URLs that need AI</div><div class="v">${(dry.needs_ai || 0).toLocaleString()}</div>
        <div class="k">Estimated runtime</div><div class="v big">${pfFmtTime(est.est_runtime_sec || 0)}</div>`;
    if (isAdmin) {
        html += `<div class="k">Estimated AI cost <span style="font-size:10px; color:#dc2626;">(admin only)</span></div>
                 <div class="v admin big">${pfFmtUsd(est.est_cost_usd)}</div>`;
        html += `<div class="pf-admin-banner" style="grid-column:1 / -1;">Customers do not see the dollar number — only the steps + runtime above.</div>`;
    } else {
        html += `<div class="k">Runs in the background</div><div class="v">You can leave this page</div>`;
    }
    html += `</div>`;

    if ((dry.needs_ai || 0) === 0 && (dry.to_process || 0) > 0) {
        html += `<div style="margin-top:10px; padding:9px 12px; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:4px; font-size:12px; color:#065f46;">
            Free heuristic match handles everything — no AI calls needed for this run.
        </div>`;
        document.getElementById('pf-test-btn').style.display = 'none';
    } else {
        document.getElementById('pf-test-btn').style.display = '';
    }

    if ((dry.to_process || 0) === 0) {
        html += `<div style="margin-top:10px; padding:9px 12px; background:#fef3c7; border:1px solid #fde68a; border-radius:4px; font-size:12px; color:#92400e;">
            Nothing left to process — every dead URL already has an approved, applied, or rejected redirect.
        </div>`;
        document.getElementById('pf-test-btn').disabled = true;
        document.getElementById('pf-full-btn').disabled = true;
    }

    document.getElementById('pf-content').innerHTML = html;
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

function showNotFoundHelp() {
    const help = document.getElementById('notfound-help');
    if (help) { help.style.display = 'block'; help.scrollIntoView({behavior: 'smooth', block: 'nearest'}); }
}

function showDeployHelp() {
    const help = document.getElementById('deploy-help');
    if (help) {
        help.style.display = 'block';
        help.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }
}

async function setQuickTarget(btn, id, target) {
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = '…';
    try {
        await call('set_target', {redirect_id: id, to_path: target});
        // Row should now be approved + have a target — easiest to refresh so
        // the row leaves the Pending filter cleanly.
        window.location.reload();
    } catch (e) { alert(e.message); btn.disabled = false; btn.textContent = orig; }
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

async function approveBucket(btn, filter, count) {
    const labels = {pending:'pending', high:'high-confidence', medium:'medium-confidence', low:'low-confidence'};
    const label = labels[filter] || filter;
    if (!confirm(`Approve all ${count} ${label} redirects that have a target?`)) return;
    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = 'Approving…';
    try {
        const r = await call('bulk_approve', {filter: filter});
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
    const prog = document.getElementById('rd-progress');
    try {
        const r = await call('apply_to_shopify');
        if (r.launched || r.already_running) {
            // Large set — background job. Poll redirect_runs.
            prog.style.display = 'block';
            prog.innerHTML = `Pushing ${(r.total_queued || '?').toLocaleString()} redirects to Shopify in the background — you can leave this page.`;
            btn.innerHTML = 'Pushing in background…';
            pollApplyStatus();
            return;
        }
        alert(`Pushed: ${r.pushed} new\nDuplicates: ${r.duplicates}\nErrors: ${r.errors}\nTotal: ${r.total}`);
        window.location.reload();
    } catch (e) {
        alert(e.message);
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

async function pollApplyStatus() {
    const prog = document.getElementById('rd-progress');
    const tick = async () => {
        try {
            const r = await call('apply_status');
            if (!r.run) return;
            if (r.run.status === 'running') {
                const proc = parseInt(r.run.items_processed || 0).toLocaleString();
                const ok = parseInt(r.run.items_succeeded || 0).toLocaleString();
                const errs = parseInt(r.run.items_failed || 0);
                prog.innerHTML = `Pushing to Shopify… ${proc} processed (${ok} ok${errs ? ', ' + errs + ' errors' : ''})`;
                setTimeout(tick, 5000);
                return;
            }
            if (r.run.status === 'done') {
                prog.innerHTML = `Done — ${parseInt(r.run.items_succeeded || 0).toLocaleString()} pushed of ${parseInt(r.run.items_processed || 0).toLocaleString()}. Refreshing…`;
                setTimeout(() => location.reload(), 1500);
                return;
            }
            if (r.run.status === 'failed') {
                prog.innerHTML = '<span style="color:#dc2626;">Apply failed: ' + (r.run.error || 'unknown') + '</span>';
                return;
            }
        } catch (e) { setTimeout(tick, 8000); }
    };
    tick();
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
        // set_target flips the row to approved on the backend — reflect that
        // immediately in the status pill so the UI doesn't lie until reload.
        const row = el.closest('.rd-row');
        if (row) {
            const pill = row.querySelector('.rd-status');
            if (pill) { pill.classList.remove('pending'); pill.classList.add('approved'); pill.textContent = 'approved'; }
            const conf = row.querySelector('.rd-confidence');
            if (conf) { conf.className = 'rd-confidence high'; conf.textContent = '100%'; }
        }
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
