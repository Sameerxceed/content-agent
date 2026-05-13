<?php
/**
 * Site Command Center — Single page for everything.
 * This is THE page for a site. No other pages needed.
 *
 * Flow: Scan → Audit → Fix → Keywords → Content → Monitor
 * Each section shows status + action button + results when done.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['id'] ?? 0);

if (!$site_id) { redirect('/dashboard/sites.php'); }

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
$stmt->execute([$site_id, $user_id]);
$site = $stmt->fetch();

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

// Fixed issues
$stmt = $db->prepare('SELECT COUNT(*) FROM page_seo WHERE site_id = ?');
$stmt->execute([$site_id]);
$fixes_ready = $stmt->fetchColumn();

// Recent posts
$stmt = $db->prepare('SELECT id, title, slug, type, status, created_at FROM posts WHERE site_id = ? ORDER BY created_at DESC LIMIT 5');
$stmt->execute([$site_id]);
$recent_posts = $stmt->fetchAll();

// Top keywords
$stmt = $db->prepare('SELECT keyword, priority, difficulty FROM keywords WHERE site_id = ? ORDER BY priority DESC LIMIT 8');
$stmt->execute([$site_id]);
$top_kws = $stmt->fetchAll();

// Audit history for chart
$stmt = $db->prepare('SELECT score, total_issues, DATE_FORMAT(run_at, "%d %b") as label FROM seo_audits WHERE site_id = ? ORDER BY run_at ASC');
$stmt->execute([$site_id]);
$score_history = $stmt->fetchAll();

// Issues fixed vs open (all time)
$stmt = $db->prepare('SELECT COUNT(*) FROM seo_issues WHERE site_id = ? AND status IN ("fix_proposed","fix_applied","resolved","fixed_by_snippet")');
$stmt->execute([$site_id]);
$issues_fixed = $stmt->fetchColumn();

// This week stats
$stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE site_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)');
$stmt->execute([$site_id]);
$posts_this_week = $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM keywords WHERE site_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)');
$stmt->execute([$site_id]);
$kw_this_week = $stmt->fetchColumn();

// Score change since first audit
$first_score = !empty($score_history) ? $score_history[0]['score'] : null;
$latest_score = $audit ? $audit['score'] : null;
$score_change = ($first_score !== null && $latest_score !== null && count($score_history) > 1) ? $latest_score - $first_score : null;

// Determine what step user is on
$has_scan = !empty($site['scanned_at']);
$has_audit = !empty($audit);
$has_fixes = $fixes_ready > 0;
$has_keywords = $kw_count > 0;
$has_content = array_sum($pc) > 0;

$page_title = $site['name'];

ob_start();
?>

<style>
.section { background:#fff; border:1px solid var(--border); border-radius:8px; margin-bottom:10px; overflow:hidden; }
.section-header { padding:12px 16px; display:flex; align-items:center; justify-content:space-between; cursor:pointer; }
.section-header:hover { background:#f8fafb; }
.section-status { display:flex; align-items:center; gap:8px; }
.section-status .dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.section-status .dot.done { background:#10b981; }
.section-status .dot.pending { background:#f59e0b; }
.section-status .dot.not-done { background:#e2e8f0; }
.section-title { font-weight:600; font-size:14px; }
.section-subtitle { font-size:12px; color:#94a3b8; }
.section-body { padding:0 16px 14px; display:none; }
.section.open .section-body { display:block; }
.section-action { padding:8px 16px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; color:#fff; text-decoration:none; display:inline-block; }

.next-action { background:var(--accent); color:#fff; border:none; border-radius:8px; padding:12px 24px; font-size:14px; font-weight:600; cursor:pointer; display:block; width:100%; text-align:center; margin-bottom:10px; }
.next-action:hover { background:#a82a00; }

.mini-log { font-family:monospace; font-size:11px; background:#0f172a; color:#94a3b8; border-radius:6px; padding:12px; max-height:200px; overflow-y:auto; margin-top:10px; display:none; }
.mini-log .s { color:#10b981; }
.mini-log .i { color:#3b82f6; }
.mini-log .w { color:#f59e0b; }

.kw-list { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.kw-list span { background:#f1f5f9; padding:3px 10px; border-radius:12px; font-size:12px; color:#475569; }
.edit-link { font-size:12px; color:var(--primary); text-decoration:none; }
.edit-link:hover { text-decoration:underline; }
.action-bar { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
</style>

<?php
$sc = $audit ? $audit['score'] : -1;
$sc_cls = $sc < 0 ? '' : ($sc >= 80 ? 'score-good' : ($sc >= 50 ? 'score-ok' : 'score-bad'));
?>

<!-- Site Header -->
<div class="card" style="margin-bottom:2px; border-bottom:3px solid var(--primary); border-radius:var(--radius) var(--radius) 0 0;">
    <div class="flex justify-between items-center">
        <div class="flex items-center gap-4">
            <?php if ($sc >= 0): ?>
                <span class="score-circle <?= $sc_cls ?>" style="width:48px;height:48px;font-size:18px;"><?= $sc ?></span>
            <?php else: ?>
                <span class="score-circle" style="width:48px;height:48px;font-size:12px;background:#f1f5f9;color:#94a3b8;">N/A</span>
            <?php endif; ?>
            <div>
                <div style="font-size:16px;font-weight:600;color:var(--primary);"><?= e($site['name']) ?></div>
                <div class="text-sm text-muted">
                    <?= e($site['domain']) ?>
                    <?php if ($site['platform']): ?><span class="badge badge-info" style="margin-left:4px;"><?= e($site['platform']) ?></span><?php endif; ?>
                    <span class="badge badge-<?= $site['is_active'] ? 'approved' : 'rejected' ?>"><?= $site['is_active'] ? 'Active' : 'Off' ?></span>
                    <span class="badge badge-<?= $site['agent_mode'] === 'auto' ? 'approved' : 'draft' ?>"><?= $site['agent_mode'] ?> mode</span>
                </div>
            </div>
        </div>
        <div class="flex gap-2">
            <a href="<?= url('/dashboard/report.php?site=' . $site_id) ?>" class="btn btn-sm" style="background:var(--primary);color:#fff;text-decoration:none;">Health Report</a>
            <a href="<?= url('/api/export.php?site_id=' . $site_id . '&type=full') ?>" class="btn btn-outline btn-sm">Export CSV</a>
            <a href="<?= url('/dashboard/sites.php?action=edit&id=' . $site_id) ?>" class="btn btn-outline btn-sm">Edit</a>
            <a href="<?= url('/dashboard/seo-audit.php?site=' . $site_id) ?>" class="btn btn-outline btn-sm">Full Audit</a>
        </div>
    </div>
</div>

<!-- Agent Actions -->
<div class="card" style="margin-bottom:2px; padding:10px 14px;">
    <div class="action-bar">
        <a href="<?= url('/dashboard/agent-run.php?agent=scanner&site=' . $site_id) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">Scan Site</a>
        <a href="<?= url('/dashboard/agent-run.php?agent=seo-auditor&site=' . $site_id) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">SEO Audit</a>
        <a href="<?= url('/dashboard/agent-run.php?agent=auto-fixer&site=' . $site_id) ?>" class="btn btn-sm" style="background:#ef4444;color:#fff;text-decoration:none;">Auto-Fix Issues</a>
        <a href="<?= url('/dashboard/agent-run.php?agent=keyword-research&site=' . $site_id) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">Find Keywords</a>
        <a href="<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>" class="btn btn-accent btn-sm" style="text-decoration:none;">Content Planner</a>
        <a href="<?= url('/dashboard/agent-run.php?agent=news-scraper&site=' . $site_id) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">Scrape News</a>
        <a href="<?= url('/dashboard/agent-run.php?agent=evaluator&site=' . $site_id) ?>" class="btn btn-outline btn-sm" style="text-decoration:none;">Evaluate Strategy</a>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom:2px;">
    <a href="<?= url('/dashboard/posts.php?site=' . $site_id . '&status=published') ?>" class="stat-card" style="text-decoration:none;color:inherit;">
        <div class="stat-label">Published</div><div class="stat-value"><?= $pc['published'] ?? 0 ?></div>
    </a>
    <a href="<?= url('/dashboard/posts.php?site=' . $site_id . '&status=draft') ?>" class="stat-card" style="text-decoration:none;color:inherit;">
        <div class="stat-label">Drafts</div><div class="stat-value"><?= $pc['draft'] ?? 0 ?></div>
    </a>
    <a href="<?= url('/dashboard/keywords.php?site=' . $site_id) ?>" class="stat-card" style="text-decoration:none;color:inherit;">
        <div class="stat-label">Keywords</div><div class="stat-value"><?= $kw_count ?></div>
    </a>
    <a href="<?= url('/dashboard/seo-audit.php?site=' . $site_id) ?>" class="stat-card" style="text-decoration:none;color:inherit;">
        <div class="stat-label">SEO Issues</div><div class="stat-value" style="color:<?= $open_issues > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= $open_issues ?></div>
    </a>
    <a href="<?= url('/dashboard/posts.php?site=' . $site_id) ?>" class="stat-card" style="text-decoration:none;color:inherit;">
        <div class="stat-label">Total Posts</div><div class="stat-value"><?= array_sum($pc) ?></div>
    </a>
</div>

<!-- Progress Tracker -->
<?php if (!empty($score_history)): ?>
<div class="card" style="margin-bottom:10px;">
    <div class="card-header flex justify-between items-center" style="padding:10px 14px;">
        <span style="font-weight:600;font-size:13px;">Progress Tracker</span>
        <?php if ($score_change !== null): ?>
            <span class="text-sm">Since first audit: <span style="color:<?= $score_change >= 0 ? 'var(--success)' : 'var(--danger)' ?>;font-weight:600;"><?= $score_change >= 0 ? '+' : '' ?><?= $score_change ?> points</span></span>
        <?php endif; ?>
    </div>
    <!-- SEO Score Chart -->
    <div style="padding:0 14px 6px;">
        <div class="text-sm text-muted mb-2" style="font-weight:600;font-size:12px;">SEO Score Over Time</div>
        <div style="display:flex;align-items:flex-end;gap:4px;height:70px;">
            <?php foreach ($score_history as $h):
                $bh = max(8, ($h['score'] / 100) * 60);
                $bc = $h['score'] >= 80 ? '#10b981' : ($h['score'] >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;">
                <span style="font-size:10px;font-weight:700;color:<?= $bc ?>;"><?= $h['score'] ?></span>
                <div style="width:100%;max-width:45px;height:<?= $bh ?>px;background:<?= $bc ?>;border-radius:3px 3px 0 0;"></div>
                <span style="font-size:8px;color:#94a3b8;"><?= $h['label'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <!-- Issues Progress Bar -->
    <?php
    $total_tracked = $issues_fixed + $open_issues;
    $fix_pct = $total_tracked > 0 ? round(($issues_fixed / $total_tracked) * 100) : 0;
    ?>
    <div style="padding:6px 14px 10px;">
        <div class="text-sm text-muted mb-2" style="font-weight:600;font-size:12px;">Issues: <?= $issues_fixed ?> fixed, <?= $open_issues ?> open</div>
        <div style="height:8px;background:#fecaca;border-radius:4px;overflow:hidden;">
            <div style="height:100%;width:<?= $fix_pct ?>%;background:#10b981;border-radius:4px;"></div>
        </div>
        <div class="flex justify-between mt-2" style="font-size:10px;color:#94a3b8;">
            <span><?= $fix_pct ?>% resolved</span>
            <span><?= $total_tracked ?> total tracked</span>
        </div>
    </div>
    <!-- Weekly Summary -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;padding:8px 14px;border-top:1px solid var(--border);">
        <div style="text-align:center;"><div style="font-size:18px;font-weight:700;color:var(--primary);"><?= $posts_this_week ?></div><div style="font-size:9px;color:#94a3b8;text-transform:uppercase;">Posts this week</div></div>
        <div style="text-align:center;"><div style="font-size:18px;font-weight:700;color:var(--primary);"><?= $kw_this_week ?></div><div style="font-size:9px;color:#94a3b8;text-transform:uppercase;">New keywords</div></div>
        <div style="text-align:center;"><div style="font-size:18px;font-weight:700;color:var(--success);"><?= $issues_fixed ?></div><div style="font-size:9px;color:#94a3b8;text-transform:uppercase;">Issues fixed</div></div>
        <div style="text-align:center;"><div style="font-size:18px;font-weight:700;color:<?= $sc >= 50 ? 'var(--success)' : ($sc >= 0 ? 'var(--danger)' : '#94a3b8') ?>;"><?= $sc >= 0 ? $sc . '%' : '--' ?></div><div style="font-size:9px;color:#94a3b8;text-transform:uppercase;">SEO Health</div></div>
    </div>
</div>
<?php endif; ?>

<!-- What to do next -->
<?php
$next_step = 'scan';
$next_label = 'Start: Scan Your Website';
if ($has_scan && !$has_audit) { $next_step = 'audit'; $next_label = 'Next: Run SEO Audit'; }
elseif ($has_audit && $open_issues > 0) { $next_step = 'fix'; $next_label = "Next: Auto-Fix {$open_issues} Issues"; }
elseif ($has_audit && !$has_keywords) { $next_step = 'keywords'; $next_label = 'Next: Find Keywords'; }
elseif ($has_keywords && !$has_content) { $next_step = 'content'; $next_label = 'Next: Write Your First Blog Post'; }
else { $next_step = 'done'; $next_label = ''; }

if ($next_step !== 'done'):
?>
<button class="next-action" onclick="runStep('<?= $next_step ?>')"><?= $next_label ?></button>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════ -->
<!-- SECTIONS (click to expand) -->
<!-- ═══════════════════════════════════════════════ -->

<!-- 1. Scan -->
<div class="section <?= !$has_scan ? 'open' : '' ?>" id="sec-scan">
    <div class="section-header" onclick="toggleSection('scan')">
        <div class="section-status">
            <div class="dot <?= $has_scan ? 'done' : 'not-done' ?>"></div>
            <div>
                <div class="section-title">🔍 Website Scan</div>
                <div class="section-subtitle"><?= $has_scan ? 'Scanned ' . format_date($site['scanned_at'], 'd M Y') . ' — ' . ($site['platform'] ?: 'custom') : 'Not scanned yet' ?></div>
            </div>
        </div>
        <?php if ($has_scan): ?>
            <button class="section-action" style="background:var(--primary);" onclick="event.stopPropagation();runStep('scan')">Re-scan</button>
        <?php endif; ?>
    </div>
    <div class="section-body">
        <?php if ($has_scan): ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;">
                <div><span class="text-muted">Platform:</span> <?= e($site['platform'] ?: 'Unknown') ?></div>
                <div><span class="text-muted">Tone:</span> <?= e($site['brand_tone'] ?: 'Not analyzed') ?></div>
                <div><span class="text-muted">Blog:</span> <?= e($site['blog_path'] ?: 'None') ?></div>
                <div><span class="text-muted">Colors:</span>
                    <?php foreach (json_decode($site['brand_colors'] ?? '[]', true) ?: [] as $c): ?>
                        <span style="display:inline-block;width:16px;height:16px;background:<?= e($c) ?>;border-radius:3px;vertical-align:middle;border:1px solid #ddd;margin-right:2px;"></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <p class="text-sm text-muted">Click the button above to scan your website. We'll detect the platform, brand, content structure, and more.</p>
        <?php endif; ?>
        <div class="mini-log" id="log-scan"></div>
    </div>
</div>

<!-- 2. SEO Audit -->
<div class="section <?= $has_audit ? 'open' : '' ?>" id="sec-audit">
    <div class="section-header" onclick="toggleSection('audit')">
        <div class="section-status">
            <div class="dot <?= $has_audit ? 'done' : 'not-done' ?>"></div>
            <div>
                <div class="section-title">📊 SEO Audit</div>
                <div class="section-subtitle"><?= $has_audit ? "Score: {$audit['score']}/100 — {$audit['total_issues']} issues, {$audit['pages_crawled']} pages" : 'Not audited yet' ?></div>
            </div>
        </div>
        <?php if ($has_audit): ?>
            <a href="<?= url('/dashboard/seo-audit.php?audit=' . $audit['id']) ?>" class="edit-link" onclick="event.stopPropagation()">View details →</a>
        <?php endif; ?>
    </div>
    <div class="section-body">
        <?php if (!empty($score_history) && count($score_history) > 1): ?>
            <div style="display:flex;align-items:flex-end;gap:4px;height:60px;margin-bottom:10px;">
                <?php foreach ($score_history as $h):
                    $bh = max(8, ($h['score'] / 100) * 55);
                    $bc = $h['score'] >= 80 ? '#10b981' : ($h['score'] >= 50 ? '#f59e0b' : '#ef4444');
                ?>
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;">
                    <span style="font-size:10px;font-weight:700;color:<?= $bc ?>;"><?= $h['score'] ?></span>
                    <div style="width:100%;max-width:40px;height:<?= $bh ?>px;background:<?= $bc ?>;border-radius:3px 3px 0 0;"></div>
                    <span style="font-size:8px;color:#94a3b8;"><?= $h['label'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="mini-log" id="log-audit"></div>
    </div>
</div>

<!-- 3. Auto-Fix -->
<div class="section" id="sec-fix">
    <div class="section-header" onclick="toggleSection('fix')">
        <div class="section-status">
            <div class="dot <?= $fixes_ready > 0 ? 'done' : ($open_issues > 0 ? 'pending' : 'not-done') ?>"></div>
            <div>
                <div class="section-title">🤖 Auto-Fix</div>
                <div class="section-subtitle"><?= $fixes_ready > 0 ? "{$fixes_ready} fixes ready to deploy" : ($open_issues > 0 ? "{$open_issues} issues to fix" : 'No issues to fix') ?></div>
            </div>
        </div>
        <?php if ($open_issues > 0): ?>
            <button class="section-action" style="background:#ef4444;" onclick="event.stopPropagation();runStep('fix')">Fix All</button>
        <?php endif; ?>
    </div>
    <div class="section-body">
        <?php if ($fixes_ready > 0): ?>
            <div style="font-size:13px;margin-bottom:10px;">
                <strong><?= $fixes_ready ?></strong> SEO rules saved. To apply them to your live site, add this snippet:
            </div>
            <div style="background:#1a1a2e;color:#10b981;padding:10px 14px;border-radius:6px;font-family:monospace;font-size:11px;cursor:pointer;word-break:break-all;" onclick="navigator.clipboard.writeText(this.innerText.trim());alert('Copied!')">
                &lt;script src="<?= e(config('app_url')) ?>/snippet/contentagent.js" data-site="<?= e($site['domain']) ?>"&gt;&lt;/script&gt;
            </div>
            <div class="text-sm text-muted mt-2">Click to copy. Or add FTP credentials in <a href="<?= url('/dashboard/sites.php?action=edit&id=' . $site_id) ?>">Edit Settings</a> for direct deployment.</div>
        <?php endif; ?>
        <div class="mini-log" id="log-fix"></div>
        <div id="fix-progress" style="display:none;margin-top:10px;">
            <div style="height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;">
                <div id="fix-bar" style="height:100%;width:0%;background:#10b981;border-radius:3px;transition:width 0.3s;"></div>
            </div>
            <div class="text-sm text-muted mt-2" id="fix-counter"></div>
        </div>
    </div>
</div>

<!-- 3b. Fix Files — Download/Deploy -->
<?php if ($has_audit): ?>
<?php
    require_once __DIR__ . '/../../includes/fix-generator.php';
    $platform_info = fix_get_platform_info($site);
    $has_ftp = !empty($site['server_host']);
?>
<div class="section" id="sec-fixfiles">
    <div class="section-header" onclick="toggleSection('fixfiles')">
        <div class="section-status">
            <div class="dot <?= $fixes_ready > 0 ? 'done' : 'not-done' ?>"></div>
            <div>
                <div class="section-title">📦 Fix Files</div>
                <div class="section-subtitle">Download or deploy fix files for <?= e($site['platform'] ?: 'your site') ?></div>
            </div>
        </div>
        <a href="<?= url('/api/download-fix.php?site_id=' . $site_id . '&type=all') ?>" class="section-action" style="background:#3b82f6;text-decoration:none;" onclick="event.stopPropagation()">Download All (ZIP)</a>
    </div>
    <div class="section-body">
        <div style="font-size:13px;margin-bottom:6px;color:#6b7280;">Platform: <strong><?= e($site['platform'] ?: 'custom') ?></strong> | Theme: <strong><?= e($site['theme_name'] ?: 'default') ?></strong></div>
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid #e5e7eb;">
                    <th style="padding:6px 8px;">File</th>
                    <th style="padding:6px 8px;">Upload To</th>
                    <th style="padding:6px 8px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:6px 8px;">🔧 Header SEO Snippet</td>
                    <td style="padding:6px 8px;font-family:monospace;font-size:11px;color:#6b7280;"><?= e($platform_info['header_path']) ?></td>
                    <td style="padding:6px 8px;">
                        <a href="<?= url('/api/download-fix.php?site_id=' . $site_id . '&type=header') ?>" style="background:#3b82f6;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;text-decoration:none;">Download</a>
                        <?php if ($has_ftp): ?>
                            <button onclick="deployFix('header')" style="background:#10b981;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;border:none;cursor:pointer;margin-left:4px;">Deploy</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:6px 8px;">🗺️ sitemap.xml</td>
                    <td style="padding:6px 8px;font-family:monospace;font-size:11px;color:#6b7280;"><?= e($platform_info['sitemap_path']) ?></td>
                    <td style="padding:6px 8px;">
                        <a href="<?= url('/api/download-fix.php?site_id=' . $site_id . '&type=sitemap') ?>" style="background:#3b82f6;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;text-decoration:none;">Download</a>
                        <?php if ($has_ftp): ?>
                            <button onclick="deployFix('sitemap')" style="background:#10b981;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;border:none;cursor:pointer;margin-left:4px;">Deploy</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:6px 8px;">🤖 robots.txt</td>
                    <td style="padding:6px 8px;font-family:monospace;font-size:11px;color:#6b7280;"><?= e($platform_info['robots_path']) ?></td>
                    <td style="padding:6px 8px;">
                        <a href="<?= url('/api/download-fix.php?site_id=' . $site_id . '&type=robots') ?>" style="background:#3b82f6;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;text-decoration:none;">Download</a>
                        <?php if ($has_ftp): ?>
                            <button onclick="deployFix('robots')" style="background:#10b981;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;border:none;cursor:pointer;margin-left:4px;">Deploy</button>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php if (!$has_ftp): ?>
            <div class="text-sm text-muted mt-2">Add FTP/SFTP credentials in <a href="<?= url('/dashboard/sites.php?action=edit&id=' . $site_id) ?>">Edit Settings</a> to enable one-click deploy.</div>
        <?php endif; ?>
        <div id="deploy-result" style="display:none;margin-top:8px;padding:8px 12px;border-radius:6px;font-size:13px;"></div>
    </div>
</div>
<?php endif; ?>

<!-- 4. Keywords -->
<div class="section <?= $has_keywords ? 'open' : '' ?>" id="sec-keywords">
    <div class="section-header" onclick="toggleSection('keywords')">
        <div class="section-status">
            <div class="dot <?= $has_keywords ? 'done' : 'not-done' ?>"></div>
            <div>
                <div class="section-title">🔑 Keywords</div>
                <div class="section-subtitle"><?= $has_keywords ? "{$kw_count} keywords found" : 'No keywords yet' ?></div>
            </div>
        </div>
        <?php if ($has_keywords): ?>
            <a href="<?= url('/dashboard/keywords.php?site=' . $site_id) ?>" class="edit-link" onclick="event.stopPropagation()">View all →</a>
        <?php endif; ?>
    </div>
    <div class="section-body">
        <?php if (!empty($top_kws)): ?>
            <div class="kw-list">
                <?php foreach ($top_kws as $kw): ?>
                    <span><?= e($kw['keyword']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="mini-log" id="log-keywords"></div>
    </div>
</div>

<!-- 5. Content -->
<div class="section <?= $has_content ? 'open' : '' ?>" id="sec-content">
    <div class="section-header" onclick="toggleSection('content')">
        <div class="section-status">
            <div class="dot <?= $has_content ? 'done' : 'not-done' ?>"></div>
            <div>
                <div class="section-title">Content</div>
                <div class="section-subtitle"><?= $has_content ? (($pc['published'] ?? 0) . ' published, ' . ($pc['draft'] ?? 0) . ' drafts') : 'No content yet' ?></div>
            </div>
        </div>
        <div style="display:flex;gap:6px;" onclick="event.stopPropagation()">
            <a href="<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>" class="edit-link" style="color:var(--accent);">Write New →</a>
            <?php if ($has_content): ?>
                <a href="<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>" class="edit-link">View All →</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="section-body">
        <!-- Publishing settings -->
        <?php $cms_enabled = !empty($site['cms_url']) && !empty($site['cms_api_key']); ?>
        <div style="padding:10px;background:<?= $cms_enabled ? '#fef3c7' : '#f8fafc' ?>;border:1px solid <?= $cms_enabled ? '#fcd34d' : 'var(--border)' ?>;border-radius:6px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;gap:10px;">
            <div style="flex:1;min-width:0;">
                <div style="font-size:13px;font-weight:600;color:<?= $cms_enabled ? '#92400e' : '#475569' ?>;">
                    <?= $cms_enabled ? '⚠ Auto-publish to ' . e($site['domain']) . ' is ENABLED' : '✓ Auto-publish to live site is DISABLED' ?>
                </div>
                <div style="font-size:11px;color:#64748b;margin-top:2px;">
                    <?= $cms_enabled ? 'New posts can be pushed live via "Publish to CMS" option in the Writer.' : 'Posts will only save as drafts or publish locally on ContentAgent. Nothing goes live on ' . e($site['domain']) . '.' ?>
                </div>
            </div>
            <button onclick="togglePublish(<?= $site_id ?>, <?= $cms_enabled ? 'false' : 'true' ?>)" class="btn btn-sm" style="background:<?= $cms_enabled ? '#dc2626' : '#10b981' ?>;color:#fff;border:none;font-size:11px;white-space:nowrap;flex-shrink:0;">
                <?= $cms_enabled ? 'Disable CMS Push' : 'Enable CMS Push' ?>
            </button>
        </div>

        <?php if (!empty($recent_posts)): ?>
            <?php foreach ($recent_posts as $rp): ?>
            <div style="padding:6px 0;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
                <a href="<?= url('/dashboard/posts.php?action=edit&id=' . $rp['id'] . '&site=' . $site_id) ?>" style="font-size:13px;color:var(--text);text-decoration:none;"><?= e(truncate($rp['title'], 50)) ?></a>
                <div style="display:flex;gap:4px;align-items:center;">
                    <span class="badge badge-<?= $rp['status'] ?>" style="font-size:10px;"><?= $rp['status'] ?></span>
                    <span style="font-size:10px;color:#94a3b8;"><?= format_date($rp['created_at'], 'd M') ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-sm text-muted">Click "Write New" above to create your first blog post.</p>
        <?php endif; ?>
    </div>
</div>

<script>
async function togglePublish(siteId, enable) {
    const action = enable ? 'enable CMS auto-publish' : 'DISABLE CMS auto-publish';
    const msg = enable
        ? 'This will allow new posts to go LIVE on the website when "Publish to CMS" is selected. You will need to enter CMS credentials. Continue?'
        : 'This will clear CMS credentials. No new posts will go live on the website. Existing live posts are NOT affected. Continue?';
    if (!confirm(msg)) return;

    if (enable) {
        // Redirect to site edit page to enter CMS credentials
        window.location.href = '<?= url('/dashboard/sites.php?action=edit&id=') ?>' + siteId + '#cms';
        return;
    }

    try {
        const res = await fetch('<?= url('/api/toggle-publish.php') ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({site_id: siteId, enable: false})
        });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert('Failed: ' + (data.error || 'unknown error'));
        }
    } catch(e) {
        alert('Error: ' + e.message);
    }
}
</script>

<!-- 6. AI SEO -->
<div class="section" id="sec-aiseo">
    <div class="section-header" onclick="toggleSection('aiseo')">
        <div class="section-status">
            <div class="dot not-done"></div>
            <div>
                <div class="section-title">AI Discoverability</div>
                <div class="section-subtitle">llms.txt, AI crawlers, schema markup</div>
            </div>
        </div>
        <div style="display:flex;gap:6px;" onclick="event.stopPropagation()">
            <a href="<?= url('/dashboard/ai-seo.php?site=' . $site_id) ?>" class="edit-link">AI SEO →</a>
            <a href="<?= url('/dashboard/ai-visibility.php?site=' . $site_id) ?>" class="edit-link" style="color:var(--accent);">Visibility Check →</a>
        </div>
    </div>
</div>

<!-- 6b. AI Presence -->
<div class="section" id="sec-presence">
    <div class="section-header" onclick="toggleSection('presence')">
        <div class="section-status">
            <div class="dot not-done"></div>
            <div>
                <div class="section-title">AI Presence Builder</div>
                <div class="section-subtitle">Find conversations on Reddit, Quora, LinkedIn & more — join with AI-powered replies</div>
            </div>
        </div>
        <a href="<?= url('/dashboard/ai-presence.php?site=' . $site_id) ?>" class="edit-link" onclick="event.stopPropagation()" style="color:var(--accent);">Build Presence →</a>
    </div>
</div>

<!-- 7. Social Media -->
<div class="section" id="sec-social">
    <div class="section-header" onclick="toggleSection('social')">
        <div class="section-status">
            <div class="dot not-done"></div>
            <div>
                <div class="section-title">📱 Social Media</div>
                <div class="section-subtitle">Connect & auto-post to LinkedIn, Twitter, Instagram</div>
            </div>
        </div>
        <a href="<?= url('/dashboard/social.php?site=' . $site_id) ?>" class="edit-link" onclick="event.stopPropagation()">Connect →</a>
    </div>
</div>

<script>
const API = '<?= url('/api') ?>';
const siteId = <?= $site_id ?>;

function toggleSection(name) {
    document.getElementById('sec-' + name).classList.toggle('open');
}

async function deployFix(type) {
    const result = document.getElementById('deploy-result');
    result.style.display = 'block';
    result.style.background = '#eff6ff';
    result.style.color = '#1e40af';
    result.textContent = 'Deploying ' + type + ' via FTP...';
    try {
        const res = await fetch(API + '/deploy-fixes.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({site_id: siteId, type: type})
        });
        const data = await res.json();
        if (data.success) {
            result.style.background = '#ecfdf5';
            result.style.color = '#065f46';
            result.textContent = '✓ ' + type + ' deployed successfully to ' + (data.path || 'server');
        } else {
            result.style.background = '#fef2f2';
            result.style.color = '#991b1b';
            result.textContent = '✗ Deploy failed: ' + (data.error || 'Unknown error');
        }
    } catch (e) {
        result.style.background = '#fef2f2';
        result.style.color = '#991b1b';
        result.textContent = '✗ Deploy failed: ' + e.message;
    }
}

function log(id, text, cls) {
    const el = document.getElementById('log-' + id);
    el.style.display = 'block';
    const prefix = cls === 's' ? '✓ ' : cls === 'i' ? '→ ' : cls === 'w' ? '⚠ ' : '  ';
    el.innerHTML += '<div class="' + cls + '">' + prefix + text + '</div>';
    el.scrollTop = el.scrollHeight;
}

async function runStep(step) {
    // Open the section
    document.getElementById('sec-' + step).classList.add('open');

    if (step === 'scan') {
        log('scan', 'Scanning website...', 'i');
        const res = await fetch(API + '/onboarding.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'scan', site_id: siteId})
        });
        const data = await res.json();
        if (data.success) {
            log('scan', 'Platform: ' + (data.platform || 'custom'), 's');
            log('scan', 'Pages: ' + data.internal_links + ' | Images: ' + data.images, 'i');
            log('scan', 'SSL: ' + (data.ssl_valid ? 'Valid' : 'Invalid'), data.ssl_valid ? 's' : 'w');
            log('scan', 'Sitemap: ' + (data.sitemap ? 'Found' : 'Missing'), data.sitemap ? 's' : 'w');
            log('scan', 'Scan complete! Refresh to see results.', 's');
            setTimeout(() => location.reload(), 2000);
        } else {
            log('scan', 'Error: ' + (data.error || 'Unknown'), 'w');
        }
    }

    else if (step === 'audit') {
        log('audit', 'Running SEO audit (up to 30 pages)...', 'i');
        const res = await fetch(API + '/onboarding.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'audit', site_id: siteId})
        });
        const data = await res.json();
        if (data.success) {
            log('audit', 'Score: ' + data.score + '/100 | Issues: ' + data.issues + ' | Pages: ' + data.pages, 's');
            setTimeout(() => location.reload(), 2000);
        } else {
            log('audit', 'Error: ' + (data.error || 'Unknown'), 'w');
        }
    }

    else if (step === 'fix') {
        document.getElementById('fix-progress').style.display = 'block';
        log('fix', 'Starting auto-fixer...', 'i');

        let offset = 0, totalFixed = 0, totalSkipped = 0, totalIssues = 0, hasMore = true;
        while (hasMore) {
            const res = await fetch(API + '/auto-fix-all.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({site_id: siteId, batch_size: 10, offset: offset})
            });
            const data = await res.json();
            if (!data.success) { log('fix', data.error || 'Error', 'w'); break; }
            totalFixed += data.fixed; totalSkipped += data.skipped;
            totalIssues = data.total_issues; hasMore = data.has_more;
            offset = data.next_offset || offset + 10;
            const pct = totalIssues > 0 ? Math.round((Math.min(offset, totalIssues) / totalIssues) * 100) : 100;
            document.getElementById('fix-bar').style.width = pct + '%';
            document.getElementById('fix-counter').textContent = Math.min(offset, totalIssues) + ' / ' + totalIssues + ' processed';
            if (data.applied) data.applied.forEach(a => log('fix', a, 's'));
        }
        log('fix', totalFixed + ' fixes ready to deploy, ' + totalSkipped + ' skipped.', 's');
        setTimeout(() => location.reload(), 2000);
    }

    else if (step === 'keywords') {
        log('keywords', 'Researching keywords...', 'i');
        const res = await fetch(API + '/onboarding.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'keywords', site_id: siteId})
        });
        const data = await res.json();
        if (data.success) {
            log('keywords', 'Found ' + data.total + ' keywords', 's');
            if (data.samples) data.samples.forEach(k => log('keywords', '  ' + k, 'i'));
            setTimeout(() => location.reload(), 2000);
        } else {
            log('keywords', data.error || 'Error', 'w');
        }
    }

    else if (step === 'content') {
        window.location.href = '<?= url('/dashboard/write.php?site=' . $site_id . '&step=propose') ?>';
    }
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
