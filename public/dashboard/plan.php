<?php
/**
 * Dashboard — Content Plan view.
 *
 * If no active plan: shows a "Generate Content Plan" hero CTA.
 * If active plan exists: forecast hero, cluster grid, rolling pipeline timeline.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/content_plan.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) redirect('/dashboard/index.php');

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

$plan = plan_get_active($db, $site_id);
$plan_full = $plan ? plan_get_full($db, (int)$plan['id']) : null;

// Pending monthly review (if any)
$pending_review_id = null;
if ($plan) {
    $stmt = $db->prepare("SELECT id FROM plan_reviews WHERE plan_id = ? AND status = 'proposed' ORDER BY id DESC LIMIT 1");
    $stmt->execute([(int)$plan['id']]);
    $pending_review_id = $stmt->fetchColumn() ?: null;
}

// Pre-compute helpful counts for the empty-state CTA
$active_keyword_count = 0;
$stmt = $db->prepare("SELECT COUNT(*) FROM keywords WHERE site_id = ? AND status = 'active'");
$stmt->execute([$site_id]);
$active_keyword_count = (int)$stmt->fetchColumn();

$page_title = 'Content Plan — ' . $site['name'];
ob_start();

// Persistent site workflow stepper at top
$stepper_active = 'publish';
include __DIR__ . '/_site_stepper.php';
?>

<style>
.plan-hero { padding:18px 22px; background:linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%); border:1px solid #ddd6fe; border-radius:10px; margin-bottom:14px; }
.plan-hero .label { font-size:11px; color:#6d28d9; text-transform:uppercase; letter-spacing:0.6px; font-weight:600; }
.plan-hero .number { font-size:34px; font-weight:700; color:#5b21b6; line-height:1.1; margin-top:4px; }
.plan-hero .sub  { font-size:13px; color:#6b21a8; margin-top:6px; line-height:1.5; }
.plan-hero .meta { display:flex; gap:18px; margin-top:12px; font-size:11px; color:#6d28d9; }
.plan-hero .meta strong { font-weight:600; }

.plan-progress-bar { height:8px; background:#e9d5ff; border-radius:4px; margin-top:10px; overflow:hidden; }
.plan-progress-bar > div { height:100%; background:#8b5cf6; }

.cluster-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:10px; margin-bottom:14px; }
.cluster-card { padding:12px 14px; background:#fff; border:1px solid var(--border); border-radius:8px; }
.cluster-card .name { font-size:13px; font-weight:600; color:var(--primary); margin-bottom:3px; }
.cluster-card .angle { font-size:11px; color:#64748b; line-height:1.5; margin-bottom:8px; }
.cluster-card .meta { display:flex; justify-content:space-between; font-size:11px; color:#475569; padding-top:8px; border-top:1px solid #f1f5f9; }
.cluster-card .meta strong { color:#5b21b6; }

.item-row { display:grid; grid-template-columns:90px 1fr auto auto auto; gap:12px; padding:10px 14px; align-items:center; border-bottom:1px solid #f1f5f9; font-size:12px; transition:background 0.1s; }
.item-row:hover { background:#f8fafb; }
.item-row:last-child { border-bottom:0; }
.item-row .date { font-weight:600; color:#475569; }
.item-row .title { color:var(--primary); }
.item-row .kw { font-size:11px; color:#94a3b8; margin-top:2px; }
.item-row .badge { font-size:10px; font-weight:600; padding:2px 7px; border-radius:10px; }
.badge-pillar      { background:#fef3c7; color:#92400e; }
.badge-supporting  { background:#f1f5f9; color:#475569; }
.badge-quick_win   { background:#dcfce7; color:#166534; }
.badge-new_content { background:#dbeafe; color:#1e40af; }
.badge-aeo_gap     { background:#fce7f3; color:#9d174d; }
.badge-long_tail   { background:#f1f5f9; color:#475569; }
.lock-pipeline  { color:#94a3b8; }
.lock-committed { color:#1e40af; }
.lock-drafted   { color:#d97706; }
.lock-published { color:#166534; }

.empty-state { padding:40px 32px; background:#fff; border:2px dashed #e2e8f0; border-radius:10px; text-align:center; }
.empty-state .icon { font-size:42px; margin-bottom:10px; }
.empty-state .title { font-size:18px; font-weight:600; color:var(--primary); margin-bottom:6px; }
.empty-state .desc { font-size:13px; color:#475569; max-width:520px; margin:0 auto 16px; line-height:1.6; }
.empty-state .cta  { display:inline-block; padding:10px 22px; font-size:14px; font-weight:600; background:#7c3aed; color:#fff; border:0; border-radius:6px; cursor:pointer; }
.empty-state .cta:disabled { opacity:0.5; cursor:not-allowed; }
.empty-state .hint { font-size:11px; color:#94a3b8; margin-top:12px; }

#plan-status { margin-top:10px; font-size:12px; }
</style>

<?php if (!$plan): ?>

    <div class="empty-state">
        <div class="icon">📋</div>
        <div class="title">No content plan yet</div>
        <div class="desc">
            ContentAgent can generate a 6-month rolling plan from your keyword list — 8 to 12 topic clusters,
            ~24 posts in the visible pipeline, sequenced for quick wins first, then pillars and supporting
            content. Forecast with estimated clicks/mo at 6 months.
        </div>
        <?php if ($active_keyword_count < 30): ?>
            <button class="cta" disabled>📋 Generate Content Plan</button>
            <div class="hint">Need at least 30 active keywords. You currently have <?= $active_keyword_count ?>. Run <a href="<?= url('/dashboard/keywords.php?site=' . $site_id) ?>" style="color:#7c3aed;">Find Keywords</a> first.</div>
        <?php else: ?>
            <button id="plan-generate-btn" class="cta" onclick="generateContentPlan(<?= $site_id ?>)">📋 Generate Content Plan</button>
            <div class="hint">Uses your <?= $active_keyword_count ?> active keywords. Takes ~3-5 minutes. You can review and edit everything after.</div>
        <?php endif; ?>
        <div id="plan-status"></div>
    </div>

<?php else:
    $forecast_low  = (int)($plan['estimated_clicks_at_horizon_low']  ?? 0);
    $forecast_high = (int)($plan['estimated_clicks_at_horizon_high'] ?? 0);
    $total = (int)$plan['total_items_scheduled'];
    $pub   = (int)$plan['total_items_published'];
    $pct   = $total > 0 ? (int)round(($pub / $total) * 100) : 0;
?>

    <?php if ($pending_review_id): ?>
    <a href="<?= url('/dashboard/plan-review.php?id=' . (int)$pending_review_id) ?>"
       style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 16px;margin-bottom:10px;background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);border:1px solid #f59e0b;border-radius:8px;text-decoration:none;color:inherit;">
        <div>
            <div style="font-size:13px;font-weight:600;color:#92400e;">📊 Monthly review is ready for your approval</div>
            <div style="font-size:11px;color:#78350f;margin-top:2px;">The AI analysed last month's performance and is proposing pipeline updates. Click to review.</div>
        </div>
        <span style="background:#d97706;color:#fff;padding:7px 14px;font-size:12px;font-weight:600;border-radius:6px;white-space:nowrap;">Review now →</span>
    </a>
    <?php endif; ?>

    <div class="plan-hero">
        <div class="label">Forecast at <?= (int)$plan['forecast_horizon_weeks'] ?> weeks</div>
        <div class="number"><?= number_format($forecast_low) ?> – <?= number_format($forecast_high) ?> <span style="font-size:16px;font-weight:500;color:#7c3aed;">organic clicks / month</span></div>
        <div class="sub">Based on <?= (int)$plan['total_clusters'] ?> topic clusters and <?= $total ?> planned items, at <?= (int)$plan['cadence_posts_per_week'] ?> posts/week. Rolls forward monthly based on what's actually ranking.</div>
        <div class="plan-progress-bar"><div style="width:<?= $pct ?>%;"></div></div>
        <div class="meta">
            <span><strong><?= $pub ?></strong> of <?= $total ?> published (<?= $pct ?>%)</span>
            <span>Pipeline extends to <strong><?= e(date('d M Y', strtotime($plan['pipeline_extends_to']))) ?></strong></span>
            <span>Last reviewed: <strong><?= $plan['last_review_at'] ? e(date('d M', strtotime($plan['last_review_at']))) : 'never' ?></strong></span>
        </div>
    </div>

    <!-- Cluster grid -->
    <?php if (!empty($plan_full['clusters'])): ?>
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.6px;color:#64748b;font-weight:600;margin-bottom:6px;">Topic clusters (<?= count($plan_full['clusters']) ?>)</div>
    <div class="cluster-grid">
        <?php foreach ($plan_full['clusters'] as $c): ?>
            <div class="cluster-card">
                <div class="name">📚 <?= e($c['name']) ?></div>
                <div class="angle"><?= e($c['angle'] ?? '') ?></div>
                <div class="meta">
                    <span><strong><?= (int)$c['item_count'] ?></strong> posts</span>
                    <span><strong><?= number_format((int)$c['cluster_clicks']) ?></strong> est clicks/mo</span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Pipeline timeline (next 12 items) -->
    <?php if (!empty($plan_full['items'])): ?>
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.6px;color:#64748b;font-weight:600;margin:14px 0 6px;">Pipeline — next items</div>
    <div class="card" style="padding:0;">
        <?php foreach (array_slice($plan_full['items'], 0, 25) as $it):
            $date = date('D, d M', strtotime($it['target_publish_date']));
            $lock_cls = 'lock-' . e($it['lock_state']);
        ?>
            <a href="<?= url('/dashboard/plan-item.php?id=' . (int)$it['id']) ?>" class="item-row" style="text-decoration:none;color:inherit;">
                <div class="date"><?= $date ?></div>
                <div>
                    <div class="title"><?= e($it['proposed_title'] ?? '(untitled)') ?></div>
                    <div class="kw">→ <?= e($it['primary_keyword']) ?> · <?= (int)($it['search_volume'] ?? 0) ?> vol</div>
                </div>
                <div><span class="badge badge-<?= e($it['role']) ?>"><?= e($it['role']) ?></span></div>
                <div><span class="badge badge-<?= e($it['bucket']) ?>"><?= e(str_replace('_', ' ', $it['bucket'])) ?></span></div>
                <div class="<?= $lock_cls ?>" style="font-size:10px;text-transform:uppercase;letter-spacing:0.4px;font-weight:600;"><?= e($it['lock_state']) ?></div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
        <a href="<?= url('/dashboard/keywords.php?site=' . $site_id) ?>" class="btn btn-outline btn-sm">← Back to Keywords</a>
        <button onclick="if(confirm('Regenerate the plan? Existing pipeline items in the new plan will be re-sequenced.')) generateContentPlan(<?= $site_id ?>)" class="btn btn-outline btn-sm">🔄 Regenerate plan</button>
        <button onclick="runReviewNow(<?= (int)$plan['id'] ?>)" class="btn btn-outline btn-sm" title="Generate a fresh monthly performance review now, instead of waiting for the 1st-of-month cron">📊 Run review now</button>
    </div>

<?php endif; ?>

<script>
async function generateContentPlan(siteId) {
    const btn = document.getElementById('plan-generate-btn');
    const status = document.getElementById('plan-status');
    if (btn) { btn.disabled = true; btn.textContent = 'Queuing…'; }
    if (status) status.innerHTML = '<span style="color:#64748b;">Starting background job…</span>';
    try {
        const res = await fetch('<?= url('/api/content-plan-start.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ site_id: siteId })
        });
        const data = await res.json();
        if (!data.success || !data.job_id) {
            if (status) status.innerHTML = '<span style="color:#dc2626;">✗ ' + (data.error || 'Failed to start') + '</span>';
            if (btn) { btn.disabled = false; btn.textContent = '📋 Generate Content Plan'; }
            return;
        }
        pollPlanStatus(data.job_id);
    } catch (e) {
        if (status) status.innerHTML = '<span style="color:#dc2626;">✗ ' + e.message + '</span>';
        if (btn) { btn.disabled = false; btn.textContent = '📋 Generate Content Plan'; }
    }
}

async function runReviewNow(planId) {
    if (!confirm('Generate a fresh monthly review now? It will analyse the last 30 days of performance and propose changes. (~1 min)')) return;
    try {
        const res = await fetch('<?= url('/api/plan-review-action.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'generate_now', plan_id: planId })
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message || 'Queued.');
            // Reload after ~70s so the new review banner appears
            setTimeout(() => location.reload(), 70000);
        } else {
            alert('Failed: ' + (data.error || 'unknown'));
        }
    } catch(e) { alert('Error: ' + e.message); }
}

function pollPlanStatus(jobId) {
    const btn = document.getElementById('plan-generate-btn');
    const status = document.getElementById('plan-status');
    const tick = async () => {
        try {
            const res = await fetch('<?= url('/api/content-plan-status.php') ?>?id=' + jobId);
            const data = await res.json();
            const step = data.current_step || 'Working…';
            const pct = data.progress || 0;
            if (data.status === 'running') {
                if (btn) btn.textContent = step + ' (' + pct + '%)';
                if (status) status.innerHTML = '<span style="color:#7c3aed;">⟳ ' + step + ' — ' + pct + '%</span>';
                setTimeout(tick, 3000);
                return;
            }
            if (data.status === 'done') {
                const s = data.summary || {};
                if (status) status.innerHTML = '<span style="color:#065f46;">✓ Plan generated — ' + (s.clusters || 0) + ' clusters, ' + (s.items_saved || 0) + ' items. Forecast: ' + (s.forecast_low || 0) + ' – ' + (s.forecast_high || 0) + ' clicks/mo. Refreshing…</span>';
                setTimeout(() => location.reload(), 1500);
                return;
            }
            if (data.status === 'failed') {
                if (btn) { btn.disabled = false; btn.textContent = '📋 Generate Content Plan'; }
                if (status) status.innerHTML = '<span style="color:#dc2626;">✗ ' + (data.error || 'Job failed') + '</span>';
                return;
            }
            setTimeout(tick, 5000);
        } catch (e) {
            if (status) status.innerHTML = '<span style="color:#dc2626;">Polling error: ' + e.message + ' — retrying…</span>';
            setTimeout(tick, 5000);
        }
    };
    tick();
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
