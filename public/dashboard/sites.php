<?php
/**
 * Dashboard — Sites management.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();
$action = $_GET['action'] ?? 'list';

// ── Handle form submissions ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash_error'] = 'Invalid form submission.';
        redirect('/dashboard/sites.php');
    }

    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'add') {
        $url = trim($_POST['url'] ?? '');
        $name = trim($_POST['name'] ?? '');

        if (empty($url)) {
            $_SESSION['flash_error'] = 'Please enter a website URL.';
            redirect('/dashboard/sites.php?action=add');
        }

        // Clean domain
        $parsed = parse_url($url);
        $domain = $parsed['host'] ?? $url;
        $domain = preg_replace('/^www\./', '', $domain);
        $name = $name ?: $domain;

        $stmt = $db->prepare('INSERT INTO sites (user_id, name, domain) VALUES (?, ?, ?)');
        $stmt->execute([$user_id, $name, $domain]);
        $new_id = $db->lastInsertId();

        $_SESSION['flash_success'] = "Site '{$name}' added!";
        redirect('/dashboard/site.php?id=' . $new_id);
    }

    if ($post_action === 'update') {
        $id = (int)$_POST['id'];

        // Verify ownership
        $stmt = $db->prepare('SELECT id FROM sites WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        if (!$stmt->fetch()) {
            $_SESSION['flash_error'] = 'Site not found.';
            redirect('/dashboard/sites.php');
        }

        $topics_arr = array_filter(array_map('trim', explode(',', $_POST['topics'] ?? '')));

        // Brand colors from color pickers
        $brand_colors = [];
        for ($ci = 0; $ci < 3; $ci++) {
            $hex = trim($_POST["brand_color_hex_{$ci}"] ?? '');
            if ($hex && preg_match('/^#[0-9a-fA-F]{3,8}$/', $hex)) {
                $brand_colors[] = $hex;
            }
        }

        // Brand fonts
        $brand_fonts = array_filter(array_map('trim', explode(',', $_POST['brand_fonts'] ?? '')));

        $stmt = $db->prepare('UPDATE sites SET name = ?, agent_mode = ?, blog_path = ?, topics = ?, rss_feeds = ?, cms_url = ?, cms_api_key = ?, server_type = ?, server_host = ?, server_user = ?, server_pass = ?, server_path = ?, git_repo = ?, hosting_panel = ?, brand_colors = ?, brand_fonts = ?, is_active = ? WHERE id = ?');
        $stmt->execute([
            trim($_POST['name']),
            $_POST['agent_mode'] ?? 'manual',
            trim($_POST['blog_path'] ?? '/blog'),
            json_encode(array_values($topics_arr)),
            json_encode(array_filter(array_map('trim', explode("\n", $_POST['rss_feeds'] ?? '')))),
            trim($_POST['cms_url'] ?? '') ?: null,
            trim($_POST['cms_api_key'] ?? '') ?: null,
            $_POST['server_type'] ?? 'api_only',
            trim($_POST['server_host'] ?? '') ?: null,
            trim($_POST['server_user'] ?? '') ?: null,
            trim($_POST['server_pass'] ?? '') ?: null,
            trim($_POST['server_path'] ?? '') ?: null,
            trim($_POST['git_repo'] ?? '') ?: null,
            trim($_POST['hosting_panel'] ?? '') ?: null,
            json_encode($brand_colors),
            json_encode(array_values($brand_fonts)),
            isset($_POST['is_active']) ? 1 : 0,
            $id,
        ]);

        $_SESSION['flash_success'] = 'Site settings updated.';
        redirect('/dashboard/site.php?id=' . $id);
    }

    if ($post_action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare('DELETE FROM sites WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);

        $_SESSION['flash_success'] = 'Site deleted.';
        redirect('/dashboard/sites.php');
    }
}

// ── Page content ────────────────────────────────────────
$page_title = 'Sites';

ob_start();

if ($action === 'add'):
?>
    <div class="card" style="max-width: 500px;">
        <div class="card-header">Add New Site</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">

            <div class="form-group">
                <label for="url">Website URL</label>
                <input type="text" id="url" name="url" class="form-control" placeholder="example.com or https://example.com" required autofocus>
            </div>

            <div class="form-group">
                <label for="name">Site Name (optional)</label>
                <input type="text" id="name" name="name" class="form-control" placeholder="My Website">
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-accent">Add Site</button>
                <a href="<?= url('/dashboard/sites.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>

<?php elseif ($action === 'view' && isset($_GET['id'])):
    redirect('/dashboard/site.php?id=' . (int)$_GET['id']);
    exit;
?>

<?php elseif ($action === 'edit' && isset($_GET['id'])):
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([(int)$_GET['id'], $user_id]);
    $site = $stmt->fetch();

    if (!$site):
        echo '<div class="alert alert-error">Site not found.</div>';
    else:
        $feeds = json_decode($site['rss_feeds'] ?? '[]', true) ?: [];
?>
    <div class="card" style="max-width: 600px;">
        <div class="card-header">Edit: <?= e($site['name']) ?></div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $site['id'] ?>">

            <div class="form-group">
                <label for="name">Site Name</label>
                <input type="text" id="name" name="name" class="form-control" value="<?= e($site['name']) ?>">
            </div>

            <div style="padding:12px 0;border-bottom:1px solid var(--border);margin-bottom:14px;">
                <div class="text-sm" style="font-weight:600;margin-bottom:8px;">Brand Colors</div>
                <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
                    <?php
                    $brand_colors = json_decode($site['brand_colors'] ?? '[]', true) ?: [];
                    for ($ci = 0; $ci < 3; $ci++):
                        $cval = $brand_colors[$ci] ?? '';
                    ?>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <input type="color" name="brand_color_<?= $ci ?>" value="<?= e($cval ?: '#1B3A6B') ?>" style="width:36px;height:36px;border:1px solid #ddd;border-radius:4px;cursor:pointer;padding:0;">
                        <input type="text" name="brand_color_hex_<?= $ci ?>" value="<?= e($cval) ?>" class="form-control" style="width:90px;font-size:12px;font-family:monospace;" placeholder="#hex" oninput="this.previousElementSibling.value=this.value">
                    </div>
                    <?php endfor; ?>
                    <span class="text-sm text-muted">Primary, Accent, Secondary</span>
                </div>
                <div class="text-sm text-muted mt-2">Used for carousels, blog theme, and social posts. Override scanner-detected colors here.</div>
            </div>

            <div class="form-group">
                <label for="brand_fonts">Brand Fonts (comma-separated)</label>
                <input type="text" id="brand_fonts" name="brand_fonts" class="form-control" value="<?= e(implode(', ', json_decode($site['brand_fonts'] ?? '[]', true) ?: [])) ?>" placeholder="e.g. Space Grotesk, Inter">
            </div>

            <div class="form-group">
                <label for="agent_mode">Agent Mode</label>
                <select id="agent_mode" name="agent_mode" class="form-control">
                    <option value="manual" <?= $site['agent_mode'] === 'manual' ? 'selected' : '' ?>>Manual (approve posts before publishing)</option>
                    <option value="auto" <?= $site['agent_mode'] === 'auto' ? 'selected' : '' ?>>Auto (publish immediately)</option>
                </select>
            </div>

            <div class="form-group">
                <label for="blog_path">Blog Path</label>
                <input type="text" id="blog_path" name="blog_path" class="form-control" value="<?= e($site['blog_path']) ?>">
            </div>

            <div class="form-group">
                <label for="topics">Topics / Niche (comma-separated)</label>
                <input type="text" id="topics" name="topics" class="form-control" value="<?= e(implode(', ', json_decode($site['topics'] ?? '[]', true) ?: [])) ?>" placeholder="e.g. software development, AI, web design">
                <div class="text-sm text-muted mt-2">Used for news scraping relevance and content strategy.</div>
            </div>

            <div class="form-group">
                <label for="rss_feeds">RSS News Feeds (one per line)</label>
                <textarea id="rss_feeds" name="rss_feeds" class="form-control" rows="5" placeholder="https://techcrunch.com/feed/&#10;https://www.wired.com/feed/rss"><?= e(implode("\n", $feeds)) ?></textarea>
                <div class="text-sm text-muted mt-2">News from these feeds will be filtered by your topics/keywords.</div>
            </div>

            <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
                <div class="text-sm" style="font-weight:600;margin-bottom:10px;">🔌 CMS / Content API</div>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="cms_url">CMS URL</label>
                        <input type="text" id="cms_url" name="cms_url" class="form-control" value="<?= e($site['cms_url'] ?? '') ?>" placeholder="https://cms.yourdomain.com">
                    </div>
                    <div class="form-group">
                        <label for="cms_api_key">CMS API Key</label>
                        <input type="text" id="cms_api_key" name="cms_api_key" class="form-control" value="<?= e($site['cms_api_key'] ?? '') ?>" placeholder="your-api-key">
                    </div>
                </div>
            </div>

            <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
                <div class="text-sm" style="font-weight:600;margin-bottom:10px;">🖥️ Server Access (for direct code changes)</div>
                <p class="text-sm text-muted mb-2">ContentAgent uses this to push SEO fixes, redirects, schema, llms.txt directly to your server. Leave blank to use the JS snippet approach instead.</p>

                <div class="form-group">
                    <label for="server_type">Access Type</label>
                    <select id="server_type" name="server_type" class="form-control">
                        <option value="api_only" <?= ($site['server_type'] ?? '') === 'api_only' ? 'selected' : '' ?>>API Only (CMS API + JS Snippet)</option>
                        <option value="ftp" <?= ($site['server_type'] ?? '') === 'ftp' ? 'selected' : '' ?>>FTP</option>
                        <option value="sftp" <?= ($site['server_type'] ?? '') === 'sftp' ? 'selected' : '' ?>>SFTP</option>
                        <option value="ssh" <?= ($site['server_type'] ?? '') === 'ssh' ? 'selected' : '' ?>>SSH</option>
                        <option value="cpanel" <?= ($site['server_type'] ?? '') === 'cpanel' ? 'selected' : '' ?>>cPanel</option>
                        <option value="git" <?= ($site['server_type'] ?? '') === 'git' ? 'selected' : '' ?>>Git (push to repo)</option>
                    </select>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="server_host">Host / IP</label>
                        <input type="text" id="server_host" name="server_host" class="form-control" value="<?= e($site['server_host'] ?? '') ?>" placeholder="ftp.yourdomain.com or IP address">
                    </div>
                    <div class="form-group">
                        <label for="server_path">Web Root Path</label>
                        <input type="text" id="server_path" name="server_path" class="form-control" value="<?= e($site['server_path'] ?? '') ?>" placeholder="/public_html or /var/www/html">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="server_user">Username</label>
                        <input type="text" id="server_user" name="server_user" class="form-control" value="<?= e($site['server_user'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="server_pass">Password</label>
                        <input type="password" id="server_pass" name="server_pass" class="form-control" value="<?= e($site['server_pass'] ?? '') ?>">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="git_repo">Git Repo URL (for git deploy)</label>
                        <input type="text" id="git_repo" name="git_repo" class="form-control" value="<?= e($site['git_repo'] ?? '') ?>" placeholder="https://github.com/user/repo.git">
                    </div>
                    <div class="form-group">
                        <label for="hosting_panel">Hosting Panel</label>
                        <select id="hosting_panel" name="hosting_panel" class="form-control">
                            <option value="">None</option>
                            <option value="cpanel" <?= ($site['hosting_panel'] ?? '') === 'cpanel' ? 'selected' : '' ?>>cPanel</option>
                            <option value="plesk" <?= ($site['hosting_panel'] ?? '') === 'plesk' ? 'selected' : '' ?>>Plesk</option>
                            <option value="vercel" <?= ($site['hosting_panel'] ?? '') === 'vercel' ? 'selected' : '' ?>>Vercel</option>
                            <option value="netlify" <?= ($site['hosting_panel'] ?? '') === 'netlify' ? 'selected' : '' ?>>Netlify</option>
                            <option value="aws" <?= ($site['hosting_panel'] ?? '') === 'aws' ? 'selected' : '' ?>>AWS</option>
                            <option value="digitalocean" <?= ($site['hosting_panel'] ?? '') === 'digitalocean' ? 'selected' : '' ?>>DigitalOcean</option>
                            <option value="linode" <?= ($site['hosting_panel'] ?? '') === 'linode' ? 'selected' : '' ?>>Linode</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_active" value="1" <?= $site['is_active'] ? 'checked' : '' ?>>
                    Site is active
                </label>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= url('/dashboard/site.php?id=' . $site['id']) ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>

        <div class="mt-4" style="padding-top: 14px; border-top: 1px solid var(--border);">
            <form method="POST" onsubmit="return confirm('Delete this site and all its data?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $site['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete Site</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

<?php else:
    // List all sites — rich card view
    $stmt = $db->prepare('SELECT * FROM sites WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$user_id]);
    $sites = $stmt->fetchAll();

    $topbar_actions = '<a href="' . url('/dashboard/onboarding.php') . '" class="btn btn-accent">+ Add Site</a>';
?>
    <?php if (empty($sites)): ?>
        <div class="card" style="text-align: center; padding: 40px;">
            <p class="text-muted mb-4">No sites added yet.</p>
            <a href="<?= url('/dashboard/onboarding.php') ?>" class="btn btn-accent">Add Your First Site</a>
        </div>
    <?php else: ?>
        <?php foreach ($sites as $s):
            // Fetch all data for this site
            $stmt = $db->prepare('SELECT status, COUNT(*) as cnt FROM posts WHERE site_id = ? GROUP BY status');
            $stmt->execute([$s['id']]);
            $pc = []; foreach ($stmt->fetchAll() as $r) $pc[$r['status']] = $r['cnt'];

            $stmt = $db->prepare('SELECT * FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 1');
            $stmt->execute([$s['id']]);
            $audit = $stmt->fetch();

            // SEO score history (all audits)
            $stmt = $db->prepare('SELECT score, total_issues, critical, warnings, pages_crawled, DATE_FORMAT(run_at, "%d %b") as label, run_at FROM seo_audits WHERE site_id = ? ORDER BY run_at ASC');
            $stmt->execute([$s['id']]);
            $audit_history = $stmt->fetchAll();

            // Issues fixed count
            $stmt = $db->prepare('SELECT COUNT(*) FROM seo_issues WHERE site_id = ? AND status IN ("fix_proposed","fix_applied","resolved")');
            $stmt->execute([$s['id']]);
            $issues_fixed = $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COUNT(*) FROM seo_issues WHERE audit_id = (SELECT a2.id FROM seo_audits a2 WHERE a2.site_id = ? ORDER BY a2.run_at DESC LIMIT 1) AND status = "open"');
            $stmt->execute([$s['id']]);
            $issues_open = $stmt->fetchColumn();

            // This week vs last week
            $stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE site_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)');
            $stmt->execute([$s['id']]);
            $posts_this_week = $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE site_id = ? AND created_at BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)');
            $stmt->execute([$s['id']]);
            $posts_last_week = $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COUNT(*) FROM keywords WHERE site_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)');
            $stmt->execute([$s['id']]);
            $kw_this_week = $stmt->fetchColumn();

            // First and latest audit for comparison
            $first_audit = !empty($audit_history) ? $audit_history[0] : null;
            $latest_audit = !empty($audit_history) ? $audit_history[count($audit_history) - 1] : null;
            $score_change = ($first_audit && $latest_audit && count($audit_history) > 1) ? $latest_audit['score'] - $first_audit['score'] : null;

            $stmt = $db->prepare('SELECT COUNT(*) FROM keywords WHERE site_id = ?');
            $stmt->execute([$s['id']]);
            $kw_count = $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COUNT(*) FROM seo_issues WHERE audit_id = (SELECT a2.id FROM seo_audits a2 WHERE a2.site_id = ? ORDER BY a2.run_at DESC LIMIT 1) AND status = "open"');
            $stmt->execute([$s['id']]);
            $open_issues = $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT * FROM posts WHERE site_id = ? ORDER BY created_at DESC LIMIT 5');
            $stmt->execute([$s['id']]);
            $recent_posts = $stmt->fetchAll();

            $stmt = $db->prepare('SELECT * FROM agent_log WHERE site_id = ? ORDER BY created_at DESC LIMIT 5');
            $stmt->execute([$s['id']]);
            $recent_activity = $stmt->fetchAll();

            $stmt = $db->prepare('SELECT * FROM keywords WHERE site_id = ? ORDER BY priority DESC LIMIT 8');
            $stmt->execute([$s['id']]);
            $top_kws = $stmt->fetchAll();

            $colors = json_decode($s['brand_colors'] ?? '[]', true) ?: [];
            $fonts = json_decode($s['brand_fonts'] ?? '[]', true) ?: [];
            $topics = json_decode($s['topics'] ?? '[]', true) ?: [];
        ?>

        <!-- Site Card: <?= e($s['name']) ?> -->
        <div style="margin-bottom: 24px;">
            <!-- Header -->
            <div class="card" style="margin-bottom: 2px; border-bottom: 3px solid var(--primary); border-radius: var(--radius) var(--radius) 0 0;">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-4">
                        <?php if ($audit): ?>
                            <?php
                            $sc = 'score-bad';
                            if ($audit['score'] >= 80) $sc = 'score-good';
                            elseif ($audit['score'] >= 50) $sc = 'score-ok';
                            ?>
                            <span class="score-circle <?= $sc ?>" style="width:48px;height:48px;font-size:18px;"><?= $audit['score'] ?></span>
                        <?php else: ?>
                            <span class="score-circle" style="width:48px;height:48px;font-size:12px;background:#f1f5f9;color:#94a3b8;">N/A</span>
                        <?php endif; ?>
                        <div>
                            <div style="font-size: 16px; font-weight: 600; color: var(--primary);"><?= e($s['name']) ?></div>
                            <div class="text-sm text-muted">
                                <?= e($s['domain']) ?>
                                <span class="badge badge-info" style="margin-left: 4px;"><?= e($s['platform'] ?: 'unknown') ?></span>
                                <span class="badge badge-<?= $s['is_active'] ? 'approved' : 'rejected' ?>"><?= $s['is_active'] ? 'Active' : 'Off' ?></span>
                                <span class="badge badge-<?= $s['agent_mode'] === 'auto' ? 'approved' : 'draft' ?>"><?= $s['agent_mode'] ?> mode</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <a href="<?= url('/api/export.php?site_id=' . $s['id'] . '&type=full') ?>" class="btn btn-outline btn-sm" title="Download full Excel report">Export CSV</a>
                        <a href="<?= url('/dashboard/sites.php?action=edit&id=' . $s['id']) ?>" class="btn btn-outline btn-sm">Edit</a>
                        <a href="<?= url('/dashboard/seo-audit.php?site=' . $s['id']) ?>" class="btn btn-outline btn-sm">Full Audit</a>
                    </div>
                </div>
            </div>

            <!-- Agent Actions -->
            <div class="card" style="margin-bottom: 2px; padding: 12px 16px;">
                <div class="flex gap-2" style="flex-wrap: wrap;">
                    <a href="<?= url('/dashboard/agent-run.php?agent=auto-fixer&site=' . $s['id']) ?>" class="btn btn-sm" style="background:#ef4444;color:#fff;text-decoration:none;">🤖 Auto-Fix All Issues</a>
                    <a href="<?= url('/dashboard/write.php?site=' . $s['id'] . '&step=propose') ?>" class="btn btn-accent btn-sm" style="text-decoration:none;">🧠 AI Content Planner</a>
                    <a href="<?= url('/dashboard/agent-run.php?agent=scanner&site=' . $s['id']) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">🔍 Scan Site</a>
                    <a href="<?= url('/dashboard/agent-run.php?agent=seo-auditor&site=' . $s['id']) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">📊 SEO Audit</a>
                    <a href="<?= url('/dashboard/agent-run.php?agent=keyword-research&site=' . $s['id']) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">🔑 Find Keywords</a>
                    <a href="<?= url('/dashboard/agent-run.php?agent=news-scraper&site=' . $s['id']) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">📰 Scrape News</a>
                    <a href="<?= url('/dashboard/agent-run.php?agent=evaluator&site=' . $s['id']) ?>" class="btn btn-outline btn-sm" style="text-decoration:none;">📈 Evaluate Strategy</a>
                </div>
            </div>

            <!-- Stats row -->
            <div class="stats-grid" style="margin-bottom: 2px;">
                <a href="<?= url('/dashboard/posts.php?site=' . $s['id'] . '&status=published') ?>" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                    <div class="stat-label">Published</div>
                    <div class="stat-value"><?= $pc['published'] ?? 0 ?></div>
                </a>
                <a href="<?= url('/dashboard/posts.php?site=' . $s['id'] . '&status=draft') ?>" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                    <div class="stat-label">Drafts</div>
                    <div class="stat-value"><?= $pc['draft'] ?? 0 ?></div>
                </a>
                <a href="<?= url('/dashboard/keywords.php?site=' . $s['id']) ?>" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                    <div class="stat-label">Keywords</div>
                    <div class="stat-value"><?= $kw_count ?></div>
                </a>
                <a href="<?= url('/dashboard/seo-audit.php?site=' . $s['id']) ?>" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                    <div class="stat-label">SEO Issues</div>
                    <div class="stat-value" style="color: <?= $open_issues > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= $open_issues ?></div>
                </a>
                <a href="<?= url('/dashboard/posts.php?site=' . $s['id']) ?>" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                    <div class="stat-label">Total Posts</div>
                    <div class="stat-value"><?= array_sum($pc) ?></div>
                </a>
            </div>

            <!-- Progress Tracker -->
            <div class="card" style="margin-bottom: 2px;">
                <div class="card-header flex justify-between items-center">
                    <span>Progress Tracker</span>
                    <span class="text-sm text-muted">
                        <?php if ($score_change !== null): ?>
                            Since first audit:
                            <span style="color: <?= $score_change >= 0 ? 'var(--success)' : 'var(--danger)' ?>; font-weight: 600;">
                                <?= $score_change >= 0 ? '+' : '' ?><?= $score_change ?> points
                            </span>
                        <?php else: ?>
                            Run audits weekly to track progress
                        <?php endif; ?>
                    </span>
                </div>

                <?php if (!empty($audit_history)): ?>
                <!-- SEO Score Timeline -->
                <div style="margin-bottom: 14px;">
                    <div class="text-sm text-muted mb-2" style="font-weight:600;">SEO Score Over Time</div>
                    <div style="display: flex; align-items: flex-end; gap: 4px; height: 80px; padding: 0 4px;">
                        <?php foreach ($audit_history as $ah):
                            $bar_height = max(8, ($ah['score'] / 100) * 70);
                            $bar_color = '#ef4444';
                            if ($ah['score'] >= 80) $bar_color = '#10b981';
                            elseif ($ah['score'] >= 50) $bar_color = '#f59e0b';
                        ?>
                        <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px;">
                            <span style="font-size: 11px; font-weight: 700; color: <?= $bar_color ?>;"><?= $ah['score'] ?></span>
                            <div style="width: 100%; max-width: 50px; height: <?= $bar_height ?>px; background: <?= $bar_color ?>; border-radius: 3px 3px 0 0; transition: height 0.3s;"></div>
                            <span style="font-size: 9px; color: #94a3b8;"><?= $ah['label'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Issues Progress Bar -->
                <div style="margin-bottom: 14px;">
                    <div class="text-sm text-muted mb-2" style="font-weight:600;">Issues: <?= $issues_fixed ?> fixed, <?= $issues_open ?> open</div>
                    <?php
                    $total_tracked = $issues_fixed + $issues_open;
                    $fix_pct = $total_tracked > 0 ? round(($issues_fixed / $total_tracked) * 100) : 0;
                    ?>
                    <div style="height: 10px; background: #fecaca; border-radius: 5px; overflow: hidden;">
                        <div style="height: 100%; width: <?= $fix_pct ?>%; background: #10b981; border-radius: 5px; transition: width 0.3s;"></div>
                    </div>
                    <div class="flex justify-between mt-2" style="font-size: 11px; color: #94a3b8;">
                        <span><?= $fix_pct ?>% resolved</span>
                        <span><?= $total_tracked ?> total tracked</span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- This Week Summary -->
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; padding-top: 10px; border-top: 1px solid var(--border);">
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: var(--primary);"><?= $posts_this_week ?></div>
                        <div style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">Posts this week</div>
                        <?php if ($posts_last_week > 0): ?>
                            <div style="font-size: 10px; color: <?= $posts_this_week >= $posts_last_week ? 'var(--success)' : 'var(--danger)' ?>;">
                                vs <?= $posts_last_week ?> last week
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: var(--primary);"><?= $kw_this_week ?></div>
                        <div style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">New keywords</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: var(--success);"><?= $issues_fixed ?></div>
                        <div style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">Issues fixed</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: <?= $audit ? ($audit['score'] >= 50 ? 'var(--success)' : 'var(--danger)') : '#94a3b8' ?>;"><?= $audit ? $audit['score'] . '%' : '—' ?></div>
                        <div style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">SEO Health</div>
                    </div>
                </div>
            </div>

            <div class="grid-2" style="margin-bottom: 2px;">
                <!-- Recent Posts -->
                <div class="card" style="margin-bottom: 0;">
                    <div class="card-header flex justify-between items-center">
                        <span>Recent Posts</span>
                        <a href="<?= url('/dashboard/posts.php?site=' . $s['id']) ?>" class="text-sm" style="color:var(--accent);text-decoration:none;">View all</a>
                    </div>
                    <?php if (empty($recent_posts)): ?>
                        <p class="text-muted text-sm">No posts yet.</p>
                    <?php else: ?>
                        <?php foreach ($recent_posts as $rp): ?>
                        <div style="padding: 6px 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <a href="<?= url('/dashboard/posts.php?action=edit&id=' . $rp['id']) ?>" style="color:var(--text);text-decoration:none;font-size:13px;"><?= e(truncate($rp['title'], 45)) ?></a>
                            </div>
                            <div class="flex gap-2 items-center">
                                <span class="badge badge-<?= $rp['type'] === 'news' ? 'info' : 'draft' ?>" style="font-size:10px;"><?= $rp['type'] ?></span>
                                <span class="badge badge-<?= $rp['status'] ?>" style="font-size:10px;"><?= $rp['status'] ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Top Keywords -->
                <div class="card" style="margin-bottom: 0;">
                    <div class="card-header flex justify-between items-center">
                        <span>Top Keywords</span>
                        <a href="<?= url('/dashboard/keywords.php?site=' . $s['id']) ?>" class="text-sm" style="color:var(--accent);text-decoration:none;">View all</a>
                    </div>
                    <?php if (empty($top_kws)): ?>
                        <p class="text-muted text-sm">No keywords yet.</p>
                    <?php else: ?>
                        <?php foreach ($top_kws as $kw): ?>
                        <div style="padding: 4px 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; font-size: 13px;">
                            <span><?= e(truncate($kw['keyword'], 40)) ?></span>
                            <div class="flex gap-2">
                                <span style="color:<?= $kw['priority'] >= 70 ? 'var(--success)' : ($kw['priority'] >= 40 ? 'var(--warning)' : '#aaa') ?>;font-weight:600;font-size:12px;">P:<?= $kw['priority'] ?></span>
                                <?php if ($kw['difficulty']): ?>
                                    <span style="color:<?= $kw['difficulty'] >= 70 ? 'var(--danger)' : ($kw['difficulty'] >= 40 ? 'var(--warning)' : 'var(--success)') ?>;font-size:12px;">D:<?= $kw['difficulty'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid-2" style="margin-bottom: 2px;">
                <!-- Brand Profile -->
                <div class="card" style="margin-bottom: 0;">
                    <div class="card-header">Brand Profile</div>
                    <div style="font-size: 13px;">
                        <div style="margin-bottom: 6px;">
                            <span class="text-muted">Tone:</span> <?= e($s['brand_tone'] ?? 'Not scanned') ?>
                        </div>
                        <div style="margin-bottom: 6px;">
                            <span class="text-muted">Colors:</span>
                            <?php foreach ($colors as $c): ?>
                                <span style="display:inline-block;width:18px;height:18px;background:<?= e($c) ?>;border-radius:3px;margin-right:3px;vertical-align:middle;border:1px solid #ddd;"></span>
                            <?php endforeach; ?>
                            <?php if (empty($colors)): ?>—<?php endif; ?>
                        </div>
                        <div style="margin-bottom: 6px;">
                            <span class="text-muted">Fonts:</span> <?= e(implode(', ', $fonts) ?: '—') ?>
                        </div>
                        <div>
                            <span class="text-muted">Topics:</span> <?= e(implode(', ', $topics) ?: '—') ?>
                        </div>
                    </div>
                </div>

                <!-- Agent Activity -->
                <div class="card" style="margin-bottom: 0;">
                    <div class="card-header">Recent Agent Activity</div>
                    <?php if (empty($recent_activity)): ?>
                        <p class="text-muted text-sm">No activity yet. Run an agent below.</p>
                    <?php else: ?>
                        <?php foreach ($recent_activity as $al): ?>
                        <div style="padding: 4px 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; font-size: 12px;">
                            <span>
                                <span class="badge badge-<?= $al['status'] === 'success' ? 'approved' : 'rejected' ?>" style="font-size:10px;"><?= $al['status'] ?></span>
                                <?= e($al['action']) ?>
                            </span>
                            <span class="text-muted"><?= format_date($al['created_at'], 'H:i, d M') ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Snippet Setup -->
            <div class="card" style="margin-bottom: 0;">
                <div class="card-header">📦 SEO Snippet — Embed on your site</div>
                <p class="text-sm text-muted mb-2">Add this one line to your website's <code>&lt;head&gt;</code>. ContentAgent will automatically fix canonical tags, meta titles, OG tags, schema, and handle redirects.</p>
                <div style="background:#1a1a1a;border-radius:6px;padding:12px 16px;font-family:monospace;font-size:12px;color:#10b981;position:relative;cursor:pointer;" onclick="navigator.clipboard.writeText(this.innerText.trim());this.querySelector('.copy-msg').style.display='block';setTimeout(()=>this.querySelector('.copy-msg').style.display='none',2000)">
                    <span class="copy-msg" style="display:none;position:absolute;top:4px;right:8px;font-size:10px;color:#fff;background:#10b981;padding:2px 8px;border-radius:3px;">Copied!</span>
                    &lt;script src="<?= e(config('app_url')) ?>/snippet/contentagent.js" data-site="<?= e($s['domain']) ?>"&gt;&lt;/script&gt;
                </div>
                <div class="text-sm text-muted mt-2">
                    <?php
                    $stmt_seo = $db->prepare('SELECT COUNT(*) FROM page_seo WHERE site_id = ?');
                    $stmt_seo->execute([$s['id']]);
                    $seo_rules = $stmt_seo->fetchColumn();
                    $stmt_redir = $db->prepare('SELECT COUNT(*) FROM redirects WHERE site_id = ?');
                    $stmt_redir->execute([$s['id']]);
                    $redirect_rules = $stmt_redir->fetchColumn();
                    ?>
                    Active: <strong><?= $seo_rules ?></strong> SEO rules, <strong><?= $redirect_rules ?></strong> redirects.
                    <?php if ($seo_rules == 0): ?>
                        Click "🤖 Auto-Fix All Issues" above to generate rules.
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php endforeach; ?>

        <!-- Agent buttons are now links to agent-run.php -->
    <?php endif; ?>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
