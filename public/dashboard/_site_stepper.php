<?php
/**
 * Site workflow stepper — persistent top-of-page nav for the per-site context.
 *
 * Include this on every per-site page (site.php overview, seo.php, keywords.php,
 * posts.php, write.php, performance.php, sites.php edit, etc.) so the user
 * always sees where they are in the workflow.
 *
 * The including page must set:
 *   $site_id           int    — the site we're scoped to
 *   $site              array  — the sites row (provides scanned_at, agent_mode, etc.)
 *   $stepper_active    string — which step is the current page: scan|seo|keywords|write|publish|track|null
 *   $db                PDO    — connection for the per-step metrics
 *
 * Optional:
 *   $stepper_topbar    bool — when true, also renders the site name + Edit + Reports
 *                             topbar above the stepper (used on the overview landing).
 */
if (!isset($site_id, $site, $db) || !$site_id) return;
$stepper_active = $stepper_active ?? null;

// ── Per-step health metrics ─────────────────────────────
$stmt = $db->prepare('SELECT score FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 1');
$stmt->execute([$site_id]);
$_stp_audit = $stmt->fetch();

$stmt = $db->prepare('SELECT COUNT(*) FROM keywords WHERE site_id = ?');
$stmt->execute([$site_id]);
$_stp_kw = (int)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT status, COUNT(*) AS cnt FROM posts WHERE site_id = ? GROUP BY status');
$stmt->execute([$site_id]);
$_stp_posts = ['published' => 0, 'draft' => 0]; foreach ($stmt->fetchAll() as $r) $_stp_posts[$r['status']] = (int)$r['cnt'];

// Content plan health (drives the Publish step's metric and href)
$_stp_plan_total = 0;
$_stp_plan_has_active = false;
try {
    $stmt = $db->prepare("SELECT total_items_scheduled, total_items_published FROM content_plans WHERE site_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$site_id]);
    $_stp_plan = $stmt->fetch();
    if ($_stp_plan) {
        $_stp_plan_has_active = true;
        $_stp_plan_total = (int)$_stp_plan['total_items_scheduled'];
    }
} catch (PDOException $e) {}

$stmt = $db->prepare('SELECT 1 FROM integrations WHERE site_id = ? AND platform = "google_search_console" AND is_active = 1');
$stmt->execute([$site_id]);
$_stp_gsc = (bool)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT MAX(gsc_synced_at) FROM keywords WHERE site_id = ?');
$stmt->execute([$site_id]);
$_stp_gsc_sync = $stmt->fetchColumn();

// ── Step definitions ────────────────────────────────────
$_stp_has_scan    = !empty($site['scanned_at']);
$_stp_has_audit   = !empty($_stp_audit);
$_stp_has_kw      = $_stp_kw > 0;
$_stp_has_publish = $_stp_posts['published'] > 0;
$_stp_has_track   = $_stp_gsc && $_stp_gsc_sync;

// "Grow" step = engagement with any of the advanced/intel features
// (competitors, brand presence, AEO tracking, AI discoverability).
$_stp_grow_competitors = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM competitors WHERE site_id = ? AND status = 'active'");
    $stmt->execute([$site_id]);
    $_stp_grow_competitors = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}
$_stp_grow_aeo = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM aeo_queries WHERE site_id = ?");
    $stmt->execute([$site_id]);
    $_stp_grow_aeo = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}
$_stp_has_grow = $_stp_grow_competitors > 0 || $_stp_grow_aeo > 0;

