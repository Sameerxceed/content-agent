<?php
/**
 * Dashboard — SEO Snapshot.
 * The default landing tab for the SEO section: at-a-glance score, the
 * Auto-Fix/Improvements summary, and the Fix Files download/deploy panel.
 * Drill-down details (Issues, Approvals, AI Readiness, Full Report) live in
 * the sibling tabs.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/fix-generator.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';

$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

// ── Data ────────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 1');
$stmt->execute([$site_id]);
$audit = $stmt->fetch();
$has_audit = !empty($audit);

// Open issues on the LATEST audit
$open_issues = 0;
if ($audit) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM seo_issues WHERE audit_id = ? AND status = "open"');
    $stmt->execute([$audit['id']]);
    $open_issues = (int)$stmt->fetchColumn();
}

// page_seo improvements grouped by status
$stmt = $db->prepare("SELECT status, COUNT(*) AS cnt FROM page_seo WHERE site_id = ? GROUP BY status");
$stmt->execute([$site_id]);
$page_seo_by_status = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($stmt->fetchAll() as $r) $page_seo_by_status[$r['status']] = (int)$r['cnt'];
$improvements_pending  = $page_seo_by_status['pending'];
$improvements_approved = $page_seo_by_status['approved'];
$fixes_ready           = $improvements_pending + $improvements_approved;

// Score history — one bar per day (latest score wins), to clean up the chart
// that previously showed multiple bars for days with several manual reruns.
$stmt = $db->prepare(
    'SELECT MAX(score) AS score, DATE_FORMAT(MAX(run_at), "%d %b") AS label, DATE(run_at) AS day
     FROM seo_audits WHERE site_id = ? GROUP BY DATE(run_at) ORDER BY day ASC'
);
$stmt->execute([$site_id]);
$score_history = $stmt->fetchAll();

$score_min = $score_history ? min(array_column($score_history, 'score')) : null;
$score_max = $score_history ? max(array_column($score_history, 'score')) : null;
$score_flat = ($score_min !== null && $score_min === $score_max);

$platform_info = fix_get_platform_info($site);
$has_ftp = !empty($site['server_host']);

// Subtitle for the Auto-Fix card — same priority as site.php used.
if ($open_issues > 0) {
    $fix_subtitle = "{$open_issues} live-site issues to fix";
} elseif ($improvements_pending > 0 && $improvements_approved > 0) {
    $fix_subtitle = "{$improvements_pending} improvements awaiting review · {$improvements_approved} approved & live in snippet";
} elseif ($improvements_pending > 0) {
    $fix_subtitle = "{$improvements_pending} SEO improvements awaiting your review";
} elseif ($improvements_approved > 0) {
    $fix_subtitle = "{$improvements_approved} approved improvements live in snippet";
} else {
    $fix_subtitle = 'Nothing to fix or improve';
}
$fix_dot = $open_issues > 0 ? 'pending' : ($fixes_ready > 0 ? 'done' : 'not-done');

$page_title = 'SEO/AEO — ' . $site['name'];

ob_start();

// Persistent site workflow stepper at top so user knows where they are.
$stepper_active = 'seo';
include __DIR__ . '/_site_stepper.php';

// SEO sub-tabs (Snapshot / Issues / Approvals / AI Readiness / Full Report)
$filter_site = $site_id;
$active = 'snapshot';
include __DIR__ . '/_health_tabs.php';
?>

<style>
.snap-section { background:#fff; border:1px solid var(--border); border-radius:8px; margin-bottom:12px; overflow:hidden; }
.snap-header { padding:12px 16px; display:flex; align-items:center; justify-content:space-between; }
.snap-status { display:flex; align-items:center; gap:10px; }
.snap-status .dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.snap-status .dot.done { background:#10b981; }
.snap-status .dot.pending { background:#f59e0b; }
.snap-status .dot.not-done { background:#e2e8f0; }
.snap-title { font-weight:600; font-size:14px; color:var(--primary); }
.snap-subtitle { font-size:12px; color:#94a3b8; margin-top:2px; }
.snap-body { padding:0 16px 14px; }
.snap-action { padding:7px 14px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; color:#fff; text-decoration:none; display:inline-block; }
.snap-link { font-size:12px; color:var(--primary); text-decoration:none; }
.snap-link:hover { text-decoration:underline; }
</style>

<!-- ── Audit Snapshot ─────────────────────────────────────── -->
<div class="snap-section">
    <div class="snap-header">
        <div class="snap-status">
            <div class="dot <?= $has_audit ? 'done' : 'not-done' ?>"></div>
            <div>
                <div class="snap-title">📊 SEO Audit</div>
                <div class="snap-subtitle"><?= $has_audit ? "Score: {$audit['score']}/100 — {$audit['total_issues']} issues, {$audit['pages_crawled']} pages · scanned " . format_date($audit['run_at'], 'd M Y') : 'Not audited yet' ?></div>
            </div>
        </div>
        <?php if ($has_audit): ?>
            <a href="<?= url('/dashboard/seo-audit.php?site=' . $site_id) ?>" class="snap-link">View issues →</a>
        <?php endif; ?>
    </div>
    <?php if ($has_audit): ?>
    <div class="snap-body">
        <?php if ($score_flat && (int)$audit['score'] === 100 && count($score_history) >= 2): ?>
            <div style="font-size:12px;color:#065f46;background:#ecfdf5;border-left:3px solid #10b981;padding:8px 12px;border-radius:4px;">
                ✓ Score 100/100 — held steady across <?= count($score_history) ?> scans. Nothing to plot.
            </div>
        <?php elseif (count($score_history) > 1): ?>
            <div style="display:flex;align-items:flex-end;gap:4px;height:36px;margin-top:4px;">
                <?php foreach ($score_history as $h):
                    $bh = max(4, ((int)$h['score'] / 100) * 32);
                    $bc = (int)$h['score'] >= 80 ? '#10b981' : ((int)$h['score'] >= 50 ? '#f59e0b' : '#ef4444');
                ?>
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;" title="<?= e($h['label']) ?>: <?= (int)$h['score'] ?>/100">
                    <div style="width:100%;max-width:32px;height:<?= $bh ?>px;background:<?= $bc ?>;border-radius:2px 2px 0 0;"></div>
                    <span style="font-size:8px;color:#94a3b8;"><?= e($h['label']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Auto-Fix & SEO Improvements ────────────────────────── -->
<div class="snap-section">
    <div class="snap-header">
        <div class="snap-status">
            <div class="dot <?= $fix_dot ?>"></div>
            <div>
                <div class="snap-title">🤖 Auto-Fix &amp; SEO Improvements</div>
                <div class="snap-subtitle"><?= $fix_subtitle ?></div>
            </div>
        </div>
        <?php if ($improvements_pending > 0): ?>
            <a href="<?= url('/dashboard/seo-approvals.php?site=' . $site_id) ?>" class="snap-action" style="background:#f59e0b;">Review <?= $improvements_pending ?></a>
        <?php endif; ?>
    </div>
    <div class="snap-body">
        <?php if ($open_issues === 0 && $fixes_ready > 0): ?>
            <div style="background:#ecfdf5;border-left:3px solid #10b981;padding:8px 12px;border-radius:4px;font-size:12px;margin-bottom:10px;color:#065f46;">
                ✓ The SEO audit shows <strong>0 broken issues</strong> on your live site. The numbers below are <em>enhancements</em> ContentAgent generated to push your SEO further — not bugs.
            </div>
        <?php endif; ?>
        <?php if ($fixes_ready > 0):
            $breakdown_parts = [];
            if ($improvements_pending > 0)  $breakdown_parts[] = $improvements_pending . ' pending review';
            if ($improvements_approved > 0) $breakdown_parts[] = $improvements_approved . ' approved';
            $breakdown = $breakdown_parts ? ' (' . implode(', ', $breakdown_parts) . ')' : '';
        ?>
            <div style="font-size:13px;margin-bottom:10px;">
                <strong><?= $fixes_ready ?></strong> page-level SEO rules saved<?= $breakdown ?>.
                To serve them on your live site, add this snippet:
            </div>
            <div style="background:#1a1a2e;color:#10b981;padding:10px 14px;border-radius:6px;font-family:monospace;font-size:11px;cursor:pointer;word-break:break-all;" onclick="navigator.clipboard.writeText(this.innerText.trim());alert('Copied!')">
                &lt;script src="<?= e(config('app_url')) ?>/snippet/contentagent.js" data-site="<?= e($site['domain']) ?>"&gt;&lt;/script&gt;
            </div>
            <div class="text-sm text-muted mt-2">Click to copy. Or add FTP credentials in <a href="<?= url('/dashboard/setup.php?site=' . $site_id . '&tab=server') ?>">Setup → Server</a> for direct deployment.</div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Fix Files (download / deploy) ──────────────────────── -->
<?php if ($has_audit): ?>
<div class="snap-section">
    <div class="snap-header">
        <div class="snap-status">
            <div class="dot <?= $fixes_ready > 0 ? 'done' : 'not-done' ?>"></div>
            <div>
                <div class="snap-title">📦 Fix Files</div>
                <div class="snap-subtitle">Download or deploy fix files for <?= e($site['platform'] ?: 'your site') ?></div>
            </div>
        </div>
        <a href="<?= url('/api/download-fix.php?site_id=' . $site_id . '&type=all') ?>" class="snap-action" style="background:#3b82f6;">Download All (ZIP)</a>
    </div>
    <div class="snap-body">
        <div style="font-size:13px;margin-bottom:6px;color:#6b7280;">Platform: <strong><?= e($site['platform'] ?: 'custom') ?></strong> | Theme: <strong><?= e($site['theme_name'] ?: 'default') ?></strong></div>
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid #e5e7eb;">
                    <th style="padding:6px 8px;">File</th>
                    <th style="padding:6px 8px;">Upload To</th>
                    <th style="padding:6px 8px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:6px 8px;">🔧 Header SEO Snippet</td>
                    <td style="padding:6px 8px;font-family:monospace;font-size:11px;color:#6b7280;"><?= e($platform_info['header_path']) ?></td>
                    <td style="padding:6px 8px;">
                        <a href="<?= url('/api/download-fix.php?site_id=' . $site_id . '&type=header') ?>" style="background:#3b82f6;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;text-decoration:none;">Download</a>
                        <?php if ($has_ftp): ?>
                            <button onclick="deployFix('header')" style="background:#10b981;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;border:none;cursor:pointer;margin-left:4px;">Deploy</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:6px 8px;">🗺️ sitemap.xml</td>
                    <td style="padding:6px 8px;font-family:monospace;font-size:11px;color:#6b7280;"><?= e($platform_info['sitemap_path']) ?></td>
                    <td style="padding:6px 8px;">
                        <a href="<?= url('/api/download-fix.php?site_id=' . $site_id . '&type=sitemap') ?>" style="background:#3b82f6;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;text-decoration:none;">Download</a>
                        <?php if ($has_ftp): ?>
                            <button onclick="deployFix('sitemap')" style="background:#10b981;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;border:none;cursor:pointer;margin-left:4px;">Deploy</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:6px 8px;">🤖 robots.txt</td>
                    <td style="padding:6px 8px;font-family:monospace;font-size:11px;color:#6b7280;"><?= e($platform_info['robots_path']) ?></td>
                    <td style="padding:6px 8px;">
                        <a href="<?= url('/api/download-fix.php?site_id=' . $site_id . '&type=robots') ?>" style="background:#3b82f6;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;text-decoration:none;">Download</a>
                        <?php if ($has_ftp): ?>
                            <button onclick="deployFix('robots')" style="background:#10b981;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;border:none;cursor:pointer;margin-left:4px;">Deploy</button>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php if (!$has_ftp): ?>
            <div class="text-sm text-muted mt-2">Add FTP/SFTP credentials in <a href="<?= url('/dashboard/setup.php?site=' . $site_id . '&tab=server') ?>">Setup → Server</a> to enable one-click deploy.</div>
        <?php endif; ?>
        <div id="deploy-result" style="display:none;margin-top:8px;padding:8px 12px;border-radius:6px;font-size:13px;"></div>
    </div>
</div>
<?php endif; ?>

<script>
function deployFix(type) {
    const out = document.getElementById('deploy-result');
    out.style.display = 'block';
    out.style.background = '#fff7ed';
    out.style.color = '#9a3412';
    out.textContent = 'Deploying ' + type + '...';
    fetch('<?= url('/api/deploy-fix.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({site_id: <?= $site_id ?>, type: type}),
    }).then(r => r.json()).then(j => {
        if (j.success) {
            out.style.background = '#ecfdf5';
            out.style.color = '#065f46';
            out.textContent = '✓ ' + (j.message || 'Deployed successfully.');
        } else {
            out.style.background = '#fef2f2';
            out.style.color = '#991b1b';
            out.textContent = '✗ ' + (j.error || 'Deploy failed.');
        }
    }).catch(e => {
        out.style.background = '#fef2f2';
        out.style.color = '#991b1b';
        out.textContent = '✗ ' + e.message;
    });
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
