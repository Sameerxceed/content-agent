<?php
/**
 * Alerts page — surfaces what cron watchers detected.
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

// Mark-all-read action
if (($_POST['action'] ?? '') === 'mark_all_read') {
    if (csrf_verify()) {
        $db->prepare('UPDATE alerts SET read_at = NOW() WHERE site_id = ? AND read_at IS NULL')->execute([$site_id]);
    }
    redirect('/dashboard/alerts.php?site=' . $site_id);
}

// Mark single read
if (!empty($_GET['mark']) && (int)$_GET['mark']) {
    $db->prepare('UPDATE alerts a JOIN sites s ON a.site_id = s.id SET a.read_at = NOW() WHERE a.id = ? AND s.user_id = ?')
        ->execute([(int)$_GET['mark'], $user_id]);
    $back = $_GET['go'] ?? ('/dashboard/alerts.php?site=' . $site_id);
    if (str_starts_with($back, '/')) redirect($back);
}

$filter = $_GET['filter'] ?? 'unread'; // unread | all | critical
$where = ['site_id = ?'];
$params = [$site_id];
if ($filter === 'unread')   { $where[] = 'read_at IS NULL'; }
if ($filter === 'critical') { $where[] = "severity = 'critical'"; }
$where_sql = implode(' AND ', $where);

$stmt = $db->prepare("SELECT * FROM alerts WHERE {$where_sql} ORDER BY detected_at DESC LIMIT 200");
$stmt->execute($params);
$alerts = $stmt->fetchAll();

$counts = ['unread' => 0, 'all' => 0, 'critical' => 0];
$stmt = $db->prepare("SELECT COUNT(*) c, SUM(read_at IS NULL) u, SUM(severity = 'critical') cr FROM alerts WHERE site_id = ?");
$stmt->execute([$site_id]);
$r = $stmt->fetch();
$counts['all'] = (int)$r['c']; $counts['unread'] = (int)$r['u']; $counts['critical'] = (int)$r['cr'];

$type_icons = [
    'new_competitor'     => '🆕',
    'competitor_post'    => '📝',
    'brand_mention'      => '💬',
    'visibility_drop'    => '📉',
    'rank_drop'          => '⬇️',
    'score_drop'         => '⬇️',
    'content_gaps_found' => '💡',
    'gsc_sync_done'      => '🔄',
];
$severity_colors = ['info' => '#3b82f6', 'warning' => '#f59e0b', 'critical' => '#ef4444'];

$page_title = 'Alerts — ' . $site['name'];
ob_start();
?>

<style>
.tabs { display:flex; gap:4px; border-bottom:1px solid var(--border); margin-bottom:14px; }
.tabs a { padding:8px 14px; text-decoration:none; font-size:13px; color:#64748b; border-bottom:2px solid transparent; font-weight:500; }
.tabs a.active { color:var(--accent); border-bottom-color:var(--accent); font-weight:600; }
.alert-card { display:flex; gap:12px; align-items:flex-start; padding:12px 14px; border:1px solid var(--border); border-radius:8px; margin-bottom:6px; background:#fff; }
.alert-card.unread { border-left:4px solid #3b82f6; }
.alert-card.unread.warning { border-left-color:#f59e0b; }
.alert-card.unread.critical { border-left-color:#ef4444; }
.alert-card .icon { font-size:22px; line-height:1; }
.alert-card .body { flex:1; min-width:0; }
.alert-card .title { font-weight:600; font-size:14px; color:var(--primary); }
.alert-card .detail { font-size:12px; color:#64748b; margin-top:4px; white-space:pre-line; line-height:1.5; }
.alert-card .meta { font-size:11px; color:#94a3b8; margin-top:6px; }
.alert-card .actions { display:flex; gap:6px; flex-shrink:0; }
.alert-card .actions a { font-size:11px; padding:4px 10px; border-radius:4px; text-decoration:none; }
.alert-card .actions .primary { background:var(--accent); color:#fff; }
.alert-card .actions .secondary { background:transparent; border:1px solid var(--border); color:#64748b; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site['name']) ?></a>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
    <h2 style="font-size:20px;color:var(--primary);margin:0;">Alerts — <?= e($site['name']) ?></h2>
    <?php if ($counts['unread'] > 0): ?>
    <form method="POST" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit" class="btn btn-outline btn-sm" style="font-size:11px;">✓ Mark all as read</button>
    </form>
    <?php endif; ?>
</div>

<div class="tabs">
    <a href="<?= url('/dashboard/alerts.php?site=' . $site_id . '&filter=unread') ?>" class="<?= $filter === 'unread' ? 'active' : '' ?>">Unread (<?= $counts['unread'] ?>)</a>
    <a href="<?= url('/dashboard/alerts.php?site=' . $site_id . '&filter=critical') ?>" class="<?= $filter === 'critical' ? 'active' : '' ?>">Critical (<?= $counts['critical'] ?>)</a>
    <a href="<?= url('/dashboard/alerts.php?site=' . $site_id . '&filter=all') ?>" class="<?= $filter === 'all' ? 'active' : '' ?>">All (<?= $counts['all'] ?>)</a>
</div>

<?php if (empty($alerts)): ?>
<div class="card" style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;">
    <?php if ($counts['all'] === 0): ?>
        No alerts yet. ContentAgent will surface things here when watchers detect changes: new competitors, brand mentions, rank drops, etc.
    <?php else: ?>
        Nothing to show with the current filter.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php foreach ($alerts as $a):
    $is_unread = empty($a['read_at']);
    $icon = $type_icons[$a['type']] ?? '🔔';
?>
<div class="alert-card <?= $is_unread ? 'unread' : '' ?> <?= e($a['severity']) ?>">
    <div class="icon"><?= $icon ?></div>
    <div class="body">
        <div class="title"><?= e($a['title']) ?></div>
        <?php if (!empty($a['detail'])): ?>
        <div class="detail"><?= e($a['detail']) ?></div>
        <?php endif; ?>
        <div class="meta">
            <span style="color:<?= $severity_colors[$a['severity']] ?? '#94a3b8' ?>;font-weight:600;text-transform:uppercase;font-size:10px;letter-spacing:0.5px;"><?= e($a['severity']) ?></span>
            · <?= format_date($a['detected_at']) ?>
            <?php if (!$is_unread): ?>· read<?php endif; ?>
        </div>
    </div>
    <div class="actions">
        <?php if (!empty($a['link_url'])): ?>
            <a href="<?= url($a['link_url']) ?>" class="primary">Open →</a>
        <?php endif; ?>
        <?php if ($is_unread): ?>
            <a href="<?= url('/dashboard/alerts.php?site=' . $site_id . '&mark=' . (int)$a['id'] . '&filter=' . e($filter)) ?>" class="secondary">Mark read</a>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