// Publish step. With Content Plan v1, the natural destination is /dashboard/plan.php
// (the plan view), where the user generates + reviews the content pipeline. The
// metric prefers plan-aware language when a plan exists, falls back to raw post
// counts when one doesn't.
if ($_stp_plan_has_active) {
    $_stp_publish_metric = $_stp_plan_total . ' planned'
        . ($_stp_posts['draft'] > 0 ? ' · ' . $_stp_posts['draft'] . ' draft' : '')
        . ($_stp_posts['published'] > 0 ? ' · ' . $_stp_posts['published'] . ' live' : '');
} else {
    $_stp_publish_metric = $_stp_has_publish
        ? ($_stp_posts['published'] . ' live' . ($_stp_posts['draft'] > 0 ? ' · ' . $_stp_posts['draft'] . ' draft' : ''))
        : ($_stp_posts['draft'] > 0 ? $_stp_posts['draft'] . ' draft' . ($_stp_posts['draft'] === 1 ? '' : 's') : 'no plan yet');
}

$_stp_steps = [
    'scan' => [
        'label'  => 'Setup',
        'metric' => $_stp_has_scan ? ($site['platform'] ?: 'custom') : 'pending',
        'done'   => $_stp_has_scan,
        'href'   => url('/dashboard/setup.php?site=' . $site_id),
    ],
    'seo' => [
        'label'  => 'SEO/AEO',
        'metric' => $_stp_has_audit ? ((int)$_stp_audit['score'] . '/100') : 'not audited',
        'done'   => $_stp_has_audit,
        'href'   => url('/dashboard/seo.php?site=' . $site_id),
    ],
    'keywords' => [
        'label'  => 'Keywords',
        'metric' => $_stp_has_kw ? ($_stp_kw . ' tracked') : '0',
        'done'   => $_stp_has_kw,
        'href'   => url('/dashboard/keywords.php?site=' . $site_id),
    ],
    'publish' => [
        'label'  => 'Publish',
        'metric' => $_stp_publish_metric,
        'done'   => $_stp_has_publish || $_stp_plan_has_active,
        'href'   => url('/dashboard/plan.php?site=' . $site_id),
    ],
    'track' => [
        'label'  => 'Track',
        'metric' => $_stp_has_track ? 'GSC OK' : ($_stp_gsc ? 'syncing' : 'GSC needed'),
        'done'   => $_stp_has_track,
        'href'   => url('/dashboard/performance.php?site=' . $site_id),
    ],
    'grow' => [
        'label'  => 'Grow',
        'metric' => $_stp_has_grow
            ? ($_stp_grow_competitors . ' competitors' . ($_stp_grow_aeo > 0 ? ' · ' . $_stp_grow_aeo . ' AEO' : ''))
            : 'intel, AEO, brand',
        'done'   => $_stp_has_grow,
        'href'   => url('/dashboard/grow.php?site=' . $site_id),
    ],
];

// First incomplete step = the current next-action.
$_stp_current_key = null;
foreach ($_stp_steps as $k => $s) {
    if (!$s['done']) { $_stp_current_key = $k; break; }
}

$_stp_topbar = !empty($stepper_topbar);
?>

