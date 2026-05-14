<?php
/**
 * Brand mentions — surfaces what the daily cron-brand-monitor finds.
 * GET ?site=X
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

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

// Status update
if (!empty($_GET['mark']) && !empty($_GET['status']) && in_array($_GET['status'], ['seen', 'ignored', 'new'], true)) {
    $db->prepare('UPDATE brand_mentions bm JOIN sites s ON bm.site_id = s.id SET bm.status = ? WHERE bm.id = ? AND s.user_id = ?')
        ->execute([$_GET['status'], (int)$_GET['mark'], $user_id]);
    redirect('/dashboard/brand-mentions.php?site=' . $site_id . '&filter=' . ($_GET['filter'] ?? 'new'));
}

$filter = $_GET['filter'] ?? 'new';
$where = ['site_id = ?'];
$params = [$site_id];
if (in_array($filter, ['new', 'seen', 'ignored'], true)) {
    $where[] = 'status = ?';
    $params[] = $filter;
}
$where_sql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT * FROM brand_mentions WHERE {$where_sql} ORDER BY found_at DESC LIMIT 200");
$stmt->execute($params);
$mentions = $stmt->fetchAll();

$counts = ['new' => 0, 'seen' => 0, 'ignored' => 0, 'all' => 0];
$stmt = $db->prepare('SELECT status, COUNT(*) c FROM brand_mentions WHERE site_id = ? GROUP BY status');
$stmt->execute([$site_id]);
foreach ($stmt->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['c'];
    $counts['all'] += (int)$r['c'];
}

$page_title = 'Brand Mentions — ' . $site['name'];
ob_start();
?>

<style>
.tabs { display:flex; gap:4px; border-bottom:1px solid var(--border); margin-bottom:14px; }
.tabs a { padding:8px 14px; text-decoration:none; font-size:13px; color:#64748b; border-bottom:2px solid transparent; font-weight:500; }
.tabs a.active { color:var(--accent); border-bottom-color:var(--accent); font-weight:600; }
.mention { display:flex; gap:12px; padding:12px 14px; border:1px solid var(--border); border-radius:8px; margin-bottom:6px; background:#fff; }
.mention.new { border-left:3px solid #3b82f6; }
.mention.ignored { opacity:0.5; }
.mention .body { flex:1; min-width:0; }
.mention .title { font-weight:600; font-size:13px; color:var(--primary); }
.mention .title a { color:inherit; text-decoration:none; }
.mention .domain { font-size:11px; color:#64748b; }
.mention .snippet { font-size:12px; color:#475569; margin-top:6px; line-height:1.5; }
.mention .meta { font-size:11px; color:#94a3b8; margin-top:6px; }
.mention .actions { display:flex; gap:6px; flex-shrink:0; }
.mention .actions a { font-size:11px; padding:3px 10px; border:1px solid var(--border); border-radius:4px; text-decoration:none; color:#64748b; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site['name']) ?></a>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
    <div>
        <h2 style="font-size:20px;color:var(--primary);margin:0;">Brand Mentions — <?= e($site['name']) ?></h2>
        <p style="font-size:12px;color:#64748b;margin:4px 0 0;">Where on the web your brand name has been mentioned in the last 24 hours (scanned daily).</p>
    </div>
</div>

<div class="tabs">
    <a href="<?= url('/dashboard/brand-mentions.php?site=' . $site_id . '&filter=new') ?>" class="<?= $filter === 'new' ? 'active' : '' ?>">New (<?= $counts['new'] ?>)</a>
    <a href="<?= url('/dashboard/brand-mentions.php?site=' . $site_id . '&filter=seen') ?>" class="<?= $filter === 'seen' ? 'active' : '' ?>">Reviewed (<?= $counts['seen'] ?>)</a>
    <a href="<?= url('/dashboard/brand-mentions.php?site=' . $site_id . '&filter=ignored') ?>" class="<?= $filter === 'ignored' ? 'active' : '' ?>">Ignored (<?= $counts['ignored'] ?>)</a>
    <a href="<?= url('/dashboard/brand-mentions.php?site=' . $site_id . '&filter=all') ?>" class="<?= $filter === 'all' ? 'active' : '' ?>">All (<?= $counts['all'] ?>)</a>
</div>

<?php if (empty($mentions)): ?>
<div class="card" style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;">
    <?php if ($counts['all'] === 0): ?>
        No mentions detected yet. The daily monitor scans Google for your brand name and lists results here.
    <?php else: ?>
        Nothing to show in this view.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php foreach ($mentions as $m): ?>
<div class="mention <?= e($m['status']) ?>">
    <div class="body">
        <div class="title"><a href="<?= e($m['url']) ?>" target="_blank"><?= e($m['title'] ?: '(no title)') ?> ↗</a></div>
        <div class="domain"><?= e($m['source_domain']) ?></div>
        <?php if (!empty($m['snippet'])): ?>
        <div class="snippet"><?= e($m['snippet']) ?></div>
        <?php endif; ?>
        <div class="meta">Found <?= format_date($m['found_at']) ?></div>
    </div>
    <div class="actions">
        <?php if ($m['status'] === 'new'): ?>
            <a href="<?= url('/dashboard/brand-mentions.php?site=' . $site_id . '&filter=' . e($filter) . '&mark=' . (int)$m['id'] . '&status=seen') ?>">✓ Mark reviewed</a>
            <a href="<?= url('/dashboard/brand-mentions.php?site=' . $site_id . '&filter=' . e($filter) . '&mark=' . (int)$m['id'] . '&status=ignored') ?>">👁 Ignore</a>
        <?php elseif ($m['status'] === 'seen'): ?>
            <a href="<?= url('/dashboard/brand-mentions.php?site=' . $site_id . '&filter=' . e($filter) . '&mark=' . (int)$m['id'] . '&status=ignored') ?>">👁 Ignore</a>
        <?php else: ?>
            <a href="<?= url('/dashboard/brand-mentions.php?site=' . $site_id . '&filter=' . e($filter) . '&mark=' . (int)$m['id'] . '&status=new') ?>">↺ Restore</a>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
