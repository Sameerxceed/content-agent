<?php
/**
 * Performance Loop dashboard — the feedback brain.
 *
 * Per-site view that surfaces:
 *   - Topline numbers (impressions, clicks, CTR, avg position over the window)
 *   - Winners: trending up — recommend writing more
 *   - Decay: traffic dropping or low CTR — refresh candidates
 *   - Dead air: published but no traction — kill or rethink
 *   - Full sortable list of published posts with channel breakdown
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/performance.php';

auth_start();
auth_require();

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { redirect('/dashboard/index.php'); }

$window = $_GET['window'] ?? '28d';
$days   = (int)preg_replace('/[^0-9]/', '', $window) ?: 28;

$summary = performance_site_summary($db, $site_id, $days);
$buckets = performance_buckets($db, $site_id);

$gsc = $db->prepare('SELECT id FROM integrations WHERE site_id = ? AND platform = "google_search_console" AND is_active = 1');
$gsc->execute([$site_id]);
$gsc_connected = (bool)$gsc->fetch();

$page_title = 'Performance — ' . $site['name'];
ob_start();

// Persistent site workflow stepper at top
$stepper_active = 'track';
include __DIR__ . '/_site_stepper.php';
?>
<style>
.pf-summary { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:18px; }
.pf-bucket  { margin-bottom:18px; }
.pf-bucket-head { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:8px; }
.pf-bucket-title { font-size:15px; font-weight:600; color:var(--primary); }
.pf-bucket-count { font-size:12px; color:var(--text-light); }
.pf-row {
    background:#fff; border:1px solid var(--border); border-radius:6px; padding:12px 14px;
    margin-bottom:6px; display:grid; grid-template-columns: 1fr auto auto auto; gap:14px; align-items:center;
}
.pf-row.winner { border-left:3px solid var(--success); }
.pf-row.decay  { border-left:3px solid var(--warning); }
.pf-row.dead   { border-left:3px solid var(--text-light); }
.pf-title { font-size:13px; font-weight:600; color:var(--text); }
.pf-meta  { font-size:11px; color:var(--text-light); margin-top:2px; }
.pf-stat  { text-align:right; font-size:12px; }
.pf-stat .v { font-size:14px; font-weight:600; color:var(--text); }
.pf-stat .l { color:var(--text-light); font-size:10px; text-transform:uppercase; letter-spacing:0.3px; }
.pf-actions button { padding:5px 10px; font-size:11px; }
.pf-reason { font-size:11px; color:var(--text-light); margin-top:3px; font-style:italic; }
.pf-empty  { color:var(--text-light); font-size:13px; padding:14px; background:#f8fafc; border-radius:6px; border:1px dashed var(--border); }
.win-tabs  { display:flex; gap:6px; }
.win-tab   { padding:4px 10px; font-size:12px; border:1px solid var(--border); border-radius:5px; text-decoration:none; color:var(--text); }
.win-tab.active { background:var(--primary); color:#fff; border-color:var(--primary); }
</style>

<div class="flex items-center justify-between mb-4">
    <div>
        <div style="font-size:11px; color:var(--text-light); text-transform:uppercase; letter-spacing:0.5px;">Performance Loop</div>
        <h2 style="font-size:18px; font-weight:600; margin:2px 0 0; color:var(--primary);"><?= e($site['name']) ?></h2>
    </div>
    <div class="flex items-center gap-2">
        <div class="win-tabs">
            <?php foreach (['7d','28d','90d'] as $w): ?>
                <a class="win-tab <?= $window === $w ? 'active' : '' ?>" href="<?= url('/dashboard/performance.php?site=' . $site_id . '&window=' . $w) ?>"><?= $w ?></a>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-outline btn-sm" onclick="fetchNow(this)">Fetch now</button>
    </div>
</div>

<?php if (!$gsc_connected): ?>
<div class="alert alert-warning">
    Google Search Console not connected for this site — organic metrics will be empty. <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>">Connect GSC</a> on the site page.
</div>
<?php endif; ?>

<div class="pf-summary">
    <div class="stat-card">
        <div class="stat-label">Impressions (<?= $window ?>)</div>
        <div class="stat-value"><?= number_format($summary['impressions']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Clicks (<?= $window ?>)</div>
        <div class="stat-value"><?= number_format($summary['clicks']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Avg CTR</div>
        <div class="stat-value"><?= $summary['ctr'] !== null ? $summary['ctr'] . '%' : '—' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Avg Position</div>
        <div class="stat-value"><?= $summary['avg_position'] !== null ? $summary['avg_position'] : '—' ?></div>
    </div>
</div>

<?php
function render_post_row($p, string $bucket_class, string $bucket_kind): void {
    $cms = $p['channels']['cms'] ?? [];
    ?>
    <div class="pf-row <?= $bucket_class ?>" data-post-id="<?= (int)$p['post_id'] ?>">
        <div>
            <div class="pf-title"><?= e($p['title']) ?></div>
            <div class="pf-meta">
                Published <?= $p['published_at'] ? date('d M', strtotime($p['published_at'])) : '—' ?>
                · /blog/<?= e($p['slug']) ?>
            </div>
            <?php if (!empty($p['reason'])): ?>
                <div class="pf-reason"><?= e($p['reason']) ?></div>
            <?php endif; ?>
        </div>
        <div class="pf-stat">
            <div class="v"><?= number_format($cms['impressions'] ?? 0) ?></div>
            <div class="l">impr</div>
        </div>
        <div class="pf-stat">
            <div class="v"><?= number_format($cms['clicks'] ?? 0) ?></div>
            <div class="l">clicks</div>
        </div>
        <div class="pf-actions">
            <?php if ($bucket_kind === 'winner'): ?>
                <button class="btn btn-success btn-sm" onclick="postAction(<?= (int)$p['post_id'] ?>, 'queue_similar', this)">Write more like this</button>
            <?php elseif ($bucket_kind === 'decay'): ?>
                <button class="btn btn-accent btn-sm" onclick="postAction(<?= (int)$p['post_id'] ?>, 'refresh', this)" title="Cannibalization check + GSC query intent + internal-link suggestions">⚡ Smart Refresh</button>
                <button class="btn btn-outline btn-sm" onclick="postAction(<?= (int)$p['post_id'] ?>, 'dismiss', this)">Dismiss</button>
            <?php elseif ($bucket_kind === 'dead'): ?>
                <button class="btn btn-outline btn-sm" onclick="postAction(<?= (int)$p['post_id'] ?>, 'dismiss', this)">Dismiss</button>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>

<div class="pf-bucket">
    <div class="pf-bucket-head">
        <span class="pf-bucket-title">🏆 Winners</span>
        <span class="pf-bucket-count"><?= count($buckets['winners']) ?> post<?= count($buckets['winners']) === 1 ? '' : 's' ?> trending up</span>
    </div>
    <?php if (!$buckets['winners']): ?>
        <div class="pf-empty">No winners yet. Posts with rising traffic or strong CTR will show up here.</div>
    <?php else: ?>
        <?php foreach ($buckets['winners'] as $p) render_post_row($p, 'winner', 'winner'); ?>
    <?php endif; ?>
</div>

<div class="pf-bucket">
    <div class="pf-bucket-head">
        <span class="pf-bucket-title">📉 Decay — refresh candidates</span>
        <span class="pf-bucket-count"><?= count($buckets['decay']) ?> post<?= count($buckets['decay']) === 1 ? '' : 's' ?> slipping</span>
    </div>
    <?php if (!$buckets['decay']): ?>
        <div class="pf-empty">Nothing slipping right now.</div>
    <?php else: ?>
        <?php foreach ($buckets['decay'] as $p) render_post_row($p, 'decay', 'decay'); ?>
    <?php endif; ?>
</div>

<div class="pf-bucket">
    <div class="pf-bucket-head">
        <span class="pf-bucket-title">🪦 Dead air</span>
        <span class="pf-bucket-count"><?= count($buckets['dead_air']) ?> post<?= count($buckets['dead_air']) === 1 ? '' : 's' ?> with no traction</span>
    </div>
    <?php if (!$buckets['dead_air']): ?>
        <div class="pf-empty">No dead-air posts.</div>
    <?php else: ?>
        <?php foreach ($buckets['dead_air'] as $p) render_post_row($p, 'dead', 'dead'); ?>
    <?php endif; ?>
</div>

<details style="margin-top:20px;">
    <summary style="cursor:pointer; font-size:13px; color:var(--text-light); font-weight:600;">All published posts (<?= count($buckets['all']) ?>)</summary>
    <div style="margin-top:10px;">
        <?php foreach ($buckets['all'] as $p): ?>
            <?php render_post_row($p, '', 'all'); ?>
        <?php endforeach; ?>
    </div>
</details>

<script>
async function postAction(postId, action, btn) {
    btn.disabled = true; const orig = btn.textContent; btn.textContent = '…';
    try {
        const res = await fetch('<?= url('/api/performance-action.php') ?>', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action, post_id: postId })
        });
        const data = await res.json();
        if (data.success) {
            if (action === 'refresh' && data.new_post_id) {
                btn.textContent = 'Draft created';
                btn.style.background = 'var(--success)';
                setTimeout(() => { window.location.href = '<?= url('/dashboard/posts.php?site=' . $site_id) ?>'; }, 800);
                return;
            }
            btn.textContent = 'Done';
            btn.style.background = 'var(--success)';
            setTimeout(() => btn.closest('.pf-row').remove(), 600);
        } else {
            btn.textContent = orig; btn.disabled = false;
            alert(data.error || 'Failed');
        }
    } catch (e) {
        btn.textContent = orig; btn.disabled = false;
        alert(e.message);
    }
}

async function fetchNow(btn) {
    btn.disabled = true; btn.textContent = 'Fetching…';
    try {
        const res = await fetch('<?= url('/api/performance-action.php') ?>', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ action:'fetch_now', site_id: <?= $site_id ?> })
        });
        const data = await res.json();
        if (data.success) { window.location.reload(); return; }
        alert((data.organic && data.organic.error) || data.error || 'Fetch failed');
        btn.disabled = false; btn.textContent = 'Fetch now';
    } catch (e) { alert(e.message); btn.disabled = false; btn.textContent = 'Fetch now'; }
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
