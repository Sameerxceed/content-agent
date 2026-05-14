<?php
/**
 * Dashboard layout template.
 * Usage: include this file with $page_title and $page_content set.
 */

$user = auth_user();
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Detect "in-site" mode: a site_id is in the URL (?site=X or ?id=X on site.php)
$ctx_site_id = 0;
if (!empty($_GET['site']) && is_numeric($_GET['site'])) {
    $ctx_site_id = (int)$_GET['site'];
} elseif ($current_page === 'site' && !empty($_GET['id']) && is_numeric($_GET['id'])) {
    $ctx_site_id = (int)$_GET['id'];
}

// If we have a site context, fetch the site name for the sidebar header
$ctx_site = null;
if ($ctx_site_id) {
    $ctx_db = require __DIR__ . '/../../includes/db.php';
    $_ctx_stmt = $ctx_db->prepare('SELECT id, name, domain FROM sites WHERE id = ? AND user_id = ?');
    $_ctx_stmt->execute([$ctx_site_id, $user['id'] ?? 0]);
    $ctx_site = $_ctx_stmt->fetch();
    if (!$ctx_site) { $ctx_site_id = 0; }
}

// Page-level alerts count for the "Alerts" link badge
$ctx_alerts_unread = 0;
if ($ctx_site_id) {
    try {
        $_a = $ctx_db->prepare('SELECT COUNT(*) FROM alerts WHERE site_id = ? AND read_at IS NULL');
        $_a->execute([$ctx_site_id]);
        $ctx_alerts_unread = (int)$_a->fetchColumn();
    } catch (PDOException $e) {}
}

