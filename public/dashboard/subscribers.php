<?php
/**
 * Dashboard — Newsletter subscribers per site.
 * Manage list (add/import/unsubscribe), see counts, surface the public signup URL.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/newsletter.php';

auth_start();
auth_require();

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { redirect('/dashboard/index.php'); }

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { $_SESSION['flash_error'] = 'Invalid form submission.'; redirect('/dashboard/subscribers.php?site=' . $site_id); }
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['name'] ?? '');
        $r = newsletter_subscribe($db, $site_id, $email, $name);
        $_SESSION[$r['success'] ? 'flash_success' : 'flash_error'] =
            $r['success']
                ? (($r['reactivated'] ?? false) ? 'Re-activated subscriber.' : 'Subscriber added.')
                : ($r['error'] ?? 'Failed to add');
    }

    if ($action === 'bulk_add') {
        $raw = trim($_POST['emails'] ?? '');
        $lines = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $added = 0; $skipped = 0;
        foreach ($lines as $line) {
            $email = trim($line);
            if (!$email) continue;
            $r = newsletter_subscribe($db, $site_id, $email);
            if (!empty($r['success'])) $added++; else $skipped++;
        }
        $_SESSION['flash_success'] = "Imported {$added} new subscriber" . ($added === 1 ? '' : 's') . ($skipped ? " ({$skipped} skipped — duplicates or invalid)" : '');
    }

    if ($action === 'unsubscribe') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('UPDATE subscribers SET status = "unsubscribed", unsubscribed_at = NOW() WHERE id = ? AND site_id = ?')->execute([$id, $site_id]);
        $_SESSION['flash_success'] = 'Unsubscribed.';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM subscribers WHERE id = ? AND site_id = ?')->execute([$id, $site_id]);
        $_SESSION['flash_success'] = 'Subscriber deleted.';
    }

    redirect('/dashboard/subscribers.php?site=' . $site_id);
}

$active_count = newsletter_subscriber_count($db, $site_id);
$total_count  = (int)$db->query('SELECT COUNT(*) FROM subscribers WHERE site_id = ' . $site_id)->fetchColumn();
$unsub_count  = $total_count - $active_count;

// Recent sends
$sends = [];
try {
    $s = $db->prepare('SELECT subject, sent_count, sent_at FROM newsletters WHERE site_id = ? ORDER BY sent_at DESC LIMIT 5');
    $s->execute([$site_id]);
    $sends = $s->fetchAll();
} catch (PDOException $e) {}

$stmt = $db->prepare('SELECT * FROM subscribers WHERE site_id = ? ORDER BY status ASC, subscribed_at DESC');
$stmt->execute([$site_id]);
$subscribers = $stmt->fetchAll();

$_base = config('app_url');
if (!$_base) {
    $_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_base   = $_scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$signup_url = rtrim($_base, '/') . '/blog/subscribe.php?site=' . $site_id;
$resend_ok  = !empty(config('resend_api_key'));

$page_title = 'Subscribers — ' . $site['name'];
ob_start();
?>
<style>
.sub-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:18px; }
.sub-actions { display:flex; gap:8px; align-items:center; }
.sub-status-active   { color:#065f46; }
.sub-status-unsubscribed { color:#94a3b8; }
.sub-status-bounced  { color:#991b1b; }
</style>

<div class="flex items-center justify-between mb-4">
    <div>
        <div style="font-size:11px; color:var(--text-light); text-transform:uppercase; letter-spacing:0.5px;">Newsletter</div>
        <h2 style="font-size:18px; font-weight:600; margin:2px 0 0; color:var(--primary);"><?= e($site['name']) ?></h2>
    </div>
</div>

<?php if (!$resend_ok): ?>
<div class="alert alert-warning">
    Resend is not configured — sends will fail. <a href="<?= url('/dashboard/integrations.php') ?>">Set it up in the Integrations Hub</a>.
</div>
<?php endif; ?>

<div class="sub-grid">
    <div class="stat-card">
        <div class="stat-label">Active subscribers</div>
        <div class="stat-value"><?= number_format($active_count) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Unsubscribed</div>
        <div class="stat-value" style="color:var(--text-light);"><?= number_format($unsub_count) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Recent sends (30d)</div>
        <div class="stat-value"><?= count($sends) ?></div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">Add subscriber</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" class="form-control" required placeholder="user@example.com">
            </div>
            <div class="form-group">
                <label>Name (optional)</label>
                <input type="text" name="name" class="form-control" placeholder="Jane Doe">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Add</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header">Bulk import</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_add">
            <div class="form-group">
                <label>Paste emails (one per line, or comma/space separated)</label>
                <textarea name="emails" class="form-control" rows="4" placeholder="alice@example.com&#10;bob@example.com"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Import</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">Public signup link</div>
    <div style="display:flex; gap:10px; align-items:center;">
        <input type="text" id="signupUrl" class="form-control" readonly value="<?= e($signup_url) ?>">
        <button class="btn btn-outline btn-sm" onclick="copyUrl()">Copy</button>
    </div>
    <div style="font-size:11px; color:var(--text-light); margin-top:6px;">Embed this anywhere — it accepts a POSTed email and adds them as a subscriber.</div>
</div>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span>Subscribers (<?= count($subscribers) ?>)</span>
        <a href="<?= url('/dashboard/subscribers.php?site=' . $site_id . '&export=csv') ?>" style="font-size:12px;color:var(--primary);text-decoration:none;">Export CSV →</a>
    </div>
    <?php if (!$subscribers): ?>
        <div style="padding:14px; color:var(--text-light); font-size:13px; background:#f8fafc; border-radius:6px; border:1px dashed var(--border);">
            No subscribers yet. Add manually above or share the public signup link.
        </div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Email</th>
                <th>Name</th>
                <th>Status</th>
                <th>Subscribed</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($subscribers as $sub): ?>
            <tr>
                <td><?= e($sub['email']) ?></td>
                <td><?= e($sub['name'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                <td><span class="sub-status-<?= e($sub['status']) ?>"><?= e($sub['status']) ?></span></td>
                <td><?= e(date('d M Y', strtotime($sub['subscribed_at']))) ?></td>
                <td class="sub-actions">
                    <?php if ($sub['status'] === 'active'): ?>
                    <form method="POST" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unsubscribe">
                        <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('Unsubscribe this email?')">Unsubscribe</button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger);" onclick="return confirm('Delete permanently?')">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if ($sends): ?>
<div class="card">
    <div class="card-header">Recent sends</div>
    <table>
        <thead><tr><th>Subject</th><th>Recipients</th><th>Sent</th></tr></thead>
        <tbody>
            <?php foreach ($sends as $s): ?>
            <tr>
                <td><?= e($s['subject']) ?></td>
                <td><?= (int)$s['sent_count'] ?></td>
                <td><?= e(date('d M Y H:i', strtotime($s['sent_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
function copyUrl() {
    const el = document.getElementById('signupUrl');
    el.select(); el.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(el.value);
    event.target.textContent = 'Copied!';
    setTimeout(() => event.target.textContent = 'Copy', 1500);
}
</script>

<?php
// CSV export
if (($_GET['export'] ?? '') === 'csv') {
    ob_clean();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="subscribers-' . preg_replace('/[^a-z0-9]/i', '-', $site['name']) . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'name', 'status', 'subscribed_at', 'unsubscribed_at']);
    foreach ($subscribers as $s) {
        fputcsv($out, [$s['email'], $s['name'], $s['status'], $s['subscribed_at'], $s['unsubscribed_at']]);
    }
    fclose($out);
    exit;
}

$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
