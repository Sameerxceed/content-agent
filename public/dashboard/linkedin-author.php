<?php
/**
 * LinkedIn author chooser — pick whether posts for THIS site go to the user's
 * personal profile or to one of the company pages they admin.
 *
 * Reached automatically after OAuth callback when the user admins ≥1 page.
 * Can also be reopened later from the site Overview connection card.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/integrations/linkedin.php';

auth_start();
auth_require();

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? $_POST['site_id'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { redirect('/dashboard/index.php'); }

// Load the connected LinkedIn integration
$stmt = $db->prepare('SELECT access_token, account_id, account_name, extra_data
                      FROM integrations WHERE site_id = ? AND platform = "linkedin" AND is_active = 1');
$stmt->execute([$site_id]);
$integration = $stmt->fetch();

if (!$integration) {
    $_SESSION['flash_error'] = 'LinkedIn is not connected for this site. Connect it first.';
    redirect('/dashboard/site.php?id=' . $site_id);
}

$extra        = json_decode($integration['extra_data'] ?? '{}', true) ?: [];
$personal_urn = $extra['personal_urn']  ?? $integration['account_id'];
$personal_nm  = $extra['personal_name'] ?? $integration['account_name'];
$current_urn  = $integration['account_id'];

// Handle the save POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash_error'] = 'Invalid form submission.';
        redirect('/dashboard/linkedin-author.php?site=' . $site_id);
    }
    $picked_urn  = trim($_POST['author_urn']  ?? '');
    $picked_name = trim($_POST['author_name'] ?? '');
    if (linkedin_set_author($db, $site_id, $picked_urn, $picked_name)) {
        $_SESSION['flash_success'] = 'LinkedIn posts will go to: ' . $picked_name;
        redirect('/dashboard/site.php?id=' . $site_id);
    } else {
        $_SESSION['flash_error'] = 'Failed to update author. Reconnect LinkedIn and try again.';
        redirect('/dashboard/linkedin-author.php?site=' . $site_id);
    }
}

// Fetch admin pages (may take a moment)
$pages = linkedin_list_admin_pages($integration['access_token']);

$page_title = 'LinkedIn author — ' . $site['name'];
ob_start();
?>
<style>
.la-card { background:#fff; border:1px solid var(--border); border-radius:6px; padding:12px 14px; margin-bottom:8px; display:flex; align-items:center; gap:12px; }
.la-card.current { border-left:3px solid var(--success); background:#f0fdf4; }
.la-ico { width:36px; height:36px; border-radius:6px; background:#0A66C2; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; flex-shrink:0; }
.la-name { font-weight:600; font-size:14px; color:var(--primary); }
.la-meta { font-size:11px; color:var(--text-light); margin-top:2px; }
.la-pick { margin-left:auto; }
</style>

<div class="flex items-center justify-between mb-4">
    <div>
        <div style="font-size:11px; color:var(--text-light); text-transform:uppercase; letter-spacing:0.5px;">LinkedIn — author for this site</div>
        <h2 style="font-size:18px; font-weight:600; margin:2px 0 0; color:var(--primary);"><?= e($site['name']) ?></h2>
    </div>
    <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" class="btn btn-outline btn-sm" style="text-decoration:none;">← Back</a>
</div>

<p style="font-size:13px; color:var(--text-light); margin-bottom:14px;">Pick where ContentAgent's LinkedIn posts go for <strong><?= e($site['name']) ?></strong>. You can change this any time.</p>

<!-- Personal profile option -->
<form method="POST" class="la-card <?= $current_urn === $personal_urn ? 'current' : '' ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="author_urn"  value="<?= e($personal_urn) ?>">
    <input type="hidden" name="author_name" value="<?= e($personal_nm) ?>">
    <div class="la-ico">👤</div>
    <div>
        <div class="la-name"><?= e($personal_nm ?: 'Personal profile') ?></div>
        <div class="la-meta">Personal profile · posts appear on your own timeline</div>
    </div>
    <div class="la-pick">
        <?php if ($current_urn === $personal_urn): ?>
            <span style="font-size:11px; color:var(--success); font-weight:600;">✓ Current</span>
        <?php else: ?>
            <button type="submit" class="btn btn-primary btn-sm">Use this</button>
        <?php endif; ?>
    </div>
</form>

<?php if (!empty($pages)): ?>
    <?php foreach ($pages as $p): ?>
    <form method="POST" class="la-card <?= $current_urn === $p['urn'] ? 'current' : '' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="author_urn"  value="<?= e($p['urn']) ?>">
        <input type="hidden" name="author_name" value="<?= e($p['name']) ?>">
        <div class="la-ico" style="background:#1B3A6B;">🏢</div>
        <div>
            <div class="la-name"><?= e($p['name']) ?></div>
            <div class="la-meta">Company page · posts appear on this page's feed</div>
        </div>
        <div class="la-pick">
            <?php if ($current_urn === $p['urn']): ?>
                <span style="font-size:11px; color:var(--success); font-weight:600;">✓ Current</span>
            <?php else: ?>
                <button type="submit" class="btn btn-primary btn-sm">Use this</button>
            <?php endif; ?>
        </div>
    </form>
    <?php endforeach; ?>
<?php else: ?>
<div class="alert alert-info" style="margin-top:14px;">
    No company pages found that you're an administrator of. If you should see pages here, check that you're listed as an Admin (not just a content poster) on linkedin.com/company/.../admin/.
</div>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
