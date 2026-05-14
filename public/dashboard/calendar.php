<?php
/**
 * Content Calendar — visual view of scheduled + recently published posts.
 *
 * Pulls from post_channels for scheduled/published, and posts for drafts.
 * Month grid by default; click any cell to see what's scheduled that day.
 *
 * GET ?site=X[&month=YYYY-MM]
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/channels/registry.php';

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

// Parse month
$month_param = $_GET['month'] ?? date('Y-m');
$first_of_month = strtotime($month_param . '-01');
if (!$first_of_month) $first_of_month = strtotime(date('Y-m-01'));
$month_label = date('F Y', $first_of_month);
$prev_month = date('Y-m', strtotime('-1 month', $first_of_month));
$next_month = date('Y-m', strtotime('+1 month', $first_of_month));

$days_in_month = (int)date('t', $first_of_month);
$first_weekday = (int)date('w', $first_of_month); // 0 = Sunday

// Date window (extend a few days each side so we don't miss cross-month items)
$from = date('Y-m-d 00:00:00', strtotime('-7 days', $first_of_month));
$to   = date('Y-m-d 23:59:59', strtotime('+' . ($days_in_month + 7) . ' days', $first_of_month));

// Scheduled / published items per channel
$stmt = $db->prepare("SELECT pc.*, p.title, p.slug
    FROM post_channels pc
    JOIN posts p ON pc.post_id = p.id
    WHERE p.site_id = ?
      AND (
        (pc.status IN ('queued','publishing') AND pc.scheduled_for BETWEEN ? AND ?)
        OR (pc.status = 'published' AND pc.published_at BETWEEN ? AND ?)
        OR (pc.status = 'failed' AND pc.updated_at BETWEEN ? AND ?)
      )
    ORDER BY COALESCE(pc.scheduled_for, pc.published_at, pc.updated_at) ASC");
$stmt->execute([$site_id, $from, $to, $from, $to, $from, $to]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by date (YYYY-MM-DD)
$by_date = [];
foreach ($rows as $r) {
    $key_dt = $r['scheduled_for'] ?: ($r['published_at'] ?: $r['updated_at']);
    if (!$key_dt) continue;
    $day = date('Y-m-d', strtotime($key_dt));
    $by_date[$day][] = $r;
}

// Unscheduled drafts to pull from (sidebar)
$stmt = $db->prepare("SELECT id, title, status, created_at FROM posts WHERE site_id = ? AND status = 'draft' ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$site_id]);
$drafts = $stmt->fetchAll();

// Recent content gaps as inspiration
$gaps = [];
try {
    $stmt = $db->prepare("SELECT id, topic, competitor_count FROM content_gaps WHERE site_id = ? AND status = 'open' ORDER BY competitor_count DESC LIMIT 10");
    $stmt->execute([$site_id]);
    $gaps = $stmt->fetchAll();
} catch (PDOException $e) {}

$registry = channels_registry();

$page_title = 'Calendar — ' . $site['name'];
ob_start();
?>

<style>
.cal-wrapper { display:grid; grid-template-columns: 1fr 280px; gap:14px; }
.cal-grid { display:grid; grid-template-columns: repeat(7, 1fr); gap:1px; background:var(--border); border:1px solid var(--border); border-radius:6px; overflow:hidden; }
.cal-grid > div { background:#fff; min-height: 110px; padding:6px; font-size:11px; position:relative; }
.cal-grid > .cal-head { background:#f8fafc; min-height: 28px; text-align:center; font-weight:600; color:#64748b; padding:6px 0; }
.cal-grid > .cal-blank { background:#fafbfc; min-height: 110px; }
.cal-day-num { font-weight:600; color:#475569; font-size:11px; margin-bottom:4px; }
.cal-day-num.today { background:var(--accent); color:#fff; border-radius:3px; padding:1px 6px; display:inline-block; }
.cal-item { display:block; font-size:10px; padding:2px 4px; border-radius:3px; margin-bottom:2px; text-decoration:none; color:#fff; line-height:1.4; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cal-item.failed { background:#dc2626; }
.cal-item.publishing { background:#f59e0b; }
.sidebar-card { background:#fff; border:1px solid var(--border); border-radius:6px; padding:10px 12px; margin-bottom:10px; }
.sidebar-card h4 { font-size:12px; color:var(--primary); margin:0 0 6px; font-weight:600; }
.sidebar-card .item { padding:5px 0; border-bottom:1px solid #f1f5f9; font-size:12px; }
.sidebar-card .item:last-child { border-bottom:none; }
.sidebar-card .item a { color:var(--primary); text-decoration:none; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site['name']) ?></a>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
    <div>
        <h2 style="font-size:20px;color:var(--primary);margin:0;">📅 Calendar — <?= e($site['name']) ?></h2>
        <p style="font-size:12px;color:#64748b;margin:4px 0 0;">Scheduled and published posts across every channel. Each colored block is one publication.</p>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
        <a href="<?= url('/dashboard/calendar.php?site=' . $site_id . '&month=' . $prev_month) ?>" class="btn btn-outline btn-sm" style="text-decoration:none;">←</a>
        <div style="font-weight:600;font-size:14px;min-width:140px;text-align:center;"><?= $month_label ?></div>
        <a href="<?= url('/dashboard/calendar.php?site=' . $site_id . '&month=' . $next_month) ?>" class="btn btn-outline btn-sm" style="text-decoration:none;">→</a>
        <a href="<?= url('/dashboard/calendar.php?site=' . $site_id) ?>" class="btn btn-outline btn-sm" style="text-decoration:none;">Today</a>
    </div>
</div>

<div class="cal-wrapper">
    <div>
        <div class="cal-grid">
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
                <div class="cal-head"><?= $d ?></div>
            <?php endforeach; ?>

            <?php for ($i = 0; $i < $first_weekday; $i++): ?>
                <div class="cal-blank"></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $days_in_month; $day++):
                $date_str = date('Y-m-d', strtotime($month_param . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT)));
                $is_today = $date_str === date('Y-m-d');
                $items = $by_date[$date_str] ?? [];
            ?>
            <div>
                <div class="cal-day-num <?= $is_today ? 'today' : '' ?>"><?= $day ?></div>
                <?php foreach ($items as $r):
                    $adapter = $registry->get($r['channel']);
                    $color = $adapter ? $adapter->color() : '#64748b';
                    $time = date('H:i', strtotime($r['scheduled_for'] ?: $r['published_at']));
                    $cls = $r['status'] === 'failed' ? 'failed' : ($r['status'] === 'publishing' ? 'publishing' : '');
                ?>
                <a href="<?= url('/dashboard/posts.php?action=edit&id=' . (int)$r['post_id']) ?>"
                   class="cal-item <?= $cls ?>"
                   style="background:<?= $cls ? '' : $color ?>;"
                   title="<?= e($adapter ? $adapter->display_name() : $r['channel']) ?> · <?= ucfirst($r['status']) ?> · <?= e($r['title']) ?>">
                    <?= $time ?> · <?= e($adapter ? $adapter->icon() : '?') ?> <?= e(mb_substr($r['title'], 0, 22)) ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endfor; ?>
        </div>

        <!-- Legend -->
        <div style="display:flex;gap:14px;margin-top:10px;font-size:11px;flex-wrap:wrap;">
            <?php foreach ($registry->all() as $a): ?>
            <span style="display:flex;align-items:center;gap:4px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:<?= $a->color() ?>;"></span>
                <?= e($a->display_name()) ?>
            </span>
            <?php endforeach; ?>
            <span style="display:flex;align-items:center;gap:4px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:#dc2626;"></span>
                Failed
            </span>
        </div>
    </div>

    <div>
        <div class="sidebar-card">
            <h4>📝 Drafts to schedule (<?= count($drafts) ?>)</h4>
            <?php if (empty($drafts)): ?>
                <div style="font-size:11px;color:#94a3b8;">No drafts. <a href="<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>">Write one →</a></div>
            <?php else: ?>
                <?php foreach ($drafts as $d): ?>
                <div class="item">
                    <a href="<?= url('/dashboard/posts.php?action=edit&id=' . (int)$d['id']) ?>"><?= e(mb_substr($d['title'], 0, 50)) ?></a>
                    <div style="font-size:10px;color:#94a3b8;"><?= format_date($d['created_at'], 'd M') ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if (!empty($gaps)): ?>
        <div class="sidebar-card">
            <h4>💡 Content gaps (write these)</h4>
            <?php foreach ($gaps as $g): ?>
            <div class="item">
                <a href="<?= url('/dashboard/content-gaps.php?site=' . $site_id) ?>"><?= e(mb_substr($g['topic'], 0, 45)) ?></a>
                <div style="font-size:10px;color:#94a3b8;"><?= (int)$g['competitor_count'] ?> competitors cover this</div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="sidebar-card">
            <h4>Quick actions</h4>
            <div style="display:flex;flex-direction:column;gap:6px;">
                <a href="<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>" class="btn btn-accent btn-sm" style="text-decoration:none;text-align:center;">+ Write a post</a>
                <a href="<?= url('/dashboard/posts.php?site=' . $site_id) ?>" class="btn btn-outline btn-sm" style="text-decoration:none;text-align:center;">All posts</a>
                <a href="<?= url('/dashboard/content-gaps.php?site=' . $site_id) ?>" class="btn btn-outline btn-sm" style="text-decoration:none;text-align:center;">Content gaps</a>
            </div>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
