<?php
/**
 * Export Health Report as multi-sheet Excel (XML Spreadsheet 2003)
 * Sheets: Overview, Technical SEO, Social & Sharing, Schema, AI Visibility, Open Issues, Keywords, Posts
 *
 * GET ?site=1
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai-seo.php';

auth_start();
if (!auth_check()) { http_response_code(401); echo 'Unauthorized'; exit; }

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();
$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { http_response_code(400); echo 'site required'; exit; }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); echo 'Site not found'; exit; }

$domain = 'https://' . ltrim($site['domain'], 'https://');

// Gather all data
$stmt = $db->prepare('SELECT * FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 1');
$stmt->execute([$site_id]);
$audit = $stmt->fetch();

$seo_issues = [];
if ($audit) {
    $stmt = $db->prepare('SELECT * FROM seo_issues WHERE audit_id = ? ORDER BY FIELD(severity, "critical", "warning", "info"), type');
    $stmt->execute([$audit['id']]);
    $seo_issues = $stmt->fetchAll();
}

$open_issues = array_filter($seo_issues, fn($i) => $i['status'] === 'open');
$fixed_issues = array_filter($seo_issues, fn($i) => in_array($i['status'], ['fix_applied', 'resolved', 'fixed_by_snippet']));

// AI audit
$ai_audit = audit_ai_discoverability($site['domain']);
$ai_results = $ai_audit['results'] ?? [];

// Homepage checks
$homepage = scraper_fetch($domain, 15);
$doc = null; $meta = []; $title = ''; $has_viewport = false; $has_canonical = false; $has_og = false; $schemas_found = [];
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

// Posts
$stmt = $db->prepare('SELECT title, slug, status, type, seo_title, seo_description, published_at, created_at FROM posts WHERE site_id = ? ORDER BY created_at DESC');
$stmt->execute([$site_id]);
$posts = $stmt->fetchAll();

// Keywords
$stmt = $db->prepare('SELECT keyword, search_volume, difficulty, cluster, priority FROM keywords WHERE site_id = ? ORDER BY priority DESC');
$stmt->execute([$site_id]);
$keywords = $stmt->fetchAll();

// Score history
$stmt = $db->prepare('SELECT score, run_at FROM seo_audits WHERE site_id = ? ORDER BY run_at ASC');
$stmt->execute([$site_id]);
$score_history = $stmt->fetchAll();

// ── Helper: XML-escape ──
function x($v) { return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

// ── Build XML Spreadsheet ──
$filename = 'health-report-' . $site['domain'] . '-' . date('Y-m-d') . '.xls';

// Buffer to prevent any stray output
ob_start();

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

ob_end_clean();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
<Styles>
 <Style ss:ID="Default" ss:Name="Normal"><Font ss:Size="11"/></Style>
 <Style ss:ID="header"><Font ss:Color="#FFFFFF" ss:Bold="1" ss:Size="11"/><Interior ss:Color="#1e3a5f" ss:Pattern="Solid"/></Style>
 <Style ss:ID="title"><Font ss:Bold="1" ss:Size="14" ss:Color="#1e3a5f"/></Style>
 <Style ss:ID="subtitle"><Font ss:Size="11" ss:Color="#64748b"/></Style>
 <Style ss:ID="pass"><Font ss:Color="#065f46" ss:Bold="1"/><Interior ss:Color="#d1fae5" ss:Pattern="Solid"/></Style>
 <Style ss:ID="fail"><Font ss:Color="#991b1b" ss:Bold="1"/><Interior ss:Color="#fecaca" ss:Pattern="Solid"/></Style>
 <Style ss:ID="warn"><Font ss:Color="#92400e" ss:Bold="1"/><Interior ss:Color="#fef3c7" ss:Pattern="Solid"/></Style>
 <Style ss:ID="bold"><Font ss:Bold="1"/></Style>
</Styles>

<!-- Sheet 1: Overview -->
<Worksheet ss:Name="Overview">
<Table>
 <Column ss:Width="200"/><Column ss:Width="200"/>
 <Row><Cell ss:StyleID="title"><Data ss:Type="String">SEO &amp; AI Health Report</Data></Cell></Row>
 <Row><Cell ss:StyleID="subtitle"><Data ss:Type="String"><?= x($site['name']) ?> — <?= x($site['domain']) ?></Data></Cell></Row>
 <Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">Generated: <?= date('d M Y, h:i A') ?></Data></Cell></Row>
 <Row><Cell><Data ss:Type="String"></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Metric</Data></Cell><Cell ss:StyleID="bold"><Data ss:Type="String">Value</Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">SEO Score</Data></Cell><Cell><Data ss:Type="Number"><?= $audit ? $audit['score'] : 0 ?></Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">AI Readiness</Data></Cell><Cell><Data ss:Type="String"><?= $ai_audit['score'] ?? 0 ?>%</Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">Open Issues</Data></Cell><Cell><Data ss:Type="Number"><?= count($open_issues) ?></Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">Issues Fixed</Data></Cell><Cell><Data ss:Type="Number"><?= count($fixed_issues) ?></Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">Pages Crawled</Data></Cell><Cell><Data ss:Type="Number"><?= $audit ? $audit['pages_crawled'] : 0 ?></Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">Posts Published</Data></Cell><Cell><Data ss:Type="Number"><?= count(array_filter($posts, fn($p) => $p['status'] === 'published')) ?></Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">Keywords Tracked</Data></Cell><Cell><Data ss:Type="Number"><?= count($keywords) ?></Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">Platform</Data></Cell><Cell><Data ss:Type="String"><?= x($site['platform'] ?: 'custom') ?></Data></Cell></Row>
 <Row><Cell><Data ss:Type="String"></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Score History</Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Date</Data></Cell><Cell ss:StyleID="bold"><Data ss:Type="String">Score</Data></Cell></Row>
 <?php foreach ($score_history as $h): ?>
 <Row><Cell><Data ss:Type="String"><?= date('d M Y', strtotime($h['run_at'])) ?></Data></Cell><Cell><Data ss:Type="Number"><?= $h['score'] ?></Data></Cell></Row>
 <?php endforeach; ?>
</Table>
</Worksheet>

<!-- Sheet 2: Technical SEO -->
<Worksheet ss:Name="Technical SEO">
<Table>
 <Column ss:Width="180"/><Column ss:Width="80"/><Column ss:Width="500"/>
 <Row><Cell ss:StyleID="header"><Data ss:Type="String">Check</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Status</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Details</Data></Cell></Row>
 <?php
 $tech_checks = [
     ['SSL Certificate', true, 'HTTPS active on ' . $site['domain']],
     ['Viewport Meta Tag', $has_viewport, $has_viewport ? 'Website displays properly on mobile devices' : 'Website may look broken on mobile — affects 60% of visitors'],
     ['XML Sitemap', scraper_check_sitemap($domain)['exists'], scraper_check_sitemap($domain)['exists'] ? 'Google can find all your pages' : 'Google is likely missing some pages'],
     ['robots.txt', scraper_check_robots($domain)['exists'], scraper_check_robots($domain)['exists'] ? 'Search engines know which pages to index' : 'No crawling instructions for search engines'],
     ['Page Title', !empty($title) && mb_strlen($title) <= 70, !empty($title) ? $title : 'No page title found'],
     ['Meta Description', !empty($meta['description']), !empty($meta['description']) ? $meta['description'] : 'No meta description — Google picks random text'],
     ['Canonical URL', $has_canonical, $has_canonical ? 'Canonical URL set — prevents duplicate content issues' : 'No canonical — Google may see duplicate pages'],
 ];
 foreach ($tech_checks as $c):
     $style = $c[1] ? 'pass' : 'fail';
 ?>
 <Row><Cell><Data ss:Type="String"><?= x($c[0]) ?></Data></Cell><Cell ss:StyleID="<?= $style ?>"><Data ss:Type="String"><?= $c[1] ? 'Pass' : 'Fail' ?></Data></Cell><Cell><Data ss:Type="String"><?= x($c[2]) ?></Data></Cell></Row>
 <?php endforeach; ?>
</Table>
</Worksheet>

<!-- Sheet 3: Social & Sharing -->
<Worksheet ss:Name="Social &amp; Sharing">
<Table>
 <Column ss:Width="180"/><Column ss:Width="80"/><Column ss:Width="500"/>
 <Row><Cell ss:StyleID="header"><Data ss:Type="String">Check</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Status</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Details</Data></Cell></Row>
 <?php
 $social_checks = [
     ['Open Graph Tags', $has_og, $has_og ? 'Links show proper preview on Facebook/LinkedIn' : 'Links look plain when shared on social media'],
     ['Twitter/X Card', !empty($meta['twitter:card']), !empty($meta['twitter:card']) ? 'Rich preview on Twitter/X' : 'Plain links on Twitter/X — no image or description'],
     ['Share Image', !empty($meta['og:image']), !empty($meta['og:image']) ? 'Branded image appears when shared' : 'No image when shared — posts with images get 2.3x more engagement'],
 ];
 foreach ($social_checks as $c):
     $style = $c[1] ? 'pass' : 'warn';
 ?>
 <Row><Cell><Data ss:Type="String"><?= x($c[0]) ?></Data></Cell><Cell ss:StyleID="<?= $style ?>"><Data ss:Type="String"><?= $c[1] ? 'Pass' : 'Missing' ?></Data></Cell><Cell><Data ss:Type="String"><?= x($c[2]) ?></Data></Cell></Row>
 <?php endforeach; ?>
</Table>
</Worksheet>

<!-- Sheet 4: Schema Markup -->
<Worksheet ss:Name="Schema Markup">
<Table>
 <Column ss:Width="180"/><Column ss:Width="80"/><Column ss:Width="500"/>
 <Row><Cell ss:StyleID="header"><Data ss:Type="String">Schema Type</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Status</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Details</Data></Cell></Row>
 <?php if (!empty($schemas_found)): foreach ($schemas_found as $s): ?>
 <Row><Cell><Data ss:Type="String"><?= x($s['@type'] ?? 'Unknown') ?></Data></Cell><Cell ss:StyleID="pass"><Data ss:Type="String">Found</Data></Cell><Cell><Data ss:Type="String"><?= x($s['name'] ?? $s['url'] ?? 'Active') ?></Data></Cell></Row>
 <?php endforeach; endif;
 $schema_types = array_map(fn($s) => $s['@type'] ?? '', $schemas_found);
 $recommended = [
     'Organization' => 'Company name, logo, contact details in Google',
     'WebSite' => 'Site structure and sitelinks in search',
     'LocalBusiness' => 'Address, hours, phone in Google Maps',
     'BreadcrumbList' => 'Page hierarchy in search results',
     'FAQ' => 'FAQ answers directly in search results',
 ];
 foreach ($recommended as $rec => $desc):
     if (!in_array($rec, $schema_types)):
 ?>
 <Row><Cell><Data ss:Type="String"><?= x($rec) ?></Data></Cell><Cell ss:StyleID="warn"><Data ss:Type="String">Suggested</Data></Cell><Cell><Data ss:Type="String"><?= x($desc) ?></Data></Cell></Row>
 <?php endif; endforeach; ?>
</Table>
</Worksheet>

<!-- Sheet 5: AI Visibility -->
<Worksheet ss:Name="AI Visibility">
<Table>
 <Column ss:Width="200"/><Column ss:Width="80"/><Column ss:Width="500"/>
 <Row><Cell ss:StyleID="header"><Data ss:Type="String">Check</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Status</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Details</Data></Cell></Row>
 <?php foreach ($ai_results as $r):
     $style = $r['status'] === 'pass' ? 'pass' : ($r['status'] === 'warning' ? 'warn' : 'fail');
     $label = $r['status'] === 'pass' ? 'Pass' : ($r['status'] === 'warning' ? 'Warning' : 'Fail');
 ?>
 <Row><Cell><Data ss:Type="String"><?= x($r['check']) ?></Data></Cell><Cell ss:StyleID="<?= $style ?>"><Data ss:Type="String"><?= $label ?></Data></Cell><Cell><Data ss:Type="String"><?= x($r['detail']) ?></Data></Cell></Row>
 <?php endforeach; ?>
 <Row><Cell><Data ss:Type="String"></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">AI Readiness Score</Data></Cell><Cell><Data ss:Type="String"><?= $ai_audit['score'] ?? 0 ?>%</Data></Cell></Row>
</Table>
</Worksheet>

<!-- Sheet 6: Open Issues -->
<Worksheet ss:Name="Open Issues">
<Table>
 <Column ss:Width="40"/><Column ss:Width="80"/><Column ss:Width="120"/><Column ss:Width="300"/><Column ss:Width="400"/>
 <Row><Cell ss:StyleID="header"><Data ss:Type="String">#</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Priority</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Type</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Page URL</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Issue</Data></Cell></Row>
 <?php $i = 1; foreach ($open_issues as $issue):
     $priority = $issue['severity'] === 'critical' ? 'HIGH' : ($issue['severity'] === 'warning' ? 'MEDIUM' : 'LOW');
     $style = $issue['severity'] === 'critical' ? 'fail' : ($issue['severity'] === 'warning' ? 'warn' : '');
 ?>
 <Row><Cell><Data ss:Type="Number"><?= $i++ ?></Data></Cell><Cell<?= $style ? " ss:StyleID=\"{$style}\"" : '' ?>><Data ss:Type="String"><?= x($priority) ?></Data></Cell><Cell><Data ss:Type="String"><?= x(str_replace('_', ' ', ucfirst($issue['type']))) ?></Data></Cell><Cell><Data ss:Type="String"><?= x($issue['url']) ?></Data></Cell><Cell><Data ss:Type="String"><?= x($issue['description']) ?></Data></Cell></Row>
 <?php endforeach; ?>
 <?php if (empty($open_issues)): ?>
 <Row><Cell><Data ss:Type="String"></Data></Cell><Cell><Data ss:Type="String"></Data></Cell><Cell><Data ss:Type="String"></Data></Cell><Cell><Data ss:Type="String">No open issues — all clear!</Data></Cell></Row>
 <?php endif; ?>
</Table>
</Worksheet>

<!-- Sheet 7: Keywords -->
<Worksheet ss:Name="Keywords">
<Table>
 <Column ss:Width="250"/><Column ss:Width="100"/><Column ss:Width="80"/><Column ss:Width="150"/><Column ss:Width="60"/>
 <Row><Cell ss:StyleID="header"><Data ss:Type="String">Keyword</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Search Volume</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Difficulty</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Cluster</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Priority</Data></Cell></Row>
 <?php foreach ($keywords as $kw): ?>
 <Row><Cell><Data ss:Type="String"><?= x($kw['keyword']) ?></Data></Cell><Cell><Data ss:Type="String"><?= x($kw['search_volume'] ?? '-') ?></Data></Cell><Cell><Data ss:Type="String"><?= x($kw['difficulty'] ?? '-') ?></Data></Cell><Cell><Data ss:Type="String"><?= x($kw['cluster'] ?? '-') ?></Data></Cell><Cell><Data ss:Type="Number"><?= $kw['priority'] ?? 0 ?></Data></Cell></Row>
 <?php endforeach; ?>
 <?php if (empty($keywords)): ?>
 <Row><Cell><Data ss:Type="String">No keywords found yet. Run Keyword Research first.</Data></Cell></Row>
 <?php endif; ?>
</Table>
</Worksheet>

<!-- Sheet 8: Posts -->
<Worksheet ss:Name="Posts">
<Table>
 <Column ss:Width="350"/><Column ss:Width="80"/><Column ss:Width="60"/><Column ss:Width="250"/><Column ss:Width="300"/><Column ss:Width="120"/>
 <Row><Cell ss:StyleID="header"><Data ss:Type="String">Title</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Status</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Type</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">SEO Title</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">SEO Description</Data></Cell><Cell ss:StyleID="header"><Data ss:Type="String">Date</Data></Cell></Row>
 <?php foreach ($posts as $p): ?>
 <Row><Cell><Data ss:Type="String"><?= x($p['title']) ?></Data></Cell><Cell ss:StyleID="<?= $p['status'] === 'published' ? 'pass' : 'warn' ?>"><Data ss:Type="String"><?= x($p['status']) ?></Data></Cell><Cell><Data ss:Type="String"><?= x($p['type']) ?></Data></Cell><Cell><Data ss:Type="String"><?= x($p['seo_title'] ?? '') ?></Data></Cell><Cell><Data ss:Type="String"><?= x($p['seo_description'] ?? '') ?></Data></Cell><Cell><Data ss:Type="String"><?= $p['published_at'] ? date('d M Y', strtotime($p['published_at'])) : date('d M Y', strtotime($p['created_at'])) ?></Data></Cell></Row>
 <?php endforeach; ?>
 <?php if (empty($posts)): ?>
 <Row><Cell><Data ss:Type="String">No posts yet. Use AI Content Planner to create your first post.</Data></Cell></Row>
 <?php endif; ?>
</Table>
</Worksheet>

</Workbook>
