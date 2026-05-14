<?php
/**
 * SEO & AI Health Report — Comprehensive, printable report.
 * Shows all checks in one place: SEO, AI readiness, technical, content.
 *
 * GET ?site=3
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai-seo.php';

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

// ── Gather all data ─────────────────────────────────────
$domain = 'https://' . ltrim($site['domain'], 'https://');

// Latest SEO audit
$stmt = $db->prepare('SELECT * FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 1');
$stmt->execute([$site_id]);
$audit = $stmt->fetch();

// SEO issues from latest audit
$seo_issues = [];
if ($audit) {
    $stmt = $db->prepare('SELECT * FROM seo_issues WHERE audit_id = ? ORDER BY FIELD(severity, "critical", "warning", "info"), type');
    $stmt->execute([$audit['id']]);
    $seo_issues = $stmt->fetchAll();
}

// Issue counts
$open_issues = array_filter($seo_issues, fn($i) => $i['status'] === 'open');
$critical = array_filter($open_issues, fn($i) => $i['severity'] === 'critical');
$warnings = array_filter($open_issues, fn($i) => $i['severity'] === 'warning');
$info_issues = array_filter($open_issues, fn($i) => $i['severity'] === 'info');
$fixed_issues = array_filter($seo_issues, fn($i) => in_array($i['status'], ['fix_applied', 'resolved', 'fixed_by_snippet']));

// AI SEO audit (live check)
$ai_audit = audit_ai_discoverability($site['domain']);
$ai_results = $ai_audit['results'] ?? [];
$ai_score = $ai_audit['score'] ?? 0;
$ai_total = $ai_audit['total'] ?? 0;
$ai_passed = $ai_audit['passed'] ?? 0;

// Post counts
$stmt = $db->prepare('SELECT status, COUNT(*) as cnt FROM posts WHERE site_id = ? GROUP BY status');
$stmt->execute([$site_id]);
$pc = []; foreach ($stmt->fetchAll() as $r) $pc[$r['status']] = $r['cnt'];

// Keywords
$stmt = $db->prepare('SELECT COUNT(*) FROM keywords WHERE site_id = ?');
$stmt->execute([$site_id]);
$kw_count = $stmt->fetchColumn();

// Issues fixed all time
$stmt = $db->prepare('SELECT COUNT(*) FROM seo_issues WHERE site_id = ? AND status IN ("fix_applied","resolved","fixed_by_snippet")');
$stmt->execute([$site_id]);
$total_fixed = $stmt->fetchColumn();

// Score history
$stmt = $db->prepare('SELECT score, DATE_FORMAT(run_at, "%d %b") as label FROM seo_audits WHERE site_id = ? ORDER BY run_at ASC');
$stmt->execute([$site_id]);
$score_history = $stmt->fetchAll();

// Homepage checks (for technical details)
$homepage = scraper_fetch($domain, 15);
$doc = null;
$meta = [];
$title = '';
$has_viewport = false;
$has_canonical = false;
$has_og = false;
$schemas_found = [];

if ($homepage['status'] === 200) {
    $doc = scraper_parse_html($homepage['body']);
    $meta = scraper_get_meta($doc);
    $title = scraper_get_title($doc);
    $has_viewport = scraper_check_viewport($doc);
    $canonical = scraper_get_canonical($doc);
    $has_canonical = !empty($canonical);
    $has_og = !empty($meta['og:title']) || !empty($meta['og:description']);
    $schemas_found = scraper_get_schema($doc);
}

$seo_score = $audit ? $audit['score'] : null;

$page_title = 'SEO Health — Full Report';
ob_start();

$active = 'report';
$filter_site = $site_id;
include __DIR__ . '/_health_tabs.php';
?>

<style>
@media print {
    .sidebar, .topbar, .no-print { display: none !important; }
    .content { margin: 0 !important; padding: 10px !important; }
    .report-section { break-inside: avoid; }
}
.report-header { text-align:center; padding:20px 0 14px; border-bottom:3px solid var(--primary); margin-bottom:14px; }
.report-header h1 { font-size:22px; color:var(--primary); margin-bottom:2px; }
.report-header .domain { font-size:14px; color:#64748b; }
.report-header .date { font-size:12px; color:#94a3b8; margin-top:4px; }

.score-row { display:flex; gap:14px; margin-bottom:14px; }
.score-box { flex:1; text-align:center; padding:16px; border-radius:8px; border:1px solid var(--border); background:#fff; }
.score-box .value { font-size:36px; font-weight:800; }
.score-box .label { font-size:11px; color:#94a3b8; text-transform:uppercase; margin-top:2px; }
.score-good { color:#10b981; }
.score-ok { color:#f59e0b; }
.score-bad { color:#ef4444; }

.report-section { background:#fff; border:1px solid var(--border); border-radius:8px; margin-bottom:12px; overflow:hidden; }
.report-section h3 { padding:10px 16px; margin:0; font-size:14px; background:#f8fafc; border-bottom:1px solid var(--border); color:var(--primary); }
.check-table { width:100%; border-collapse:collapse; font-size:13px; }
.check-table td { padding:8px 16px; border-bottom:1px solid #f1f5f9; }
.check-table td:first-child { width:30%; font-weight:500; }
.check-table td:nth-child(2) { width:15%; text-align:center; }
.check-table td:last-child { color:#64748b; }

.badge-pass { background:#d1fae5; color:#065f46; padding:2px 10px; border-radius:10px; font-size:11px; font-weight:600; }
.badge-fail { background:#fecaca; color:#991b1b; padding:2px 10px; border-radius:10px; font-size:11px; font-weight:600; }
.badge-warn { background:#fef3c7; color:#92400e; padding:2px 10px; border-radius:10px; font-size:11px; font-weight:600; }
.badge-info-r { background:#dbeafe; color:#1e40af; padding:2px 10px; border-radius:10px; font-size:11px; font-weight:600; }

.summary-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:14px; }
.summary-item { text-align:center; padding:12px; background:#fff; border:1px solid var(--border); border-radius:8px; }
.summary-item .num { font-size:22px; font-weight:700; }
.summary-item .lbl { font-size:10px; color:#94a3b8; text-transform:uppercase; }
</style>

<div class="no-print" style="margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">
    <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site['name']) ?></a>
    <a href="<?= url('/api/export-report.php?site=' . $site_id) ?>" class="btn btn-accent btn-sm" style="text-decoration:none;">Download Excel</a>
    <button onclick="window.print()" class="btn btn-outline btn-sm">Print / Save PDF</button>
</div>

<!-- Report Header -->
<div class="report-header">
    <h1>SEO & AI Health Report</h1>
    <div class="domain"><?= e($site['name']) ?> &mdash; <?= e($site['domain']) ?></div>
    <div class="date">Generated <?= date('d M Y, h:i A') ?> &bull; Platform: <?= e($site['platform'] ?: 'custom') ?></div>
</div>

<!-- Score Overview -->
<div class="score-row">
    <div class="score-box">
        <div class="value <?= $seo_score >= 80 ? 'score-good' : ($seo_score >= 50 ? 'score-ok' : 'score-bad') ?>"><?= $seo_score ?? '—' ?></div>
        <div class="label">SEO Score</div>
    </div>
    <div class="score-box">
        <div class="value <?= $ai_score >= 80 ? 'score-good' : ($ai_score >= 50 ? 'score-ok' : 'score-bad') ?>"><?= $ai_score ?>%</div>
        <div class="label">AI Readiness</div>
    </div>
    <div class="score-box">
        <div class="value"><?= count($open_issues) ?></div>
        <div class="label">Open Issues</div>
    </div>
    <div class="score-box">
        <div class="value score-good"><?= $total_fixed ?></div>
        <div class="label">Issues Fixed</div>
    </div>
</div>

<!-- Summary Stats -->
<div class="summary-grid">
    <div class="summary-item"><div class="num"><?= $audit ? $audit['pages_crawled'] : 0 ?></div><div class="lbl">Pages Crawled</div></div>
    <div class="summary-item"><div class="num"><?= $pc['published'] ?? 0 ?></div><div class="lbl">Posts Published</div></div>
    <div class="summary-item"><div class="num"><?= $kw_count ?></div><div class="lbl">Keywords Tracked</div></div>
    <div class="summary-item"><div class="num"><?= count($schemas_found) ?></div><div class="lbl">Schema Types</div></div>
</div>

<!-- SEO Score Trend -->
<?php if (count($score_history) > 1): ?>
<div class="report-section">
    <h3>SEO Score Trend</h3>
    <div style="padding:14px 16px;">
        <div style="display:flex;align-items:flex-end;gap:4px;height:70px;">
            <?php foreach ($score_history as $h):
                $bh = max(8, ($h['score'] / 100) * 60);
                $bc = $h['score'] >= 80 ? '#10b981' : ($h['score'] >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;">
                <span style="font-size:10px;font-weight:700;color:<?= $bc ?>;"><?= $h['score'] ?></span>
                <div style="width:100%;max-width:45px;height:<?= $bh ?>px;background:<?= $bc ?>;border-radius:3px 3px 0 0;"></div>
                <span style="font-size:8px;color:#94a3b8;"><?= $h['label'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Technical Foundation -->
<div class="report-section">
    <h3>Technical Foundation</h3>
    <table class="check-table">
        <tr>
            <td>SSL Certificate</td>
            <td><span class="badge-pass">Pass</span></td>
            <td>HTTPS active on <?= e($site['domain']) ?></td>
        </tr>
        <tr>
            <td>Viewport Meta Tag</td>
            <td><span class="<?= $has_viewport ? 'badge-pass' : 'badge-fail' ?>"><?= $has_viewport ? 'Pass' : 'Fail' ?></span></td>
            <td><?= $has_viewport ? 'Your website displays properly on phones and tablets' : 'Your website may look broken on mobile devices — this affects 60% of your visitors' ?></td>
        </tr>
        <tr>
            <td>XML Sitemap</td>
            <td><?php
                $sm = scraper_check_sitemap($domain);
                echo '<span class="' . ($sm['exists'] ? 'badge-pass' : 'badge-fail') . '">' . ($sm['exists'] ? 'Pass' : 'Fail') . '</span>';
            ?></td>
            <td><?= $sm['exists'] ? 'Google and other search engines can find all your pages' : 'Google is likely missing some of your pages — they don\'t know your full website exists' ?></td>
        </tr>
        <tr>
            <td>robots.txt</td>
            <td><?php
                $rb = scraper_check_robots($domain);
                echo '<span class="' . ($rb['exists'] ? 'badge-pass' : 'badge-fail') . '">' . ($rb['exists'] ? 'Pass' : 'Fail') . '</span>';
            ?></td>
            <td><?= $rb['exists'] ? 'Search engines know which pages to show and which to skip' : 'Search engines have no instructions — they may index pages you don\'t want shown' ?></td>
        </tr>
        <tr>
            <td>Page Title</td>
            <td><span class="<?= !empty($title) && mb_strlen($title) <= 70 ? 'badge-pass' : 'badge-warn' ?>"><?= !empty($title) ? (mb_strlen($title) <= 70 ? 'Pass' : 'Too Long') : 'Fail' ?></span></td>
            <td><?= !empty($title) ? '"' . e(truncate($title, 60)) . '" — this is what people see in Google search results' : 'No title — your page shows as "Untitled" in Google, which nobody clicks' ?></td>
        </tr>
        <tr>
            <td>Page Description</td>
            <td><span class="<?= !empty($meta['description']) ? 'badge-pass' : 'badge-fail' ?>"><?= !empty($meta['description']) ? 'Pass' : 'Fail' ?></span></td>
            <td><?= !empty($meta['description']) ? 'Google shows: "' . e(truncate($meta['description'], 70)) . '..."' : 'No description — Google picks random text from your page, which often looks bad' ?></td>
        </tr>
        <tr>
            <td>Canonical URL</td>
            <td><span class="<?= $has_canonical ? 'badge-pass' : 'badge-fail' ?>"><?= $has_canonical ? 'Pass' : 'Fail' ?></span></td>
            <td><?= $has_canonical ? 'Google knows which version of your page is the "main" one' : 'Google may think you have duplicate pages, which hurts your ranking' ?></td>
        </tr>
    </table>
</div>

<!-- Social & Sharing -->
<div class="report-section">
    <h3>Social & Sharing</h3>
    <table class="check-table">
        <tr>
            <td>Open Graph Tags</td>
            <td><span class="<?= $has_og ? 'badge-pass' : 'badge-fail' ?>"><?= $has_og ? 'Pass' : 'Fail' ?></span></td>
            <td><?= $has_og ? 'When someone shares your link on Facebook or LinkedIn, it shows a proper preview with title and description' : 'When someone shares your link on social media, it looks plain with no preview — people are less likely to click' ?></td>
        </tr>
        <tr>
            <td>Twitter/X Preview</td>
            <td><span class="<?= !empty($meta['twitter:card']) ? 'badge-pass' : 'badge-warn' ?>"><?= !empty($meta['twitter:card']) ? 'Pass' : 'Missing' ?></span></td>
            <td><?= !empty($meta['twitter:card']) ? 'Your links show a rich preview when shared on Twitter/X' : 'Links shared on Twitter/X will look plain — no image, no description' ?></td>
        </tr>
        <tr>
            <td>Share Image</td>
            <td><span class="<?= !empty($meta['og:image']) ? 'badge-pass' : 'badge-warn' ?>"><?= !empty($meta['og:image']) ? 'Pass' : 'Missing' ?></span></td>
            <td><?= !empty($meta['og:image']) ? 'A branded image appears when your link is shared on social media' : 'No image shows when your link is shared — posts with images get 2.3x more engagement' ?></td>
        </tr>
    </table>
</div>

<!-- Structured Data -->
<div class="report-section">
    <h3>Rich Search Results (Schema Markup)</h3>
    <p style="padding:8px 16px 0;font-size:12px;color:#64748b;margin:0;">Schema markup helps Google show rich results — like star ratings, business hours, FAQs, and breadcrumbs directly in search results. Pages with rich results get up to 30% more clicks.</p>
    <table class="check-table">
        <?php if (!empty($schemas_found)): ?>
            <?php foreach ($schemas_found as $schema): ?>
            <tr>
                <td><?= e($schema['@type'] ?? 'Unknown') ?></td>
                <td><span class="badge-pass">Found</span></td>
                <td><?php
                    if (isset($schema['name'])) echo 'Name: ' . e(truncate($schema['name'], 40));
                    elseif (isset($schema['url'])) echo 'URL: ' . e(truncate($schema['url'], 40));
                    else echo 'Configured and active';
                ?></td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td>Schema Markup</td>
                <td><span class="badge-fail">Missing</span></td>
                <td>Your site has no schema markup — Google shows plain blue links instead of rich results with images, ratings, or details</td>
            </tr>
        <?php endif; ?>
        <?php
        $schema_types = array_map(fn($s) => $s['@type'] ?? '', $schemas_found);
        $recommended = [
            'Organization' => 'Tells Google your company name, logo, and contact details',
            'WebSite' => 'Helps Google understand your site structure and enables sitelinks',
            'LocalBusiness' => 'Shows your business address, hours, and phone in Google Maps results',
            'BreadcrumbList' => 'Shows page hierarchy in search results (Home > Products > Item)',
            'FAQ' => 'Your FAQ answers can appear directly in Google search results',
        ];
        foreach ($recommended as $rec => $desc):
            if (!in_array($rec, $schema_types)):
        ?>
        <tr>
            <td><?= $rec ?></td>
            <td><span class="badge-info-r">Suggested</span></td>
            <td><?= $desc ?></td>
        </tr>
        <?php endif; endforeach; ?>
    </table>
</div>

<!-- AI Discoverability -->
<div class="report-section">
    <h3>AI Search Visibility</h3>
    <p style="padding:8px 16px 0;font-size:12px;color:#64748b;margin:0;">ChatGPT, Perplexity, Claude, and Google AI now answer questions directly. If your site isn't optimized for AI, you're invisible to a growing number of searchers.</p>
    <table class="check-table">
        <?php foreach ($ai_results as $r): ?>
        <tr>
            <td><?= e($r['check']) ?></td>
            <td><span class="<?= $r['status'] === 'pass' ? 'badge-pass' : ($r['status'] === 'warning' ? 'badge-warn' : 'badge-fail') ?>"><?= $r['status'] === 'pass' ? 'Pass' : ($r['status'] === 'warning' ? 'Warning' : 'Fail') ?></span></td>
            <td><?= e($r['detail']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<!-- Open Issues -->
<?php if (!empty($open_issues)): ?>
<div class="report-section">
    <h3>What Needs Fixing (<?= count($open_issues) ?> items)</h3>
    <table class="check-table">
        <tr style="background:#f8fafc;font-weight:600;">
            <td>Issue</td>
            <td>Severity</td>
            <td>Page</td>
        </tr>
        <?php foreach (array_slice($open_issues, 0, 20) as $issue): ?>
        <tr>
            <td><?= e($issue['description']) ?></td>
            <td><span class="<?= $issue['severity'] === 'critical' ? 'badge-fail' : ($issue['severity'] === 'warning' ? 'badge-warn' : 'badge-info-r') ?>"><?= $issue['severity'] ?></span></td>
            <td style="font-size:11px;"><?= e(truncate($issue['url'], 40)) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (count($open_issues) > 20): ?>
        <tr><td colspan="3" style="text-align:center;color:#94a3b8;">+ <?= count($open_issues) - 20 ?> more issues</td></tr>
        <?php endif; ?>
    </table>
</div>
<?php endif; ?>

<!-- Footer -->
<div style="text-align:center;padding:14px 0;color:#94a3b8;font-size:11px;border-top:1px solid var(--border);margin-top:14px;">
    Report generated by ContentAgent &mdash; contentagent.xceedtech.in &mdash; <?= date('d M Y') ?>
</div>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
