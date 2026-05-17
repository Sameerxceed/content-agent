<?php
/**
 * SEO Approvals — review pending brand-facing SEO proposals.
 *
 * Shows each pending page with Current (from live site) vs Proposed (editable).
 * Customer approves, edits & approves, or rejects per page.
 *
 * GET ?site=1
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/scraper.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { redirect('/dashboard/index.php'); }

// Get all proposals (pending first, then approved/rejected at bottom)
$stmt = $db->prepare('SELECT * FROM page_seo WHERE site_id = ? ORDER BY FIELD(status, "pending", "approved", "rejected"), url_path');
$stmt->execute([$site_id]);
$all = $stmt->fetchAll();

$pending = array_filter($all, fn($p) => $p['status'] === 'pending');
$approved = array_filter($all, fn($p) => $p['status'] === 'approved');
$rejected = array_filter($all, fn($p) => $p['status'] === 'rejected');

$page_title = 'SEO/AEO — Approvals';
ob_start();

$active = 'approvals';
$filter_site = $site_id;
include __DIR__ . '/_health_tabs.php';
?>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to <?= e($site['name']) ?></a>
</div>

<div style="text-align:center;margin-bottom:14px;">
    <h2 style="font-size:20px;color:var(--primary);margin-bottom:4px;">SEO Approvals</h2>
    <p style="font-size:13px;color:#64748b;">Review brand-facing SEO changes before they go live on <?= e($site['domain']) ?></p>
</div>

<div class="stats-grid" style="margin-bottom:14px;">
    <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value" style="color:#f59e0b;"><?= count($pending) ?></div></div>
    <div class="stat-card"><div class="stat-label">Approved (Live)</div><div class="stat-value" style="color:#10b981;"><?= count($approved) ?></div></div>
    <div class="stat-card"><div class="stat-label">Rejected</div><div class="stat-value" style="color:#94a3b8;"><?= count($rejected) ?></div></div>
</div>

<?php if (count($pending) > 0): ?>
<div style="display:flex;gap:8px;margin-bottom:14px;justify-content:flex-end;">
    <button onclick="bulkAction('approve_all')" class="btn btn-sm" style="background:#10b981;color:#fff;border:none;">Approve All Pending</button>
    <button onclick="bulkAction('reject_all')" class="btn btn-sm" style="background:transparent;color:#dc2626;border:1px solid #dc2626;">Reject All Pending</button>
</div>
<?php endif; ?>

<?php if (empty($all)): ?>
<div class="card"><div style="padding:20px;text-align:center;color:#94a3b8;">
    <p>No SEO proposals yet for this site. Run an SEO audit to generate proposals.</p>
</div></div>
<?php endif; ?>

<?php foreach ($all as $row): ?>
<?php $is_pending = $row['status'] === 'pending'; $rid = (int)$row['id']; ?>
<div class="card" style="margin-bottom:10px;border-left:4px solid <?= $row['status'] === 'pending' ? '#f59e0b' : ($row['status'] === 'approved' ? '#10b981' : '#94a3b8') ?>;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div style="flex:1;min-width:0;">
            <div style="font-weight:600;font-size:13px;color:var(--primary);"><?= e($row['url_path']) ?></div>
            <div style="font-size:11px;color:#94a3b8;">
                Status: <span style="font-weight:600;text-transform:uppercase;color:<?= $row['status'] === 'pending' ? '#f59e0b' : ($row['status'] === 'approved' ? '#10b981' : '#94a3b8') ?>;"><?= $row['status'] ?></span>
                <?php if ($row['reviewed_at']): ?>
                · reviewed <?= format_date($row['reviewed_at'], 'd M Y H:i') ?>
                <?php endif; ?>
            </div>
        </div>
        <a href="https://<?= e($site['domain']) ?><?= e($row['url_path']) ?>" target="_blank" style="font-size:11px;color:var(--primary);text-decoration:none;">View page →</a>
    </div>

    <form id="form-<?= $rid ?>" onsubmit="return false;">
        <div style="padding:12px;">
            <div class="form-group" style="margin-bottom:10px;">
                <label style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Page Title (max 60 chars)</label>
                <input type="text" name="meta_title" maxlength="70" class="form-control" value="<?= e($row['meta_title'] ?? '') ?>" <?= $is_pending ? '' : 'disabled' ?> style="font-size:13px;">
            </div>
            <div class="form-group" style="margin-bottom:10px;">
                <label style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Meta Description (max 160 chars)</label>
                <textarea name="meta_description" maxlength="200" class="form-control" rows="2" <?= $is_pending ? '' : 'disabled' ?> style="font-size:13px;"><?= e($row['meta_description'] ?? '') ?></textarea>
            </div>
            <div class="grid-2" style="gap:10px;">
                <div class="form-group" style="margin-bottom:10px;">
                    <label style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">OG Title (social share)</label>
                    <input type="text" name="og_title" class="form-control" value="<?= e($row['og_title'] ?? '') ?>" <?= $is_pending ? '' : 'disabled' ?> style="font-size:13px;">
                </div>
                <div class="form-group" style="margin-bottom:10px;">
                    <label style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">OG Image URL</label>
                    <input type="text" name="og_image" class="form-control" value="<?= e($row['og_image'] ?? '') ?>" <?= $is_pending ? '' : 'disabled' ?> style="font-size:13px;">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">OG Description</label>
                <textarea name="og_description" class="form-control" rows="2" <?= $is_pending ? '' : 'disabled' ?> style="font-size:13px;"><?= e($row['og_description'] ?? '') ?></textarea>
            </div>
        </div>

        <?php if ($is_pending): ?>
        <div style="padding:8px 12px;background:#f8fafc;display:flex;gap:6px;justify-content:flex-end;border-top:1px solid var(--border);">
            <button type="button" onclick="approveRow(<?= $rid ?>)" class="btn btn-sm" style="background:#10b981;color:#fff;border:none;">Approve as-is</button>
            <button type="button" onclick="editApproveRow(<?= $rid ?>)" class="btn btn-sm btn-accent">Edit & Approve</button>
            <button type="button" onclick="rejectRow(<?= $rid ?>)" class="btn btn-sm" style="background:transparent;color:#dc2626;border:1px solid #dc2626;">Reject</button>
        </div>
        <?php endif; ?>
    </form>
</div>
<?php endforeach; ?>

<script>
const API = '<?= url('/api/seo-approval.php') ?>';
const SITE_ID = <?= $site_id ?>;

async function call(body) {
    try {
        const res = await fetch(API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        });
        const data = await res.json();
        if (data.success) location.reload();
        else alert('Failed: ' + (data.error || 'unknown'));
    } catch(e) { alert('Error: ' + e.message); }
}

function approveRow(id)  { if (confirm('Approve this proposal as-is? It will go live on the website.')) call({action: 'approve', id}); }
function rejectRow(id)   { if (confirm('Reject this proposal? Nothing from it will go live.'))  call({action: 'reject', id}); }
function editApproveRow(id) {
    const form = document.getElementById('form-' + id);
    const data = {action: 'edit_approve', id};
    new FormData(form).forEach((v, k) => data[k] = v);
    if (!confirm('Save your edits and approve? This goes live immediately.')) return;
    call(data);
}
function bulkAction(action) {
    const word = action === 'approve_all' ? 'approve' : 'reject';
    if (!confirm('Are you sure you want to ' + word + ' ALL pending proposals at once?')) return;
    call({action, site_id: SITE_ID});
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
