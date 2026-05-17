<?php
/**
 * Site Command Center — at-a-glance overview for one site.
 *
 * The page is a scannable dashboard: every section the customer cares about
 * is one row with a status summary; click the row to open the detail page.
 * No accordions, no tabs, no banners — just rows.
 *
 * Top of page: hero (name + 2 buttons) → pipeline stepper → next-action CTA
 *              → one-line metrics → row list.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/integrations/google.php';
require_once __DIR__ . '/../../includes/integrations/linkedin.php';
require_once __DIR__ . '/../../includes/integrations/twitter.php';
require_once __DIR__ . '/../../includes/integrations/reddit.php';
require_once __DIR__ . '/../../includes/performance.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['id'] ?? 0);

if (!$site_id) { redirect('/dashboard/sites.php'); }

$site = auth_get_accessible_site($db, $site_id);

if (!$site) { redirect('/dashboard/sites.php'); }

// ── Gather all data ─────────────────────────────────────
// Latest audit
$stmt = $db->prepare('SELECT * FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 1');
$stmt->execute([$site_id]);
$audit = $stmt->fetch();

// Post counts
$stmt = $db->prepare('SELECT status, COUNT(*) as cnt FROM posts WHERE site_id = ? GROUP BY status');
$stmt->execute([$site_id]);
$pc = []; foreach ($stmt->fetchAll() as $r) $pc[$r['status']] = $r['cnt'];

// Keywords
$stmt = $db->prepare('SELECT COUNT(*) FROM keywords WHERE site_id = ?');
$stmt->execute([$site_id]);
$kw_count = $stmt->fetchColumn();

// Open issues (latest audit only)
$open_issues = 0;
if ($audit) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM seo_issues WHERE audit_id = ? AND status = "open"');
    $stmt->execute([$audit['id']]);
    $open_issues = $stmt->fetchColumn();
}

// Pending SEO approvals
$stmt = $db->prepare('SELECT COUNT(*) FROM page_seo WHERE site_id = ? AND status = "pending"');
$stmt->execute([$site_id]);
$pending_approvals = (int)$stmt->fetchColumn();

// GSC integration status
$stmt = $db->prepare('SELECT * FROM integrations WHERE site_id = ? AND platform = "google_search_console" AND is_active = 1');
$stmt->execute([$site_id]);
$gsc_integration = $stmt->fetch();

// Latest GSC sync time
$stmt = $db->prepare('SELECT MAX(gsc_synced_at) FROM keywords WHERE site_id = ?');
$stmt->execute([$site_id]);
$gsc_last_sync = $stmt->fetchColumn();

// Competitor counts
$competitors_active = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM competitors WHERE site_id = ? AND status = 'active'");
    $stmt->execute([$site_id]);
    $competitors_active = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    // table not yet migrated — leave at 0
}

// Content gap counts
$open_gaps = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM content_gaps WHERE site_id = ? AND status = 'open'");
    $stmt->execute([$site_id]);
    $open_gaps = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// Unread alerts
$unread_alerts = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM alerts WHERE site_id = ? AND read_at IS NULL");
    $stmt->execute([$site_id]);
    $unread_alerts = (int)$stmt->fetchColumn();
} catch (PDOException $e) {}

// page_seo: SEO improvements ContentAgent has generated for individual pages.
$stmt = $db->prepare("SELECT status, COUNT(*) AS cnt FROM page_seo WHERE site_id = ? GROUP BY status");
$stmt->execute([$site_id]);
$page_seo_by_status = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($stmt->fetchAll() as $r) $page_seo_by_status[$r['status']] = (int)$r['cnt'];
$improvements_pending  = $page_seo_by_status['pending'];
$improvements_approved = $page_seo_by_status['approved'];
$fixes_ready           = $improvements_pending + $improvements_approved;

// This week stats
$stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE site_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)');
$stmt->execute([$site_id]);
$posts_this_week = $stmt->fetchColumn();

// Step flags
$has_scan     = !empty($site['scanned_at']);
$has_audit    = !empty($audit);
$has_keywords = $kw_count > 0;
$has_content  = array_sum($pc) > 0;
$has_publish  = !empty($pc['published']);
$has_track    = !empty($gsc_integration) && !empty($gsc_last_sync);

// ── Performance buckets (for Performance row's status) ─────
$buckets = ['winners' => [], 'decay' => [], 'dead_air' => []];
try { $buckets = performance_buckets($db, $site_id); } catch (Throwable $e) {}

// ── Per-site OAuth connections (for Channels row) ──────────
$site_connections = [
    'google_search_console' => ['active' => false, 'account' => null],
    'linkedin'              => ['active' => false, 'account' => null],
    'twitter'               => ['active' => false, 'account' => null],
    'reddit'                => ['active' => false, 'account' => null],
];
$stmt = $db->prepare("SELECT platform, account_name FROM integrations
                      WHERE site_id = ? AND is_active = 1
                        AND platform IN ('google_search_console','linkedin','twitter','reddit')");
$stmt->execute([$site_id]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $site_connections[$row['platform']] = ['active' => true, 'account' => $row['account_name']];
}

$page_title = $site['name'];

// ── Pipeline definition ────────────────────────────────────
$pipeline = [
    'scan'     => ['label' => 'Scan',     'done' => $has_scan,    'href' => url('/dashboard/sites.php?action=edit&id=' . $site_id)],
    'seo'      => ['label' => 'SEO',      'done' => $has_audit,   'href' => url('/dashboard/seo.php?site=' . $site_id)],
    'keywords' => ['label' => 'Keywords', 'done' => $has_keywords,'href' => url('/dashboard/keywords.php?site=' . $site_id)],
    'write'    => ['label' => 'Write',    'done' => $has_content, 'href' => url('/dashboard/write.php?site=' . $site_id . '&step=propose')],
    'publish'  => ['label' => 'Publish',  'done' => $has_publish, 'href' => url('/dashboard/posts.php?site=' . $site_id)],
    'track'    => ['label' => 'Track',    'done' => $has_track,   'href' => url('/dashboard/performance.php?site=' . $site_id)],
];
$current_step_key = null;
foreach ($pipeline as $key => $step) {
    if (!$step['done']) { $current_step_key = $key; break; }
}

$next_action_labels = [
    'scan'     => 'Start: Scan your website',
    'seo'      => 'Next: Run SEO audit',
    'keywords' => 'Next: Find keywords',
    'write'    => 'Next: Write your first post',
    'publish'  => 'Next: Publish to your site',
    'track'    => 'Next: Connect Google Search Console',
];
$next_action_label = $current_step_key ? $next_action_labels[$current_step_key] : null;
$next_action_href  = $current_step_key ? $pipeline[$current_step_key]['href'] : null;

// Business focus state
$topics_arr       = json_decode($site['topics'] ?? '[]', true) ?: [];
$topics_confirmed = !empty($site['topics_confirmed']);

// Channels status
$cms_push_on = !empty($site['cms_url']) && !empty($site['cms_api_key']);
$socials_on  = (int)!!$site_connections['linkedin']['active']
             + (int)!!$site_connections['twitter']['active']
             + (int)!!$site_connections['reddit']['active'];

ob_start();
?>

<style>
.hero-bar { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; background:#fff; border:1px solid var(--border); border-radius:8px; margin-bottom:10px; }
.hero-bar h1 { margin:0; font-size:18px; font-weight:600; color:var(--primary); }
.hero-bar .sub { font-size:12px; color:#64748b; margin-top:2px; }
.hero-bar .actions { display:flex; gap:6px; align-items:center; }
.hero-btn { padding:6px 12px; border:1px solid var(--border); background:#fff; color:var(--text); border-radius:6px; font-size:12px; text-decoration:none; cursor:pointer; display:inline-block; }
.hero-btn:hover { background:#f8fafb; }
.hero-btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); }

.stepper { display:flex; align-items:center; background:#fff; border:1px solid var(--border); border-radius:8px; padding:14px 16px; margin-bottom:10px; gap:2px; flex-wrap:wrap; }
.step { display:flex; align-items:center; gap:8px; padding:6px 10px; border-radius:6px; text-decoration:none; color:#64748b; font-size:13px; }
.step:hover { background:#f8fafb; }
.step .step-dot { width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; }
.step.done    .step-dot { background:#10b981; color:#fff; }
.step.done    { color:#065f46; }
.step.current .step-dot { background:var(--accent); color:#fff; box-shadow:0 0 0 4px rgba(204,51,0,0.15); }
.step.current { color:var(--accent); font-weight:600; }
.step.pending .step-dot { background:#e2e8f0; color:#94a3b8; }
.step .arrow { color:#cbd5e1; margin:0 4px; user-select:none; }

.cta-next { display:block; background:var(--accent); color:#fff; padding:14px 20px; border-radius:8px; text-align:center; font-size:14px; font-weight:600; text-decoration:none; margin-bottom:10px; }
.cta-next:hover { background:#a82a00; color:#fff; }

.metrics-strip { display:flex; gap:14px; justify-content:center; padding:10px 16px; background:#f8fafb; border:1px solid var(--border); border-radius:8px; margin-bottom:14px; font-size:13px; color:#475569; flex-wrap:wrap; }
.metrics-strip strong { color:var(--primary); font-weight:600; }
.metrics-strip .sep { color:#cbd5e1; }

.row-card { display:flex; align-items:center; gap:14px; padding:14px 16px; background:#fff; border:1px solid var(--border); border-radius:8px; margin-bottom:8px; text-decoration:none; color:inherit; transition:background 0.1s, border-color 0.1s; }
.row-card:hover { background:#f8fafb; border-color:#cbd5e1; }
.row-card .row-icon { width:36px; height:36px; border-radius:8px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.row-card .row-main { flex:1; min-width:0; }
.row-card .row-title { font-size:14px; font-weight:600; color:var(--primary); }
.row-card .row-sub { font-size:12px; color:#64748b; margin-top:2px; }
.row-card .row-status { font-size:11px; padding:3px 9px; border-radius:10px; flex-shrink:0; font-weight:500; }
.row-card .row-status.good    { background:#dcfce7; color:#166534; }
.row-card .row-status.warn    { background:#fef3c7; color:#92400e; }
.row-card .row-status.bad     { background:#fee2e2; color:#991b1b; }
.row-card .row-status.neutral { background:#f1f5f9; color:#475569; }
.row-card .row-arrow { color:#cbd5e1; font-size:18px; flex-shrink:0; }

.report-menu { position:relative; }
.report-menu summary { list-style:none; cursor:pointer; }
.report-menu summary::-webkit-details-marker { display:none; }
.report-menu .menu-items { position:absolute; right:0; top:calc(100% + 4px); background:#fff; border:1px solid var(--border); border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,0.08); padding:4px; z-index:50; min-width:180px; }
.report-menu .menu-items a { display:block; padding:8px 12px; font-size:13px; color:var(--text); text-decoration:none; border-radius:4px; }
.report-menu .menu-items a:hover { background:#f1f5f9; }

.focus-warn { padding:12px 16px; background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; margin-bottom:10px; }
.focus-warn .title { font-size:13px; font-weight:600; color:#92400e; }
.focus-warn .desc  { font-size:12px; color:#92400e; margin-top:3px; }
</style>

<!-- ── Hero strip ───────────────────────────────────────── -->
<div class="hero-bar">
    <div>
        <h1><?= e($site['name']) ?></h1>
        <div class="sub">
            <?= e($site['domain']) ?>
            <?php if (!$site['is_active']): ?>
                <span style="color:#dc2626;margin-left:6px;font-weight:600;">· PAUSED</span>
            <?php endif; ?>
            <?php if (($site['agent_mode'] ?? '') === 'auto'): ?>
                <span style="color:#10b981;margin-left:6px;">· Auto mode</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="actions">
        <a href="<?= url('/dashboard/sites.php?action=edit&id=' . $site_id) ?>" class="hero-btn primary">⚙ Edit</a>
        <details class="report-menu">
            <summary class="hero-btn">⋯ Reports</summary>
            <div class="menu-items">
                <a href="<?= url('/dashboard/report.php?site=' . $site_id) ?>">Health Report</a>
                <a href="<?= url('/dashboard/seo-audit.php?site=' . $site_id) ?>">Full SEO Audit</a>
                <a href="<?= url('/api/export.php?site_id=' . $site_id . '&type=full') ?>">Export CSV</a>
            </div>
        </details>
    </div>
</div>

<!-- ── Pipeline stepper ─────────────────────────────────── -->
<div class="stepper">
    <?php $step_keys = array_keys($pipeline); $last_key = end($step_keys); ?>
    <?php foreach ($pipeline as $key => $step):
        $state = $step['done'] ? 'done' : ($key === $current_step_key ? 'current' : 'pending');
        $glyph = $step['done'] ? '✓' : ($key === $current_step_key ? '●' : ((string)(array_search($key, $step_keys) + 1)));
    ?>
        <a href="<?= e($step['href']) ?>" class="step <?= $state ?>">
            <span class="step-dot"><?= $glyph ?></span>
            <span><?= e($step['label']) ?></span>
        </a>
        <?php if ($key !== $last_key): ?>
            <span class="arrow">──</span>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<!-- ── Next action CTA ──────────────────────────────────── -->
<?php if ($next_action_label): ?>
    <a href="<?= e($next_action_href) ?>" class="cta-next"><?= e($next_action_label) ?> →</a>
<?php endif; ?>

<!-- ── Business Focus warning (only when not confirmed) ─── -->
<?php if (!$topics_confirmed): ?>
    <div class="focus-warn">
        <div class="title">⚠ Tell ContentAgent what your business sells</div>
        <div class="desc">AI keyword research and content writing depend on this. <a href="<?= url('/dashboard/sites.php?action=edit&id=' . $site_id . '#focus') ?>" style="color:#92400e;font-weight:600;">Set business focus →</a></div>
    </div>
<?php endif; ?>

<!-- ── One-line metrics strip ───────────────────────────── -->
<div class="metrics-strip">
    <span><strong><?= $posts_this_week ?></strong> post<?= $posts_this_week === 1 ? '' : 's' ?> this week</span>
    <span class="sep">·</span>
    <span>SEO <strong><?= $audit ? (int)$audit['score'] . '/100' : '—' ?></strong></span>
    <span class="sep">·</span>
    <span><strong><?= $kw_count ?></strong> keywords</span>
    <span class="sep">·</span>
    <span><strong><?= $unread_alerts ?></strong> alert<?= $unread_alerts === 1 ? '' : 's' ?></span>
</div>

<!-- ── Dashboard rows ───────────────────────────────────── -->

<!-- Site Identity -->
<?php
$identity_bits = [];
$identity_bits[] = $site['platform'] ? e($site['platform']) : 'custom platform';
$identity_bits[] = $has_scan ? 'scanned ' . format_date($site['scanned_at'], 'd M Y') : 'not scanned';
if ($topics_confirmed && !empty($topics_arr)) {
    $identity_bits[] = count($topics_arr) . ' topic' . (count($topics_arr) === 1 ? '' : 's');
}
$identity_status_cls = $topics_confirmed && $has_scan ? 'good' : 'warn';
$identity_status_lbl = $topics_confirmed && $has_scan ? 'Ready' : 'Setup needed';
?>
<a href="<?= url('/dashboard/sites.php?action=edit&id=' . $site_id) ?>" class="row-card">
    <div class="row-icon">🔍</div>
    <div class="row-main">
        <div class="row-title">Site Identity</div>
        <div class="row-sub"><?= e(implode(' · ', $identity_bits)) ?></div>
    </div>
    <span class="row-status <?= $identity_status_cls ?>"><?= $identity_status_lbl ?></span>
    <span class="row-arrow">›</span>
</a>

<!-- SEO -->
<?php
$seo_bits = [];
if ($audit) {
    $seo_bits[] = "Score {$audit['score']}/100";
    $seo_bits[] = "{$open_issues} open issue" . ($open_issues === 1 ? '' : 's');
    if ($fixes_ready > 0) $seo_bits[] = "{$fixes_ready} improvement" . ($fixes_ready === 1 ? '' : 's');
} else {
    $seo_bits[] = 'Not audited yet';
}
$seo_status_cls = !$audit ? 'neutral' : ($open_issues > 0 ? 'warn' : 'good');
$seo_status_lbl = !$audit ? 'Pending' : ($open_issues > 0 ? "{$open_issues} to fix" : ($audit['score'] . '/100'));
?>
<a href="<?= url('/dashboard/seo.php?site=' . $site_id) ?>" class="row-card">
    <div class="row-icon">📊</div>
    <div class="row-main">
        <div class="row-title">SEO</div>
        <div class="row-sub"><?= e(implode(' · ', $seo_bits)) ?></div>
    </div>
    <span class="row-status <?= $seo_status_cls ?>"><?= e($seo_status_lbl) ?></span>
    <span class="row-arrow">›</span>
</a>

<!-- Keywords -->
<a href="<?= url('/dashboard/keywords.php?site=' . $site_id) ?>" class="row-card">
    <div class="row-icon">🔑</div>
    <div class="row-main">
        <div class="row-title">Keywords</div>
        <div class="row-sub">
            <?php if ($kw_count > 0): ?>
                <?= $kw_count ?> keyword<?= $kw_count === 1 ? '' : 's' ?> tracked
                <?php if ($gsc_integration): ?>· GSC connected<?php endif; ?>
            <?php else: ?>
                No keywords yet — run keyword research
            <?php endif; ?>
        </div>
    </div>
    <span class="row-status <?= $kw_count > 0 ? 'good' : 'neutral' ?>"><?= $kw_count > 0 ? $kw_count . ' found' : 'Empty' ?></span>
    <span class="row-arrow">›</span>
</a>

<!-- Content -->
<?php
$content_bits = [];
$content_bits[] = ($pc['published'] ?? 0) . ' published';
if (($pc['draft'] ?? 0) > 0) $content_bits[] = ($pc['draft']) . ' draft' . ($pc['draft'] === 1 ? '' : 's');
$content_bits[] = $posts_this_week . ' this week';
?>
<a href="<?= url('/dashboard/posts.php?site=' . $site_id) ?>" class="row-card">
    <div class="row-icon">📝</div>
    <div class="row-main">
        <div class="row-title">Content</div>
        <div class="row-sub"><?= e(implode(' · ', $content_bits)) ?></div>
    </div>
    <span class="row-status <?= ($pc['published'] ?? 0) > 0 ? 'good' : 'neutral' ?>"><?= ($pc['published'] ?? 0) > 0 ? 'Live' : 'Empty' ?></span>
    <span class="row-arrow">›</span>
</a>

<!-- Channels -->
<?php
$channels_bits = [];
$channels_bits[] = 'CMS push ' . ($cms_push_on ? 'ON' : 'OFF');
$channels_bits[] = $socials_on . '/3 socials connected';
$channels_cls = ($cms_push_on || $socials_on > 0) ? 'good' : 'warn';
$channels_lbl = ($cms_push_on || $socials_on > 0) ? 'Active' : 'Not set up';
?>
<a href="<?= url('/dashboard/integrations.php') ?>" class="row-card">
    <div class="row-icon">🚀</div>
    <div class="row-main">
        <div class="row-title">Channels</div>
        <div class="row-sub"><?= e(implode(' · ', $channels_bits)) ?></div>
    </div>
    <span class="row-status <?= $channels_cls ?>"><?= e($channels_lbl) ?></span>
    <span class="row-arrow">›</span>
</a>

<!-- AI Discoverability -->
<a href="<?= url('/dashboard/ai-seo.php?site=' . $site_id) ?>" class="row-card">
    <div class="row-icon">🤖</div>
    <div class="row-main">
        <div class="row-title">AI Discoverability</div>
        <div class="row-sub">llms.txt, AI crawlers, schema markup for ChatGPT / Perplexity</div>
    </div>
    <span class="row-arrow">›</span>
</a>

<!-- Brand Presence -->
<a href="<?= url('/dashboard/ai-presence.php?site=' . $site_id) ?>" class="row-card">
    <div class="row-icon">🏢</div>
    <div class="row-main">
        <div class="row-title">Brand Presence</div>
        <div class="row-sub">Find conversations on Reddit, Quora, LinkedIn — join with AI-powered replies</div>
    </div>
    <span class="row-arrow">›</span>
</a>

<!-- AEO Tracker (Perplexity citation tracking) -->
<a href="<?= url('/dashboard/aeo.php?site=' . $site_id) ?>" class="row-card">
    <div class="row-icon">🎯</div>
    <div class="row-main">
        <div class="row-title">AEO Tracker</div>
        <div class="row-sub">Track when Perplexity, ChatGPT and other answer engines cite your site</div>
    </div>
    <span class="row-arrow">›</span>
</a>

<!-- Competitors -->
<a href="<?= url('/dashboard/competitors.php?site=' . $site_id) ?>" class="row-card">
    <div class="row-icon">⚔️</div>
    <div class="row-main">
        <div class="row-title">Competitors</div>
        <div class="row-sub">
            <?php if ($competitors_active > 0): ?>
                <?= $competitors_active ?> tracked
                <?php if ($open_gaps > 0): ?> · <?= $open_gaps ?> content gap<?= $open_gaps === 1 ? '' : 's' ?> open<?php endif; ?>
            <?php else: ?>
                No competitors tracked yet
            <?php endif; ?>
        </div>
    </div>
    <span class="row-status <?= $competitors_active > 0 ? 'good' : 'neutral' ?>"><?= $competitors_active > 0 ? $competitors_active . ' active' : 'Empty' ?></span>
    <span class="row-arrow">›</span>
</a>

<!-- Performance -->
<?php
$perf_winners = count($buckets['winners']);
$perf_decay   = count($buckets['decay']);
$perf_cls = !$gsc_integration ? 'warn' : ($perf_decay > 0 ? 'warn' : 'good');
$perf_lbl = !$gsc_integration ? 'GSC needed' : ($perf_decay > 0 ? $perf_decay . ' slipping' : 'OK');
?>
<a href="<?= url('/dashboard/performance.php?site=' . $site_id) ?>" class="row-card">
    <div class="row-icon">📈</div>
    <div class="row-main">
        <div class="row-title">Performance</div>
        <div class="row-sub">
            <?php if (!$gsc_integration): ?>
                Connect Google Search Console to see clicks, impressions and rankings
            <?php else: ?>
                <?= $perf_winners ?> winner<?= $perf_winners === 1 ? '' : 's' ?> · <?= $perf_decay ?> slipping
            <?php endif; ?>
        </div>
    </div>
    <span class="row-status <?= $perf_cls ?>"><?= e($perf_lbl) ?></span>
    <span class="row-arrow">›</span>
</a>

<!-- Alerts -->
<a href="<?= url('/dashboard/alerts.php?site=' . $site_id) ?>" class="row-card">
    <div class="row-icon">🔔</div>
    <div class="row-main">
        <div class="row-title">Alerts</div>
        <div class="row-sub">
            <?php if ($unread_alerts > 0): ?>
                <?= $unread_alerts ?> unread notification<?= $unread_alerts === 1 ? '' : 's' ?>
            <?php else: ?>
                All clear — nothing needs your attention
            <?php endif; ?>
        </div>
    </div>
    <span class="row-status <?= $unread_alerts > 0 ? 'warn' : 'good' ?>"><?= $unread_alerts > 0 ? $unread_alerts . ' unread' : '✓' ?></span>
    <span class="row-arrow">›</span>
</a>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
