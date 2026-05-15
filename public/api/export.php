<?php
/**
 * API — Export site data as multi-sheet Excel (XML Spreadsheet).
 * GET /api/export.php?site_id=1&type=full
 *
 * Generates Excel XML with separate sheets:
 * 1. Summary & Progress
 * 2. Content Strategy (proposed blogs + keyword mapping)
 * 3. SEO Issues
 * 4. Keywords
 * 5. Posts
 * 6. Agent Activity
 */

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();

if (!auth_check()) {
    header('Location: ' . base_path() . '/auth/login.php');
    exit;
}

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site_id'] ?? 0);
if (!$site_id) die('site_id required');

$site = auth_get_accessible_site($db, $site_id);
if (!$site) die('Site not found');

$filename = slugify($site['name']) . '-report-' . date('Y-m-d') . '.xls';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// ── Gather all data ─────────────────────────────────────
// Posts
$stmt = $db->prepare('SELECT * FROM posts WHERE site_id = ? ORDER BY created_at DESC');
$stmt->execute([$site_id]);
$posts = $stmt->fetchAll();

$post_counts = ['published' => 0, 'draft' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($posts as $p) $post_counts[$p['status']] = ($post_counts[$p['status']] ?? 0) + 1;

// SEO audits
$stmt = $db->prepare('SELECT * FROM seo_audits WHERE site_id = ? ORDER BY run_at ASC');
$stmt->execute([$site_id]);
$audits = $stmt->fetchAll();

// SEO issues
$stmt = $db->prepare('SELECT * FROM seo_issues WHERE site_id = ? ORDER BY FIELD(severity, "critical", "warning", "info"), type');
$stmt->execute([$site_id]);
$issues = $stmt->fetchAll();

$issue_counts = ['open' => 0, 'fix_proposed' => 0, 'resolved' => 0, 'ignored' => 0];
foreach ($issues as $i) $issue_counts[$i['status']] = ($issue_counts[$i['status']] ?? 0) + 1;

// Keywords
$stmt = $db->prepare('SELECT * FROM keywords WHERE site_id = ? ORDER BY priority DESC');
$stmt->execute([$site_id]);
$keywords = $stmt->fetchAll();

// Agent log
$stmt = $db->prepare('SELECT * FROM agent_log WHERE site_id = ? ORDER BY created_at DESC LIMIT 100');
$stmt->execute([$site_id]);
$logs = $stmt->fetchAll();

// Content strategy: group keywords by cluster, find gaps
$stmt = $db->prepare('SELECT k.cluster, GROUP_CONCAT(k.keyword ORDER BY k.priority DESC SEPARATOR ", ") as kws, COUNT(*) as cnt, ROUND(AVG(k.priority)) as avg_priority, ROUND(AVG(k.difficulty)) as avg_difficulty FROM keywords k WHERE k.site_id = ? AND k.cluster IS NOT NULL GROUP BY k.cluster ORDER BY avg_priority DESC');
$stmt->execute([$site_id]);
$clusters = $stmt->fetchAll();

// Existing post keywords to find gaps
$covered_keywords = [];
foreach ($posts as $p) {
    $kws = array_filter(array_map('trim', explode(',', $p['seo_keywords'] ?? '')));
    $covered_keywords = array_merge($covered_keywords, $kws);
}
$covered_keywords = array_map('strtolower', $covered_keywords);

// ── Generate Excel XML ──────────────────────────────────
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">

<Styles>
 <Style ss:ID="header"><Font ss:Bold="1" ss:Size="11" ss:Color="#FFFFFF"/><Interior ss:Color="#1B3A6B" ss:Pattern="Solid"/></Style>
 <Style ss:ID="title"><Font ss:Bold="1" ss:Size="14" ss:Color="#1B3A6B"/></Style>
 <Style ss:ID="subtitle"><Font ss:Bold="1" ss:Size="11" ss:Color="#333333"/></Style>
 <Style ss:ID="good"><Font ss:Color="#065F46"/><Interior ss:Color="#D1FAE5" ss:Pattern="Solid"/></Style>
 <Style ss:ID="bad"><Font ss:Color="#991B1B"/><Interior ss:Color="#FECACA" ss:Pattern="Solid"/></Style>
 <Style ss:ID="warn"><Font ss:Color="#92400E"/><Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/></Style>
 <Style ss:ID="accent"><Font ss:Bold="1" ss:Color="#CC3300"/></Style>
 <Style ss:ID="bold"><Font ss:Bold="1"/></Style>
 <Style ss:ID="muted"><Font ss:Color="#999999"/></Style>
</Styles>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- SHEET 1: Summary & Progress -->
<!-- ═══════════════════════════════════════════════════════ -->
<Worksheet ss:Name="Summary">
<Table>
 <Column ss:Width="200"/><Column ss:Width="150"/><Column ss:Width="150"/><Column ss:Width="150"/>

 <Row><Cell ss:StyleID="title"><Data ss:Type="String">ContentAgent — Site Report</Data></Cell></Row>
 <Row><Cell ss:StyleID="muted"><Data ss:Type="String">Generated: <?= date('d M Y, h:i A') ?></Data></Cell></Row>
 <Row/>

 <Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">Site Information</Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Name</Data></Cell><Cell><Data ss:Type="String"><?= x($site['name']) ?></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Domain</Data></Cell><Cell><Data ss:Type="String"><?= x($site['domain']) ?></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Platform</Data></Cell><Cell><Data ss:Type="String"><?= x($site['platform'] ?: 'Custom') ?></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Brand Tone</Data></Cell><Cell><Data ss:Type="String"><?= x($site['brand_tone'] ?: 'Not analyzed') ?></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Agent Mode</Data></Cell><Cell><Data ss:Type="String"><?= x($site['agent_mode']) ?></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Last Scanned</Data></Cell><Cell><Data ss:Type="String"><?= x($site['scanned_at'] ?: 'Never') ?></Data></Cell></Row>
 <Row/>

 <Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">Content Summary</Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Published</Data></Cell><Cell><Data ss:Type="Number"><?= $post_counts['published'] ?></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Drafts</Data></Cell><Cell><Data ss:Type="Number"><?= $post_counts['draft'] ?></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Approved</Data></Cell><Cell><Data ss:Type="Number"><?= $post_counts['approved'] ?></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Total Posts</Data></Cell><Cell><Data ss:Type="Number"><?= count($posts) ?></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Total Keywords</Data></Cell><Cell><Data ss:Type="Number"><?= count($keywords) ?></Data></Cell></Row>
 <Row/>

 <Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">SEO Score History</Data></Cell></Row>
 <Row>
  <Cell ss:StyleID="header"><Data ss:Type="String">Date</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Score</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Issues</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Critical</Data></Cell>
 </Row>
 <?php foreach ($audits as $a): ?>
 <Row>
  <Cell><Data ss:Type="String"><?= x($a['run_at']) ?></Data></Cell>
  <Cell ss:StyleID="<?= $a['score'] >= 80 ? 'good' : ($a['score'] >= 50 ? 'warn' : 'bad') ?>"><Data ss:Type="Number"><?= $a['score'] ?></Data></Cell>
  <Cell><Data ss:Type="Number"><?= $a['total_issues'] ?></Data></Cell>
  <Cell><?php if($a['critical']>0): ?><Data ss:Type="Number"><?= $a['critical'] ?></Data><?php else: ?><Data ss:Type="Number">0</Data><?php endif; ?></Cell>
 </Row>
 <?php endforeach; ?>

 <?php if (count($audits) >= 2): $first=$audits[0]; $last=$audits[count($audits)-1]; $change=$last['score']-$first['score']; ?>
 <Row/>
 <Row><Cell ss:StyleID="accent"><Data ss:Type="String">Score Change: <?= ($change>=0?'+':'') . $change ?> points (<?= $first['score'] ?> → <?= $last['score'] ?>)</Data></Cell></Row>
 <?php endif; ?>

 <Row/>
 <Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">SEO Issues Status</Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Open (needs fixing)</Data></Cell><Cell ss:StyleID="bad"><Data ss:Type="Number"><?= $issue_counts['open'] ?></Data></Cell><Cell ss:StyleID="muted"><Data ss:Type="String">→ Go to SEO Audit in dashboard, click "Fix All"</Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Fix Generated (review in SEO Audit tab)</Data></Cell><Cell ss:StyleID="warn"><Data ss:Type="Number"><?= $issue_counts['fix_proposed'] ?></Data></Cell><Cell ss:StyleID="muted"><Data ss:Type="String">→ Fixes are ready. See "SEO Issues" sheet for details.</Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Resolved</Data></Cell><Cell ss:StyleID="good"><Data ss:Type="Number"><?= $issue_counts['resolved'] ?></Data></Cell></Row>
 <Row/>
 <Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">What To Do Next</Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">1.</Data></Cell><Cell><Data ss:Type="String">See "Content Strategy" sheet → write proposed blog posts to rank for target keywords</Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">2.</Data></Cell><Cell><Data ss:Type="String">See "SEO Issues" sheet → apply suggested fixes to improve SEO score from <?= $audits ? $audits[count($audits)-1]['score'] : '?' ?>/100</Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">3.</Data></Cell><Cell><Data ss:Type="String">Run SEO Audit weekly to track score improvements</Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">4.</Data></Cell><Cell><Data ss:Type="String">Run "Write Blog Post" from dashboard to auto-generate content for proposed topics</Data></Cell></Row>
</Table>
</Worksheet>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- SHEET 2: Content Strategy & Action Plan -->
<!-- ═══════════════════════════════════════════════════════ -->
<Worksheet ss:Name="Content Strategy">
<Table>
 <Column ss:Width="40"/><Column ss:Width="100"/><Column ss:Width="300"/><Column ss:Width="350"/><Column ss:Width="50"/><Column ss:Width="50"/><Column ss:Width="80"/><Column ss:Width="250"/>

 <Row><Cell ss:StyleID="title"><Data ss:Type="String">Content Strategy — Action Plan</Data></Cell></Row>
 <Row><Cell ss:StyleID="muted"><Data ss:Type="String">This plan maps keywords to blog posts. Write the "ACTION NEEDED" posts to rank for those keywords on Google.</Data></Cell></Row>
 <Row><Cell ss:StyleID="muted"><Data ss:Type="String">To auto-generate any post: go to Dashboard → Sites → click "Write Blog Post" button, or run: php agent/blog-writer.php --site=<?= $site_id ?> --topic="TOPIC"</Data></Cell></Row>
 <Row/>

 <Row>
  <Cell ss:StyleID="header"><Data ss:Type="String">#</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Action</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Blog Post Topic</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Keywords This Post Will Rank For</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Priority</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Difficulty</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Impact</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Notes</Data></Cell>
 </Row>

 <?php
 $proposal_num = 0;
 $to_write = 0;
 $already_written = 0;
 foreach ($clusters as $c):
    $proposal_num++;
    $cluster_kws = array_map('trim', explode(', ', $c['kws']));
    $is_covered = false;
    foreach ($cluster_kws as $ckw) {
        if (in_array(strtolower($ckw), $covered_keywords)) { $is_covered = true; break; }
    }
    if ($is_covered) $already_written++; else $to_write++;
    $kw_count = count($cluster_kws);
    $impact = $kw_count >= 5 ? 'HIGH' : ($kw_count >= 3 ? 'MEDIUM' : 'LOW');
    $impact_style = $kw_count >= 5 ? 'good' : ($kw_count >= 3 ? 'warn' : 'muted');
 ?>
 <Row>
  <Cell><Data ss:Type="Number"><?= $proposal_num ?></Data></Cell>
  <Cell ss:StyleID="<?= $is_covered ? 'good' : 'bad' ?>"><Data ss:Type="String"><?= $is_covered ? 'DONE' : 'ACTION NEEDED' ?></Data></Cell>
  <Cell ss:StyleID="bold"><Data ss:Type="String"><?= x(ucwords($c['cluster'])) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x(implode(', ', array_slice($cluster_kws, 0, 8))) ?></Data></Cell>
  <Cell><Data ss:Type="Number"><?= $c['avg_priority'] ?></Data></Cell>
  <Cell><Data ss:Type="Number"><?= $c['avg_difficulty'] ?></Data></Cell>
  <Cell ss:StyleID="<?= $impact_style ?>"><Data ss:Type="String"><?= $impact ?> (<?= $kw_count ?> keywords)</Data></Cell>
  <Cell ss:StyleID="muted"><Data ss:Type="String"><?= $is_covered ? 'Already published — monitor ranking' : 'Write a 800-1200 word article targeting these keywords' ?></Data></Cell>
 </Row>
 <?php endforeach; ?>

 <?php
 $stmt = $db->prepare('SELECT keyword, priority, difficulty FROM keywords WHERE site_id = ? AND cluster IS NULL AND priority >= 50 ORDER BY priority DESC LIMIT 15');
 $stmt->execute([$site_id]);
 $unclustered = $stmt->fetchAll();
 foreach ($unclustered as $uk):
    $proposal_num++;
    $is_covered = in_array(strtolower($uk['keyword']), $covered_keywords);
    if ($is_covered) $already_written++; else $to_write++;
 ?>
 <Row>
  <Cell><Data ss:Type="Number"><?= $proposal_num ?></Data></Cell>
  <Cell ss:StyleID="<?= $is_covered ? 'good' : 'bad' ?>"><Data ss:Type="String"><?= $is_covered ? 'DONE' : 'ACTION NEEDED' ?></Data></Cell>
  <Cell ss:StyleID="bold"><Data ss:Type="String"><?= x(ucwords($uk['keyword'])) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x($uk['keyword']) ?></Data></Cell>
  <Cell><Data ss:Type="Number"><?= $uk['priority'] ?></Data></Cell>
  <Cell><Data ss:Type="Number"><?= $uk['difficulty'] ?? '' ?></Data></Cell>
  <Cell><Data ss:Type="String">MEDIUM (1 keyword)</Data></Cell>
  <Cell ss:StyleID="muted"><Data ss:Type="String"><?= $is_covered ? 'Already published' : 'Write an article focused on this keyword' ?></Data></Cell>
 </Row>
 <?php endforeach; ?>

 <Row/>
 <Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">Summary</Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Total Topics Identified</Data></Cell><Cell><Data ss:Type="Number"><?= $proposal_num ?></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Already Written (DONE)</Data></Cell><Cell ss:StyleID="good"><Data ss:Type="Number"><?= $already_written ?></Data></Cell></Row>
 <Row><Cell ss:StyleID="bold"><Data ss:Type="String">Need To Write (ACTION NEEDED)</Data></Cell><Cell ss:StyleID="bad"><Data ss:Type="Number"><?= $to_write ?></Data></Cell></Row>
 <Row/>
 <Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">How To Use This Plan</Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">1.</Data></Cell><Cell ss:MergeAcross="5"><Data ss:Type="String">Look at rows marked "ACTION NEEDED" in red — these are blog posts you should write.</Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">2.</Data></Cell><Cell ss:MergeAcross="5"><Data ss:Type="String">Each row shows which keywords that blog post will help you rank for on Google.</Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">3.</Data></Cell><Cell ss:MergeAcross="5"><Data ss:Type="String">HIGH impact means the post covers many keywords at once — do these first.</Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">4.</Data></Cell><Cell ss:MergeAcross="5"><Data ss:Type="String">Low difficulty = easier to rank. High priority = more valuable to rank for.</Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">5.</Data></Cell><Cell ss:MergeAcross="5"><Data ss:Type="String">To auto-generate: click "Write Blog Post" on the dashboard, or run the blog-writer agent.</Data></Cell></Row>
 <Row><Cell><Data ss:Type="String">6.</Data></Cell><Cell ss:MergeAcross="5"><Data ss:Type="String">After publishing, re-run SEO Audit weekly to track ranking improvements.</Data></Cell></Row>
</Table>
</Worksheet>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- SHEET 3: SEO Issues -->
<!-- ═══════════════════════════════════════════════════════ -->
<Worksheet ss:Name="SEO Issues">
<Table>
 <Column ss:Width="80"/><Column ss:Width="120"/><Column ss:Width="300"/><Column ss:Width="350"/><Column ss:Width="300"/><Column ss:Width="100"/>
 <Row>
  <Cell ss:StyleID="header"><Data ss:Type="String">Severity</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Type</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">URL</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Description</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Suggested Fix</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Status</Data></Cell>
 </Row>
 <?php foreach ($issues as $i):
    $sev_style = $i['severity'] === 'critical' ? 'bad' : ($i['severity'] === 'warning' ? 'warn' : 'muted');
 ?>
 <Row>
  <Cell ss:StyleID="<?= $sev_style ?>"><Data ss:Type="String"><?= x($i['severity']) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x(str_replace('_', ' ', $i['type'])) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x($i['url']) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x($i['description']) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x($i['suggested_fix'] ?? '') ?></Data></Cell>
  <Cell ss:StyleID="<?= $i['status']==='open' ? 'bad' : ($i['status']==='resolved' ? 'good' : 'warn') ?>"><Data ss:Type="String"><?= x($i['status']) ?></Data></Cell>
 </Row>
 <?php endforeach; ?>
</Table>
</Worksheet>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- SHEET 4: Keywords -->
<!-- ═══════════════════════════════════════════════════════ -->
<Worksheet ss:Name="Keywords">
<Table>
 <Column ss:Width="300"/><Column ss:Width="150"/><Column ss:Width="70"/><Column ss:Width="70"/><Column ss:Width="70"/><Column ss:Width="100"/><Column ss:Width="120"/>
 <Row>
  <Cell ss:StyleID="header"><Data ss:Type="String">Keyword</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Cluster</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Priority</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Difficulty</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Rank</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Volume</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Last Checked</Data></Cell>
 </Row>
 <?php foreach ($keywords as $kw): ?>
 <Row>
  <Cell><Data ss:Type="String"><?= x($kw['keyword']) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x($kw['cluster'] ?? '') ?></Data></Cell>
  <Cell><Data ss:Type="Number"><?= $kw['priority'] ?></Data></Cell>
  <Cell><Data ss:Type="Number"><?= $kw['difficulty'] ?? 0 ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= $kw['current_rank'] ?? '—' ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= $kw['search_volume'] ?? '—' ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x($kw['last_checked'] ?? '') ?></Data></Cell>
 </Row>
 <?php endforeach; ?>
</Table>
</Worksheet>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- SHEET 5: Posts -->
<!-- ═══════════════════════════════════════════════════════ -->
<Worksheet ss:Name="Posts">
<Table>
 <Column ss:Width="350"/><Column ss:Width="200"/><Column ss:Width="60"/><Column ss:Width="80"/><Column ss:Width="250"/><Column ss:Width="70"/><Column ss:Width="120"/><Column ss:Width="120"/>
 <Row>
  <Cell ss:StyleID="header"><Data ss:Type="String">Title</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Slug</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Type</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Status</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">SEO Keywords</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Words</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Created</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Published</Data></Cell>
 </Row>
 <?php foreach ($posts as $p): ?>
 <Row>
  <Cell><Data ss:Type="String"><?= x($p['title']) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x($p['slug']) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x($p['type']) ?></Data></Cell>
  <Cell ss:StyleID="<?= $p['status']==='published' ? 'good' : ($p['status']==='draft' ? 'warn' : '') ?>"><Data ss:Type="String"><?= x($p['status']) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x($p['seo_keywords'] ?? '') ?></Data></Cell>
  <Cell><Data ss:Type="Number"><?= str_word_count(strip_tags($p['body'])) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x($p['created_at']) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x($p['published_at'] ?? '') ?></Data></Cell>
 </Row>
 <?php endforeach; ?>
</Table>
</Worksheet>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- SHEET 6: Agent Activity -->
<!-- ═══════════════════════════════════════════════════════ -->
<Worksheet ss:Name="Agent Activity">
<Table>
 <Column ss:Width="150"/><Column ss:Width="80"/><Column ss:Width="100"/><Column ss:Width="400"/><Column ss:Width="150"/>
 <Row>
  <Cell ss:StyleID="header"><Data ss:Type="String">Action</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Status</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Duration</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Details</Data></Cell>
  <Cell ss:StyleID="header"><Data ss:Type="String">Date</Data></Cell>
 </Row>
 <?php foreach ($logs as $l): ?>
 <Row>
  <Cell><Data ss:Type="String"><?= x($l['action']) ?></Data></Cell>
  <Cell ss:StyleID="<?= $l['status']==='success' ? 'good' : 'bad' ?>"><Data ss:Type="String"><?= x($l['status']) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= $l['duration_ms'] ? round($l['duration_ms']/1000,1).'s' : '—' ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x(truncate($l['details'] ?? '', 200)) ?></Data></Cell>
  <Cell><Data ss:Type="String"><?= x($l['created_at']) ?></Data></Cell>
 </Row>
 <?php endforeach; ?>
</Table>
</Worksheet>

</Workbook>
<?php

// XML escape helper
function x(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