<style>
.stp-wrap { background:#fff; border:1px solid var(--border); border-radius:8px; margin-bottom:14px; }
.stp-topbar { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; border-bottom:1px solid var(--border); }
.stp-topbar h1 { margin:0; font-size:17px; font-weight:600; color:var(--primary); }
.stp-topbar .sub { font-size:12px; color:#64748b; margin-top:2px; }
.stp-topbar .actions { display:flex; gap:6px; align-items:center; }
.stp-btn { padding:6px 12px; border:1px solid var(--border); background:#fff; color:var(--text); border-radius:6px; font-size:12px; text-decoration:none; display:inline-block; }
.stp-btn:hover { background:#f8fafb; }
.stp-btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); }

.stp-row { display:flex; align-items:stretch; padding:10px 8px; gap:0; flex-wrap:nowrap; overflow-x:auto; }
.stp-step { flex:1; min-width:110px; display:flex; flex-direction:column; align-items:center; gap:4px; padding:8px 6px; border-radius:6px; text-decoration:none; color:#64748b; position:relative; transition:background 0.1s; }
.stp-step:hover { background:#f8fafb; }
.stp-step.active { background:#eef2f9; }
.stp-step .stp-dot { width:24px; height:24px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex-shrink:0; }
.stp-step.done    .stp-dot { background:#10b981; color:#fff; }
.stp-step.done    .stp-name { color:#065f46; font-weight:600; }
.stp-step.current .stp-dot { background:var(--accent); color:#fff; box-shadow:0 0 0 4px rgba(204,51,0,0.15); }
.stp-step.current .stp-name { color:var(--accent); font-weight:700; }
.stp-step.pending .stp-dot { background:#e2e8f0; color:#94a3b8; }
.stp-step.pending .stp-name { color:#94a3b8; }
.stp-step.active  .stp-name { text-decoration:underline; }
.stp-name { font-size:13px; }
.stp-metric { font-size:11px; color:#64748b; text-align:center; }
.stp-step.done .stp-metric { color:#065f46; }
.stp-arrow { display:flex; align-items:center; color:#cbd5e1; padding:0 2px; user-select:none; flex-shrink:0; }
@media (max-width: 640px) {
    .stp-step { min-width:90px; }
    .stp-name { font-size:12px; }
    .stp-metric { font-size:10px; }
}
</style>

<div class="stp-wrap">
    <?php if ($_stp_topbar): ?>
    <div class="stp-topbar">
        <div>
            <h1><?= e($site['name']) ?></h1>
            <div class="sub">
                <?= e($site['domain']) ?>
                <?php if (!$site['is_active']): ?><span style="color:#dc2626;margin-left:6px;font-weight:600;">· PAUSED</span><?php endif; ?>
                <?php if (($site['agent_mode'] ?? '') === 'auto'): ?><span style="color:#10b981;margin-left:6px;">· Auto mode</span><?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <a href="<?= url('/dashboard/setup.php?site=' . $site_id) ?>" class="stp-btn primary">⚙ Setup</a>
            <details style="position:relative;">
                <summary class="stp-btn" style="list-style:none;cursor:pointer;">⋯ Reports</summary>
                <div style="position:absolute;right:0;top:calc(100% + 4px);background:#fff;border:1px solid var(--border);border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,0.08);padding:4px;z-index:50;min-width:180px;">
                    <a href="<?= url('/dashboard/report.php?site=' . $site_id) ?>" style="display:block;padding:8px 12px;font-size:13px;color:var(--text);text-decoration:none;border-radius:4px;">Health Report</a>
                    <a href="<?= url('/dashboard/seo-audit.php?site=' . $site_id) ?>" style="display:block;padding:8px 12px;font-size:13px;color:var(--text);text-decoration:none;border-radius:4px;">Full SEO Audit</a>
                    <a href="<?= url('/api/export.php?site_id=' . $site_id . '&type=full') ?>" style="display:block;padding:8px 12px;font-size:13px;color:var(--text);text-decoration:none;border-radius:4px;">Export CSV</a>
                </div>
            </details>
        </div>
    </div>
    <?php endif; ?>

    <div class="stp-row">
        <?php $_stp_keys = array_keys($_stp_steps); $_stp_last = end($_stp_keys); ?>
        <?php foreach ($_stp_steps as $key => $step):
            $state = $step['done'] ? 'done' : ($key === $_stp_current_key ? 'current' : 'pending');
            $active_cls = $key === $stepper_active ? 'active' : '';
            $glyph = $step['done'] ? '✓' : ((string)(array_search($key, $_stp_keys) + 1));
        ?>
            <a href="<?= e($step['href']) ?>" class="stp-step <?= $state ?> <?= $active_cls ?>">
                <span class="stp-dot"><?= $glyph ?></span>
                <span class="stp-name"><?= e($step['label']) ?></span>
                <span class="stp-metric"><?= e($step['metric']) ?></span>
            </a>
            <?php if ($key !== $_stp_last): ?>
                <div class="stp-arrow">─</div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
