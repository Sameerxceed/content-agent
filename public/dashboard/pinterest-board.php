<?php
/**
 * Pinterest board picker — pick which board pins should be posted to for
 * THIS site. Reached automatically after OAuth callback when the user has
 * 2+ boards (single-board accounts auto-pick). Reachable later from the
 * Setup → Channels card to switch boards.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/integrations/pinterest.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';

$site_id = (int)($_GET['site'] ?? $_POST['site_id'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { redirect('/dashboard/index.php'); }

// Load the active Pinterest integration.
$stmt = $db->prepare('SELECT account_name, extra_data FROM integrations
                      WHERE site_id = ? AND platform = "pinterest" AND is_active = 1 LIMIT 1');
$stmt->execute([$site_id]);
$integration = $stmt->fetch();

if (!$integration) {
    $_SESSION['flash_error'] = 'Pinterest is not connected for this site. Connect it first.';
    redirect('/dashboard/setup.php?site=' . $site_id . '&tab=channels');
}

$extra            = json_decode((string)($integration['extra_data'] ?? '{}'), true) ?: [];
$current_board_id = (string)($extra['board_id'] ?? '');

// Handle POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash_error'] = 'Invalid form submission.';
        redirect('/dashboard/pinterest-board.php?site=' . $site_id);
    }
    $picked_id   = trim((string)($_POST['board_id']   ?? ''));
    $picked_name = trim((string)($_POST['board_name'] ?? ''));
    if ($picked_id && pinterest_set_board($db, $site_id, $picked_id, $picked_name)) {
        $_SESSION['flash_success'] = 'Pinterest pins will go to the "' . $picked_name . '" board.';
        redirect('/dashboard/setup.php?site=' . $site_id . '&tab=channels');
    } else {
        $_SESSION['flash_error'] = 'Failed to save board pick. Reconnect Pinterest and try again.';
        redirect('/dashboard/pinterest-board.php?site=' . $site_id);
    }
}

// Need a live access_token to list boards (refresh transparently if needed).
$token  = pinterest_get_active_token($db, $site_id);
$boards = $token ? pinterest_list_boards($token) : [];

$page_title = 'Pinterest board — ' . $site['name'];
ob_start();
?>
<style>
.pb-card { background:#fff; border:1px solid var(--border); border-radius:6px; padding:12px 14px; margin-bottom:8px; display:flex; align-items:center; gap:12px; }
.pb-card.current { border-left:3px solid var(--success); background:#f0fdf4; }
.pb-ico { width:36px; height:36px; border-radius:6px; background:#E60023; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; flex-shrink:0; }
.pb-name { font-weight:600; font-size:14px; color:var(--primary); }
.pb-meta { font-size:11px; color:var(--text-light); margin-top:2px; }
.pb-pick { margin-left:auto; }
</style>

<div class="flex items-center justify-between mb-4">
    <div>
        <div style="font-size:11px; color:var(--text-light); text-transform:uppercase; letter-spacing:0.5px;">Pinterest — board for this site</div>
        <h2 style="font-size:18px; font-weight:600; margin:2px 0 0; color:var(--primary);"><?= e($site['name']) ?></h2>
    </div>
    <a href="<?= url('/dashboard/setup.php?site=' . $site_id . '&tab=channels') ?>" class="btn btn-outline btn-sm" style="text-decoration:none;">← Back</a>
</div>

<p style="font-size:13px; color:var(--text-light); margin-bottom:14px;">Pinterest pins from <strong><?= e($site['name']) ?></strong> will go to the board you pick here. You can switch any time.</p>

<?php if (empty($boards)): ?>
    <div class="alert alert-info" style="margin-top:14px;">
        We couldn't fetch your boards. Either the OAuth connection expired (reconnect Pinterest) or your account has no boards yet — create one on
        <a href="https://www.pinterest.com" target="_blank" rel="noopener">pinterest.com</a> and refresh.
    </div>
<?php else: ?>
    <?php foreach ($boards as $b): ?>
        <form method="POST" class="pb-card <?= $current_board_id === $b['id'] ? 'current' : '' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="board_id"   value="<?= e($b['id']) ?>">
            <input type="hidden" name="board_name" value="<?= e($b['name']) ?>">
            <div class="pb-ico">P</div>
            <div>
                <div class="pb-name"><?= e($b['name']) ?></div>
                <div class="pb-meta"><?= (int)$b['pin_count'] ?> pin<?= $b['pin_count'] == 1 ? '' : 's' ?></div>
            </div>
            <div class="pb-pick">
                <?php if ($current_board_id === $b['id']): ?>
                    <span style="font-size:11px; color:var(--success); font-weight:600;">✓ Current</span>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary btn-sm">Use this board</button>
                <?php endif; ?>
            </div>
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