// Helper to mark sidebar items active. Match by current_page + optional query check.
function sidebar_active(string $page, array $pages_or_query = []): string {
    global $current_page;
    if (empty($pages_or_query)) {
        return $current_page === $page ? 'active' : '';
    }
    if (in_array($current_page, $pages_or_query, true)) return 'active';
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title ?? 'Dashboard') ?> — ContentAgent</title>
    <link rel="icon" href="/dashboard/assets/img/logo.png" type="image/png">
    <style>
        :root {
            --primary: #1B3A6B;
            --primary-dark: #132a4f;
            --accent: #CC3300;
            --accent-hover: #a82a00;
            --bg: #f5f6fa;
            --white: #ffffff;
            --text: #2c3e50;
            --text-light: #64748b;
            --border: #e2e8f0;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --sidebar-w: 240px;
            --radius: 6px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            font-size: 14px;
            line-height: 1.5;
        }

        /* ── Sidebar ─────────────────────────────────── */
        .sidebar {
            position: fixed;
            left: 0; top: 0;
            width: var(--sidebar-w);
            height: 100vh;
            background: var(--primary);
            color: #fff;
            display: flex;
            flex-direction: column;
            z-index: 100;
        }

        .sidebar-logo {
            padding: 16px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-logo img {
            max-width: 140px;
            height: auto;
        }

        .sidebar-nav {
            flex: 1;
            padding: 8px 0;
            overflow-y: auto;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            font-size: 13px;
            transition: all 0.15s;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }

        .sidebar-nav a.active {
            border-right: 3px solid var(--accent);
        }

        .sidebar-nav .nav-icon {
            width: 18px;
            text-align: center;
            font-size: 15px;
        }

        .sidebar-nav .nav-section {
            padding: 16px 20px 6px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.4);
        }

        .sidebar-user {
            padding: 12px 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sidebar-user a {
            color: rgba(255,255,255,0.6);
            text-decoration: none;
            font-size: 12px;
        }

        .sidebar-user a:hover { color: #fff; }

        /* ── Main content ────────────────────────────── */
        .main {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
        }

        .topbar {
            background: var(--white);
            border-bottom: 1px solid var(--border);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .topbar h1 {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary);
        }

        .content {
            padding: 20px 24px;
        }

        /* ── Cards ───────────────────────────────────── */
        .card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 16px;
        }

        .card-header {
            font-size: 14px;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
        }

        /* ── Stat cards ──────────────────────────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
        }

        .stat-card .stat-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .stat-card .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-card .stat-sub {
            font-size: 11px;
            color: var(--text-light);
            margin-top: 2px;
        }

        /* ── Tables ──────────────────────────────────── */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th {
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-light);
            padding: 8px 10px;
            border-bottom: 2px solid var(--border);
            background: #fafbfc;
        }

        table td {
            padding: 10px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }

        table tr:hover { background: #f8fafc; }

        /* ── Buttons ─────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border: none;
            border-radius: var(--radius);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }
        .btn-primary:hover { background: var(--primary-dark); }

        .btn-accent {
            background: var(--accent);
            color: #fff;
        }
        .btn-accent:hover { background: var(--accent-hover); }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }
        .btn-outline:hover { background: #f1f5f9; }

        .btn-sm { padding: 4px 10px; font-size: 12px; }

        .btn-success { background: var(--success); color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }

        /* ── Badges ───────────────────────────────────── */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-draft { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #d1fae5; color: #065f46; }
        .badge-published { background: #dbeafe; color: #1e40af; }
        .badge-rejected { background: #fecaca; color: #991b1b; }
        .badge-critical { background: #fecaca; color: #991b1b; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info { background: #dbeafe; color: #1e40af; }

        /* ── Forms ───────────────────────────────────── */
        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 4px;
        }

        .form-control {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 13px;
            color: var(--text);
            transition: border-color 0.15s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(27, 58, 107, 0.1);
        }

        textarea.form-control { resize: vertical; min-height: 80px; }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 8px center;
            background-repeat: no-repeat;
            background-size: 16px;
            padding-right: 32px;
        }

        /* ── Alerts ──────────────────────────────────── */
        .alert {
            padding: 10px 14px;
            border-radius: var(--radius);
            font-size: 13px;
            margin-bottom: 14px;
        }

        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fecaca; color: #991b1b; border: 1px solid #fca5a5; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .alert-info { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }

        /* ── Score circle ────────────────────────────── */
        .score-circle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            font-weight: 700;
            font-size: 16px;
        }

        .score-good { background: #d1fae5; color: #065f46; }
        .score-ok { background: #fef3c7; color: #92400e; }
        .score-bad { background: #fecaca; color: #991b1b; }

        /* ── Utility ─────────────────────────────────── */
        .text-muted { color: var(--text-light); }
        .text-sm { font-size: 12px; }
        .mt-2 { margin-top: 8px; }
        .mt-4 { margin-top: 16px; }
        .mb-2 { margin-bottom: 8px; }
        .mb-4 { margin-bottom: 16px; }
        .flex { display: flex; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .gap-2 { gap: 8px; }
        .gap-4 { gap: 16px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main { margin-left: 0; }
            .grid-2 { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<nav class="sidebar">
    <div class="sidebar-logo">
        <img src="<?= url('/dashboard/assets/img/logo.png') ?>" alt="ContentAgent">
    </div>

    <div class="sidebar-nav">
        <?php if ($ctx_site): ?>
            <!-- ── In-site sidebar ─────────────────────────────────── -->
            <a href="<?= url('/dashboard/index.php') ?>" style="font-size:11px;color:#94a3b8;text-decoration:none;padding:6px 16px;display:block;letter-spacing:0.3px;">&larr; All sites</a>
            <div style="padding:4px 16px 10px;border-bottom:1px solid rgba(255,255,255,0.08);margin-bottom:6px;">
                <div style="font-size:13px;font-weight:600;color:#fff;line-height:1.3;"><?= e($ctx_site['name']) ?></div>
                <div style="font-size:11px;color:#cbd5e1;"><?= e($ctx_site['domain']) ?></div>
            </div>

            <a href="<?= url('/dashboard/site.php?id=' . $ctx_site_id) ?>" class="<?= sidebar_active('site') ?>">
                <span class="nav-icon">&#8962;</span> Overview
            </a>
            <a href="<?= url('/dashboard/posts.php?site=' . $ctx_site_id) ?>" class="<?= sidebar_active('', ['posts', 'write']) ?>">
                <span class="nav-icon">&#9998;</span> Content
            </a>
            <a href="<?= url('/dashboard/keywords.php?site=' . $ctx_site_id) ?>" class="<?= sidebar_active('', ['keywords', 'seo-audit', 'report', 'search-console']) ?>">
                <span class="nav-icon">&#128269;</span> SEO
            </a>
            <a href="<?= url('/dashboard/competitors.php?site=' . $ctx_site_id) ?>" class="<?= sidebar_active('', ['competitors', 'content-gaps']) ?>">
                <span class="nav-icon">&#127919;</span> Competitors
            </a>
            <a href="<?= url('/dashboard/calendar.php?site=' . $ctx_site_id) ?>" class="<?= sidebar_active('calendar') ?>">
                <span class="nav-icon">&#128197;</span> Calendar
            </a>
            <a href="<?= url('/dashboard/performance.php?site=' . $ctx_site_id) ?>" class="<?= sidebar_active('performance') ?>">
                <span class="nav-icon">&#128200;</span> Performance
            </a>
            <a href="<?= url('/dashboard/subscribers.php?site=' . $ctx_site_id) ?>" class="<?= sidebar_active('subscribers') ?>">
                <span class="nav-icon">&#9993;</span> Subscribers
            </a>
            <a href="<?= url('/dashboard/ai-presence.php?site=' . $ctx_site_id) ?>" class="<?= sidebar_active('', ['ai-presence', 'ai-visibility', 'brand-mentions']) ?>">
                <span class="nav-icon">&#128172;</span> AI Presence
            </a>
            <a href="<?= url('/dashboard/alerts.php?site=' . $ctx_site_id) ?>" class="<?= sidebar_active('alerts') ?>" style="display:flex;justify-content:space-between;align-items:center;">
                <span><span class="nav-icon">&#128276;</span> Alerts</span>
                <?php if ($ctx_alerts_unread > 0): ?>
                    <span style="background:#ef4444;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px;font-weight:600;"><?= $ctx_alerts_unread ?></span>
                <?php endif; ?>
            </a>

            <div class="nav-section" style="margin-top:14px;">Global</div>
            <a href="<?= url('/dashboard/integrations.php') ?>" class="<?= sidebar_active('integrations') ?>">
                <span class="nav-icon">&#128268;</span> Integrations
            </a>
            <a href="<?= url('/dashboard/settings.php') ?>" class="<?= sidebar_active('settings') ?>">
                <span class="nav-icon">&#9881;</span> Settings
            </a>
        <?php else: ?>
            <!-- ── Global sidebar ──────────────────────────────────── -->
            <div class="nav-section">Main</div>
            <a href="<?= url('/dashboard/index.php') ?>" class="<?= sidebar_active('index') ?>">
                <span class="nav-icon">&#9632;</span> Dashboard
            </a>

            <div class="nav-section">Settings</div>
            <a href="<?= url('/dashboard/integrations.php') ?>" class="<?= sidebar_active('integrations') ?>">
                <span class="nav-icon">&#128268;</span> Integrations
            </a>
            <a href="<?= url('/dashboard/settings.php') ?>" class="<?= sidebar_active('settings') ?>">
                <span class="nav-icon">&#9881;</span> Settings
            </a>
        <?php endif; ?>
    </div>

    <?php if (($user['id'] ?? 0) == 1): ?>
    <div style="padding: 8px 12px; border-top: 1px solid rgba(255,255,255,0.1);">
        <button onclick="deployToHtdocs(this)" style="width:100%;padding:6px;background:var(--accent);color:#fff;border:none;border-radius:4px;font-size:11px;cursor:pointer;font-weight:600;">Deploy Changes</button>
    </div>
    <?php endif; ?>

    <div class="sidebar-user">
        <span><?= e($user['name'] ?? 'User') ?></span>
        <a href="<?= url('/auth/logout.php') ?>">Logout</a>
    </div>

    <script>
    async function deployToHtdocs(btn) {
        btn.disabled = true;
        btn.textContent = 'Deploying...';
        try {
            const res = await fetch('<?= url('/api/sync.php') ?>', {method:'POST'});
            const data = await res.json();
            if (data.success) {
                btn.textContent = 'Deployed!';
                btn.style.background = '#10b981';
                setTimeout(() => { btn.textContent = 'Deploy Changes'; btn.style.background = ''; btn.disabled = false; }, 3000);
            } else {
                btn.textContent = 'Failed';
                setTimeout(() => { btn.textContent = 'Deploy Changes'; btn.disabled = false; }, 3000);
            }
        } catch(e) { btn.textContent = 'Deploy Changes'; btn.disabled = false; }
    }
    </script>
</nav>

<div class="main">
    <div class="topbar">
        <h1><?= e($page_title ?? 'Dashboard') ?></h1>
        <div class="flex items-center gap-2">
            <?= $topbar_actions ?? '' ?>
        </div>
    </div>

    <div class="content">
        <?php
        // Auto back button
        $current_file = basename($_SERVER['PHP_SELF'], '.php');
        $has_params = !empty($_GET['action']) || !empty($_GET['audit']) || !empty($_GET['id']) || !empty($_GET['site']);
        if ($has_params && $current_file !== 'index'):
            if ($current_file === 'site'):
                // Site detail → back to Dashboard
                $back_url = url('/dashboard/index.php');
                $back_label = 'Dashboard';
            elseif (!empty($_GET['site']) || !empty($_GET['id'])):
                // Tool pages with site context → back to site
                $back_site_id = $_GET['site'] ?? $_GET['id'] ?? '';
                $back_url = url('/dashboard/site.php?id=' . (int)$back_site_id);
                $back_label = 'Site';
            else:
                $back_url = url('/dashboard/index.php');
                $back_label = 'Dashboard';
            endif;
        ?>
            <div style="margin-bottom: 10px;">
                <a href="<?= $back_url ?>" style="color: var(--text-light); text-decoration: none; font-size: 13px;">&laquo; Back to <?= $back_label ?></a>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success"><?= e($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-error"><?= e($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
        <?php endif; ?>

        <?= $page_content ?? '' ?>
    </div>
</div>

</body>
</html>
