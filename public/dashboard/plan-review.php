<?php
/**
 * Dashboard — Monthly Plan Review.
 *
 * User reviews AI-proposed changes from the last 30 days' performance:
 *   1. Performance summary widget at top
 *   2. Learnings list (informational)
 *   3. Proposed changes table — each row has a checkbox; user picks which to apply
 *   4. Forecast update (if any)
 *   5. Footer: Approve Selected / Approve All / Reject All
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$review_id = (int)($_GET['id'] ?? 0);
if (!$review_id) { http_response_code(400); exit('Missing id'); }

$stmt = $db->prepare("SELECT r.*, p.cadence_posts_per_week FROM plan_reviews r
    JOIN content_plans p ON r.plan_id = p.id
    WHERE r.id = ?");
$stmt->execute([$review_id]);
$review = $stmt->fetch();
if (!$review) { http_response_code(404); exit('Review not found'); }

$site_id = (int)$review['site_id'];
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(403); exit('Access denied'); }

$summary  = json_decode($review['summary'] ?? '{}', true) ?: [];
$learnings = json_decode($review['learnings'] ?? '[]', true) ?: [];
$changes  = json_decode($review['proposed_changes'] ?? '{}', true) ?: [];
$forecast = json_decode($review['forecast_update'] ?? 'null', true);

$page_title = 'Monthly Plan Review — ' . $site['name'];
ob_start();

$stepper_active = 'publish';
include __DIR__ . '/_site_stepper.php';
?>

<style>
.rv-head { background:#fff; border:1px solid var(--border); border-radius:8px; padding:14px 18px; margin-bottom:12px; }
.rv-head h1 { margin:0; font-size:18px; font-weight:600; color:var(--primary); }
.rv-head .meta { font-size:12px; color:#64748b; margin-top:4px; }
.rv-head .status { display:inline-block; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:600; margin-left:8px; }
.rv-st-proposed   { background:#fef3c7; color:#92400e; }
.rv-st-approved   { background:#dcfce7; color:#166534; }
.rv-st-rejected   { background:#fee2e2; color:#991b1b; }
.rv-st-expired    { background:#f1f5f9; color:#64748b; }
.rv-st-partial    { background:#dbeafe; color:#1e40af; }

.rv-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:8px; margin-bottom:10px; }
.rv-stat { background:#fff; border:1px solid var(--border); border-radius:8px; padding:10px 12px; }
.rv-stat .label { font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; font-weight:600; }
.rv-stat .value { font-size:18px; font-weight:700; color:var(--primary); margin-top:2px; }
.rv-stat .sub { font-size:10px; color:#94a3b8; margin-top:2px; }

.rv-card { background:#fff; border:1px solid var(--border); border-radius:8px; padding:12px 14px; margin-bottom:10px; }
.rv-card h3 { margin:0 0 8px; font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; }
.rv-learning { padding:8px 12px; background:#f5f3ff; border-left:3px solid #8b5cf6; font-size:13px; color:#5b21b6; margin-bottom:6px; border-radius:0 4px 4px 0; line-height:1.5; }

.rv-changes h3 { font-size:13px; }
.rv-change { display:grid; grid-template-columns:32px 100px 1fr; gap:10px; padding:10px 0; border-bottom:1px solid #f1f5f9; font-size:13px; align-items:start; }
.rv-change:last-child { border-bottom:0; }
.rv-change input[type=checkbox] { width:16px; height:16px; margin-top:2px; }
.rv-change .type { font-size:10px; font-weight:600; padding:3px 9px; border-radius:10px; text-transform:uppercase; letter-spacing:0.4px; text-align:center; }
.rv-t-swap        { background:#dbeafe; color:#1e40af; }
.rv-t-addition    { background:#dcfce7; color:#166534; }
.rv-t-removal     { background:#fee2e2; color:#991b1b; }
.rv-t-reschedule  { background:#fef3c7; color:#92400e; }
.rv-change .title { font-weight:600; color:var(--primary); }
.rv-change .reason { font-size:11px; color:#64748b; margin-top:3px; font-style:italic; }

.rv-forecast { padding:12px 14px; background:linear-gradient(135deg,#f5f3ff 0%,#ede9fe 100%); border:1px solid #ddd6fe; border-radius:8px; }
.rv-forecast .num { font-size:18px; font-weight:700; color:#5b21b6; }
.rv-forecast .sub { font-size:11px; color:#6b21a8; margin-top:3px; }

.rv-footer { position:sticky; bottom:0; background:#fff; border-top:1px solid var(--border); padding:12px 0; margin-top:14px; display:flex; gap:8px; justify-content:flex-end; align-items:center; flex-wrap:wrap; }
.rv-footer .count { margin-right:auto; font-size:13px; color:#64748b; }
.rv-btn-primary { background:#10b981; color:#fff; border:1px solid #10b981; padding:8px 14px; font-size:13px; font-weight:600; border-radius:6px; cursor:pointer; }
.rv-btn-primary:disabled { background:#94a3b8; border-color:#94a3b8; cursor:not-allowed; }
.rv-btn-outline { background:#fff; color:#475569; border:1px solid var(--border); padding:8px 12px; font-size:12px; border-radius:6px; cursor:pointer; }
.rv-btn-danger  { background:#fff; color:#dc2626; border:1px solid #fecaca; padding:8px 12px; font-size:12px; border-radius:6px; cursor:pointer; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/plan.php?site=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">← Back to Plan</a>
</div>

<div class="rv-head">
    <h1>📊 Monthly Plan Review
        <span class="rv-status rv-st-<?= e($review['status']) ?>"><?= e(str_replace('_', ' ', $review['status'])) ?></span>
    </h1>
    <div class="meta">
        Period: <strong><?= e(date('d M', strtotime($review['period_start']))) ?></strong> →
        <strong><?= e(date('d M Y', strtotime($review['period_end']))) ?></strong> ·
        Created <?= e(format_date($review['created_at'])) ?>
        <?php if ($review['status'] === 'proposed'): ?>
            · Expires <?= e(format_date($review['expires_at'])) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Performance summary -->
<div class="rv-stats">
    <div class="rv-stat">
        <div class="label">Posts published</div>
        <div class="value"><?= (int)($summary['posts_published'] ?? 0) ?></div>
        <div class="sub">in the last 30 days</div>
    </div>
    <div class="rv-stat">
        <div class="label">Clicks gained</div>
        <div class="value"><?= number_format((int)($summary['total_clicks_gained'] ?? 0)) ?></div>
        <div class="sub">via Google Search Console</div>
    </div>
    <div class="rv-stat">
        <div class="label">Winners</div>
        <div class="value"><?= count($summary['winners'] ?? []) ?></div>
        <div class="sub">overperformed forecast</div>
    </div>
    <div class="rv-stat">
        <div class="label">Underperformers</div>
        <div class="value"><?= count($summary['underperformers'] ?? []) ?></div>
        <div class="sub">below 30% of forecast</div>
    </div>
</div>

<!-- Learnings -->
<?php if ($learnings): ?>
<div class="rv-card">
    <h3>💡 What the AI learned</h3>
    <?php foreach ($learnings as $L): ?>
        <div class="rv-learning"><?= e((string)$L) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Forecast update -->
<?php if ($forecast && !empty($forecast['updated_range'])): ?>
<div class="rv-card">
    <h3>📈 Forecast update</h3>
    <div class="rv-forecast">
        <div class="num">
            Previous: <?= number_format((int)($forecast['previous_range'][0] ?? 0)) ?> – <?= number_format((int)($forecast['previous_range'][1] ?? 0)) ?>
            → Updated: <?= number_format((int)($forecast['updated_range'][0] ?? 0)) ?> – <?= number_format((int)($forecast['updated_range'][1] ?? 0)) ?> clicks/mo
        </div>
        <?php if (!empty($forecast['drivers'])): ?>
            <div class="sub">Drivers: <?= e(implode(' · ', (array)$forecast['drivers'])) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Proposed changes -->
<?php if ($review['status'] === 'proposed'): ?>
<form id="rv-form" class="rv-card rv-changes">
    <h3>📝 Proposed changes — pick which to apply</h3>

    <?php $cnt = 0; foreach (($changes['swaps'] ?? []) as $i => $sw): $cnt++; ?>
        <label class="rv-change">
            <input type="checkbox" name="approved[]" value="swap:<?= $i ?>" checked>
            <span class="type rv-t-swap">Swap</span>
            <div>
                <div class="title"><?= e($sw['from_keyword'] ?? '(unknown)') ?> → keyword id #<?= (int)($sw['to_keyword_id'] ?? 0) ?></div>
                <div class="reason"><?= e($sw['reason'] ?? '') ?> (item #<?= (int)($sw['item_id'] ?? 0) ?>)</div>
            </div>
        </label>
    <?php endforeach; ?>

    <?php foreach (($changes['reschedules'] ?? []) as $i => $rs): $cnt++; ?>
        <label class="rv-change">
            <input type="checkbox" name="approved[]" value="reschedule:<?= $i ?>" checked>
            <span class="type rv-t-reschedule">Reschedule</span>
            <div>
                <div class="title">Item #<?= (int)($rs['item_id'] ?? 0) ?>: <?= e($rs['from_date'] ?? '') ?> → <?= e($rs['to_date'] ?? '') ?></div>
                <div class="reason"><?= e($rs['reason'] ?? '') ?></div>
            </div>
        </label>
    <?php endforeach; ?>

    <?php foreach (($changes['additions'] ?? []) as $i => $ad): $cnt++; ?>
        <label class="rv-change">
            <input type="checkbox" name="approved[]" value="addition:<?= $i ?>" checked>
            <span class="type rv-t-addition">Add</span>
            <div>
                <div class="title"><?= e($ad['proposed_title'] ?? '(untitled)') ?></div>
                <div class="reason">
                    Keyword id #<?= (int)($ad['primary_keyword_id'] ?? 0) ?> in cluster #<?= (int)($ad['cluster_id'] ?? 0) ?>
                    · target week <?= (int)($ad['target_week'] ?? 0) ?>
                    · <?= e($ad['content_type'] ?? 'blog') ?>
                    — <?= e($ad['reason'] ?? '') ?>
                </div>
            </div>
        </label>
    <?php endforeach; ?>

    <?php foreach (($changes['removals'] ?? []) as $i => $rm): $cnt++; ?>
        <label class="rv-change">
            <input type="checkbox" name="approved[]" value="removal:<?= $i ?>" checked>
            <span class="type rv-t-removal">Remove</span>
            <div>
                <div class="title">Item #<?= (int)($rm['item_id'] ?? 0) ?></div>
                <div class="reason"><?= e($rm['reason'] ?? '') ?></div>
            </div>
        </label>
    <?php endforeach; ?>

    <?php if ($cnt === 0): ?>
        <div style="padding:20px;text-align:center;color:#94a3b8;font-size:13px;">No proposed changes for this period — the plan is on track.</div>
    <?php endif; ?>
</form>

<div class="rv-footer">
    <div class="count" id="rv-count"><?= $cnt ?> change<?= $cnt === 1 ? '' : 's' ?> selected</div>
    <button class="rv-btn-outline" onclick="toggleAll(false)">Deselect all</button>
    <button class="rv-btn-outline" onclick="toggleAll(true)">Select all</button>
    <button class="rv-btn-danger" onclick="rejectReview(<?= $review_id ?>)">Reject all</button>
    <button class="rv-btn-primary" onclick="applyReview(<?= $review_id ?>)">✓ Approve selected</button>
</div>

<?php else: ?>
<div class="rv-card">
    <div style="font-size:13px;color:#64748b;text-align:center;">
        This review is <strong><?= e($review['status']) ?></strong>.
        <?= (int)$review['applied_change_count'] ?> change<?= $review['applied_change_count'] === '1' ? '' : 's' ?> applied.
        Reviewed <?= e(format_date($review['reviewed_at'] ?? '')) ?>.
    </div>
</div>
<?php endif; ?>

<script>
function updateCount() {
    const n = document.querySelectorAll('input[name="approved[]"]:checked').length;
    const el = document.getElementById('rv-count');
    if (el) el.textContent = n + ' change' + (n === 1 ? '' : 's') + ' selected';
}
document.querySelectorAll('input[name="approved[]"]').forEach(cb => cb.addEventListener('change', updateCount));

function toggleAll(checked) {
    document.querySelectorAll('input[name="approved[]"]').forEach(cb => cb.checked = checked);
    updateCount();
}

async function applyReview(reviewId) {
    const ids = Array.from(document.querySelectorAll('input[name="approved[]"]:checked')).map(cb => cb.value);
    if (!confirm('Apply ' + ids.length + ' change' + (ids.length === 1 ? '' : 's') + ' to the plan?')) return;
    const res = await fetch('<?= url('/api/plan-review-action.php') ?>', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'apply', review_id: reviewId, approved_ids: ids })
    });
    const data = await res.json();
    if (data.success) {
        const a = data.applied || {};
        alert('Applied — ' + (a.swaps || 0) + ' swaps, ' + (a.additions || 0) + ' additions, ' + (a.removals || 0) + ' removals, ' + (a.reschedules || 0) + ' reschedules.');
        location.reload();
    } else {
        alert('Failed: ' + (data.error || 'unknown'));
    }
}

async function rejectReview(reviewId) {
    if (!confirm('Reject all proposed changes? The plan will continue as-is until next review.')) return;
    const res = await fetch('<?= url('/api/plan-review-action.php') ?>', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'reject', review_id: reviewId })
    });
    const data = await res.json();
    if (data.success) { location.reload(); } else { alert('Failed: ' + (data.error || 'unknown')); }
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
