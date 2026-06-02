<?php
/**
 * Legal document review — inspect the Claude-generated draft + approve or publish.
 *
 * Day 1: read-only preview (title + jurisdictions + rendered body + raw HTML toggle).
 * Future: inline edit for material tweaks (rare; defer).
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/legal_docs.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';

$site_id  = (int)($_GET['site'] ?? 0);
$doc_type = (string)($_GET['type'] ?? '');

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(403); exit('Access denied'); }
if (!in_array($doc_type, ['privacy','terms','cookies','refund','disclaimer'], true)) {
    exit('Unknown doc type');
}

$stmt = $db->prepare("SELECT * FROM legal_docs WHERE site_id = ? AND doc_type = ?");
$stmt->execute([$site_id, $doc_type]);
$doc = $stmt->fetch();
if (!$doc) {
    header('Location: ' . url('/dashboard/legal.php?site=' . $site_id));
    exit;
}

$doc_meta = [
    'privacy'    => ['icon' => '🔐', 'name' => 'Privacy Policy'],
    'terms'      => ['icon' => '📜', 'name' => 'Terms of Service'],
    'cookies'    => ['icon' => '🍪', 'name' => 'Cookie Policy'],
    'refund'     => ['icon' => '💸', 'name' => 'Refund Policy'],
    'disclaimer' => ['icon' => '⚖️', 'name' => 'Disclaimer'],
];
$meta = $doc_meta[$doc_type] ?? ['icon' => '📄', 'name' => ucfirst($doc_type)];
$jurisdictions = json_decode($doc['jurisdictions'] ?? '[]', true) ?: [];

$page_title = $meta['name'] . ' draft — ' . $site['name'];
$stepper_active = 'grow';
ob_start();
include __DIR__ . '/_site_stepper.php';
?>

<style>
.le-head { background:#fff; border:1px solid var(--border); border-radius:8px; padding:12px 14px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.le-head .left { display:flex; align-items:center; gap:10px; }
.le-head .icon { font-size:20px; }
.le-head h1 { margin:0; font-size:15px; color:var(--primary); font-weight:600; }
.le-head .sub { font-size:11px; color:var(--text-light); margin-top:2px; }
.le-actions { display:flex; gap:6px; flex-wrap:wrap; }
.le-btn { font-size:12px; font-weight:600; padding:8px 14px; border-radius:6px; border:0; cursor:pointer; text-decoration:none; }
.le-btn.primary  { background:var(--accent); color:#fff; }
.le-btn.outline  { background:#fff; color:var(--primary); border:1px solid var(--border); }
.le-btn.danger   { background:#fff; color:#dc2626; border:1px solid #fecaca; }
.le-meta { background:#f8fafb; border:1px solid var(--border); border-radius:8px; padding:12px 16px; margin-bottom:12px; font-size:12px; color:#475569; display:flex; gap:18px; flex-wrap:wrap; }
.le-meta strong { color:#0f172a; }
.le-body { background:#fff; border:1px solid var(--border); border-radius:8px; padding:18px 22px; font-size:14px; line-height:1.6; color:#1e293b; max-height:680px; overflow-y:auto; }
.le-body h1, .le-body h2, .le-body h3 { font-weight:600; color:#0f172a; margin:1.3em 0 0.4em; line-height:1.3; }
.le-body h1 { font-size:18px; } .le-body h2 { font-size:15px; } .le-body h3 { font-size:13px; }
.le-body h1:first-child, .le-body h2:first-child, .le-body h3:first-child { margin-top:0; }
.le-body p, .le-body li { margin:0.6em 0; }
.le-body ul, .le-body ol { padding-left:1.6em; }
.le-body strong { color:#0f172a; font-weight:600; }
.le-body a { color:#0891b2; }
.le-body table { border-collapse:collapse; width:100%; margin:1em 0; }
.le-body th, .le-body td { border:1px solid #e2e8f0; padding:6px 10px; text-align:left; font-size:13px; }
.le-body th { background:#f1f5f9; font-weight:600; }
.le-html { display:none; margin-top:8px; }
.le-html textarea { width:100%; height:420px; font-family:ui-monospace,monospace; font-size:12px; padding:12px; border:1px solid var(--border); border-radius:6px; }
.le-footer { margin-top:14px; font-size:11px; color:#94a3b8; }
</style>

<div class="le-head">
    <div class="left">
        <span class="icon"><?= $meta['icon'] ?></span>
        <div>
            <h1><?= e($doc['title'] ?: $meta['name']) ?></h1>
            <div class="sub">
                Status: <strong style="color:#0f172a;"><?= e(ucfirst($doc['status'])) ?></strong>
                <?php if (!empty($doc['generated_at'])): ?> · Generated <?= e(format_date($doc['generated_at'])) ?><?php endif; ?>
                <?php if (!empty($doc['published_at'])): ?> · Published <?= e(format_date($doc['published_at'])) ?><?php endif; ?>
                · Version <?= (int)$doc['version'] ?>
            </div>
        </div>
    </div>
    <div class="le-actions">
        <a class="le-btn outline" href="<?= url('/dashboard/legal.php?site=' . $site_id) ?>">← Back to all docs</a>
        <?php if ($doc['status'] === 'drafted'): ?>
            <button class="le-btn primary" onclick="publishDoc()">✓ Publish to your website →</button>
        <?php elseif ($doc['status'] === 'published'): ?>
            <a class="le-btn outline" href="<?= e($doc['published_url']) ?>" target="_blank">View live ↗</a>
            <button class="le-btn outline" onclick="regenerateDoc()">🔄 Regenerate</button>
        <?php endif; ?>
        <button class="le-btn danger" onclick="discardDoc()">Discard draft</button>
    </div>
</div>

<div class="le-meta">
    <span>📌 <strong>Type:</strong> <?= e($meta['name']) ?></span>
    <span>🌍 <strong>Jurisdictions covered:</strong> <?= e(implode(' · ', $jurisdictions)) ?></span>
    <span>🔗 <strong>Will publish at:</strong> /<?= e($doc['slug']) ?></span>
    <span>📝 <strong>Word count:</strong> <?= number_format(str_word_count(strip_tags($doc['body_html'] ?? ''))) ?></span>
</div>

<div class="le-body">
    <?= $doc['body_html'] ?? '<p style="color:#94a3b8;">No content yet.</p>' ?>
</div>

<div class="le-footer">
    <a href="#" onclick="event.preventDefault();document.querySelector('.le-html').style.display='block';this.style.display='none';" style="color:#6366f1;">View raw HTML ⇅</a>
</div>
<div class="le-html">
    <textarea readonly><?= e($doc['body_html'] ?? '') ?></textarea>
</div>

<script>
const SITE_ID  = <?= (int)$site_id ?>;
const DOC_TYPE = <?= json_encode($doc_type) ?>;

async function callAction(payload) {
    const r = await fetch('<?= url('/api/legal-action.php') ?>', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify(payload),
    });
    try { return await r.json(); } catch (e) { return {success:false,error:'Bad response'}; }
}
async function publishDoc() {
    if (!confirm('Publish this document to your live website?')) return;
    const r = await callAction({ action:'publish', site_id:SITE_ID, doc_type:DOC_TYPE });
    if (r.success) { alert('✓ Published at ' + (r.published_url || 'your site')); location.reload(); }
    else alert('Failed: ' + (r.error || 'unknown'));
}
async function regenerateDoc() {
    if (!confirm('Throw away the current draft and generate a fresh one?')) return;
    const r = await callAction({ action:'generate', site_id:SITE_ID, doc_type:DOC_TYPE });
    if (r.success) location.reload();
    else alert('Failed: ' + (r.error || 'unknown'));
}
async function discardDoc() {
    if (!confirm('Discard this draft? It will be removed and the doc will be marked missing again.')) return;
    const r = await callAction({ action:'discard', site_id:SITE_ID, doc_type:DOC_TYPE });
    if (r.success) window.location = '<?= url('/dashboard/legal.php?site=' . $site_id) ?>';
    else alert('Failed: ' + (r.error || 'unknown'));
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
