<?php
/**
 * Dashboard — Image SEO audit.
 *
 * One-button audit + per-image review queue. Each row shows the image
 * thumb, current alt, status (good / needs_alt / weak_alt / no_dims /
 * oversized / broken), and links to the post.
 *
 * The auditor walks every published post on the site (~50ms/img for HEAD
 * checks). For 200 posts × 3 imgs each that's ~30s — runs synchronously
 * for now; convert to background if customers hit timeouts.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/image_auditor.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

$summary = img_audit_site_summary($db, $site_id);

$filter = (string)($_GET['filter'] ?? 'all');
// All column refs must be aliased — posts.site_id collides with image_audits.site_id
// and posts.status collides with image_audits.status. MySQL would throw "ambiguous".
$where = "a.site_id = ? AND a.dismissed_at IS NULL"; $args = [$site_id];
if ($filter === 'needs_alt')  { $where .= " AND a.status = 'needs_alt'"; }
if ($filter === 'weak_alt')   { $where .= " AND a.status = 'weak_alt'"; }
if ($filter === 'oversized')  { $where .= " AND a.status = 'oversized'"; }
if ($filter === 'broken')     { $where .= " AND a.status = 'broken'"; }
if ($filter === 'no_dims')    { $where .= " AND a.status = 'no_dims'"; }
if ($filter === 'good')       { $where .= " AND a.status = 'good'"; }
if ($filter === 'issues')     { $where .= " AND a.status != 'good'"; }

$stmt = $db->prepare("SELECT a.*, p.title AS post_title, p.slug AS post_slug
    FROM image_audits a LEFT JOIN posts p ON p.id = a.post_id
    WHERE {$where} ORDER BY FIELD(a.status, 'broken','oversized','needs_alt','weak_alt','no_dims','good'), a.id DESC
    LIMIT 200");
$stmt->execute($args);
$rows = $stmt->fetchAll();

$page_title = 'Image SEO — ' . $site['name'];
ob_start();
?>
<style>
.is-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:8px; margin-bottom:12px; max-width:980px; }
.is-card { padding:11px 14px; background:#fff; border:1px solid var(--border); border-radius:6px; }
.is-card .lbl { font-size:10px; text-transform:uppercase; letter-spacing:0.4px; color:#94a3b8; }
.is-card .num { font-size:22px; font-weight:700; color:#0f172a; line-height:1; margin-top:3px; }
.is-card .num.good { color:#059669; }
.is-card .num.warn { color:#d97706; }
.is-card .num.bad  { color:#dc2626; }
.is-pills { display:flex; gap:0; border-bottom:1px solid var(--border); margin:14px 0 10px; flex-wrap:wrap; }
.is-pill { padding:7px 12px; font-size:12px; color:var(--text-light); border-bottom:2px solid transparent; text-decoration:none; }
.is-pill.active { color:var(--primary); border-bottom-color:var(--primary); font-weight:600; }
.is-row { background:#fff; border:1px solid var(--border); border-radius:6px; padding:10px 12px; margin-bottom:6px; display:grid; grid-template-columns: 60px 1fr auto auto; gap:12px; align-items:center; max-width:980px; }
.is-row.broken    { border-left:3px solid #dc2626; }
.is-row.oversized { border-left:3px solid #f97316; }
.is-row.needs_alt { border-left:3px solid #d97706; }
.is-row.weak_alt  { border-left:3px solid #fbbf24; }
.is-row.no_dims   { border-left:3px solid #94a3b8; }
.is-row.good      { border-left:3px solid #059669; }
.is-thumb { width:60px; height:45px; object-fit:cover; border-radius:3px; background:#f1f5f9; }
.is-alt   { font-size:12px; color:#0f172a; font-family:ui-monospace, monospace; }
.is-alt .empty { color:#dc2626; }
.is-meta  { font-size:10px; color:#64748b; margin-top:3px; }
.is-meta a { color:var(--primary); text-decoration:none; }
.is-status { font-size:10px; padding:3px 9px; border-radius:10px; font-weight:600; white-space:nowrap; }
.is-status.broken    { background:#fee2e2; color:#991b1b; }
.is-status.oversized { background:#ffedd5; color:#9a3412; }
.is-status.needs_alt { background:#fef3c7; color:#92400e; }
.is-status.weak_alt  { background:#fef9c3; color:#854d0e; }
.is-status.no_dims   { background:#f1f5f9; color:#475569; }
.is-status.good      { background:#d1fae5; color:#065f46; }
.is-empty { color:var(--text-light); font-size:13px; padding:14px; background:#f8fafc; border-radius:6px; border:1px dashed var(--border); }
</style>

<div style="margin-bottom:8px;"><a href="<?= url('/dashboard/site-health.php?site=' . $site_id) ?>" style="font-size:12px;color:var(--primary);text-decoration:none;">&larr; Site Health</a></div>

<div class="setup-section" style="max-width:980px;">
    <h3 style="margin:0 0 3px; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--primary);">Image SEO audit</h3>
    <p class="desc" style="margin:0 0 10px; max-width:720px;">
        Scan every image in your published posts for missing alt text, oversized files, missing dimensions, and broken links.
        Bad images tank LCP + accessibility + Google Image traffic.
    </p>
    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px;">
        <button class="btn btn-accent btn-sm" onclick="runAudit(this)">⚡ Run audit</button>
    </div>
    <div id="is-progress" style="display:none; font-size:12px; color:var(--text-light); padding:8px 10px; background:#f8fafc; border-radius:4px; border:1px dashed var(--border); margin-bottom:10px;"></div>
</div>

<?php if ($summary['total'] > 0): ?>
<div class="is-stats">
    <div class="is-card"><div class="lbl">Images audited</div><div class="num"><?= number_format($summary['total']) ?></div></div>
    <?php
    $good_count = (int)($summary['by_status']['good'] ?? 0);
    $bad_count  = $summary['total'] - $good_count;
    ?>
    <div class="is-card"><div class="lbl">Passing</div><div class="num good"><?= number_format($good_count) ?></div></div>
    <div class="is-card"><div class="lbl">Issues</div><div class="num <?= $bad_count > 0 ? 'bad' : 'good' ?>"><?= number_format($bad_count) ?></div></div>
    <div class="is-card"><div class="lbl">Broken</div><div class="num <?= ($summary['by_status']['broken'] ?? 0) > 0 ? 'bad' : '' ?>"><?= (int)($summary['by_status']['broken'] ?? 0) ?></div></div>
    <div class="is-card"><div class="lbl">Oversized</div><div class="num <?= ($summary['by_status']['oversized'] ?? 0) > 0 ? 'warn' : '' ?>"><?= (int)($summary['by_status']['oversized'] ?? 0) ?></div></div>
</div>

<div class="is-pills">
    <?php
    $tabs = ['all' => 'All', 'issues' => 'Issues', 'needs_alt' => 'Missing alt', 'weak_alt' => 'Weak alt', 'oversized' => 'Oversized', 'no_dims' => 'No dims', 'broken' => 'Broken', 'good' => 'Good'];
    foreach ($tabs as $key => $label):
        $href = url('/dashboard/image-seo.php?site=' . $site_id . '&filter=' . $key);
    ?>
        <a href="<?= $href ?>" class="is-pill <?= $filter === $key ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if (empty($rows)): ?>
    <div class="is-empty">No images match this filter.</div>
<?php else: foreach ($rows as $r): ?>
    <div class="is-row <?= e($r['status']) ?>">
        <img class="is-thumb" src="<?= e($r['image_url']) ?>" loading="lazy" onerror="this.style.opacity='0.3'">
        <div>
            <div class="is-alt">
                <?php if ($r['alt_text'] === null || trim($r['alt_text']) === ''): ?>
                    <span class="empty">(no alt)</span>
                <?php else: ?>
                    "<?= e(mb_substr($r['alt_text'], 0, 80)) ?>"
                <?php endif; ?>
            </div>
            <div class="is-meta">
                <?php if ($r['post_id']): ?>
                    on <a href="<?= url('/dashboard/plan-item.php?id=' . (int)$r['post_id']) ?>"><?= e(mb_substr($r['post_title'] ?? '(untitled)', 0, 60)) ?></a>
                <?php endif; ?>
                <?php if ($r['width'] && $r['height']): ?> · <?= (int)$r['width'] ?>×<?= (int)$r['height'] ?><?php endif; ?>
                <?php if ($r['file_bytes']): ?> · <?= number_format($r['file_bytes'] / 1024, 0) ?>KB<?php endif; ?>
                <?php if ($r['issue_notes']): ?> · <span style="color:#dc2626;"><?= e($r['issue_notes']) ?></span><?php endif; ?>
            </div>
        </div>
        <span class="is-status <?= e($r['status']) ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span>
        <a href="<?= e($r['image_url']) ?>" target="_blank" rel="noopener" style="font-size:11px; color:#475569; text-decoration:none;">open ↗</a>
    </div>
<?php endforeach; endif; ?>

<?php else: ?>
<div class="is-empty">No audit run yet. Click "Run audit" to scan every published post for image SEO issues.</div>
<?php endif; ?>

<script>
const SITE_ID = <?= $site_id ?>;
async function runAudit(btn) {
    btn.disabled = true;
    const prog = document.getElementById('is-progress');
    prog.style.display = 'block';
    prog.innerHTML = 'Auditing images — checking alt text, dimensions, file sizes. This may take a few minutes for big sites.';
    try {
        const res = await fetch('<?= url('/api/image-seo-action.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'run', site_id: SITE_ID })
        });
        const data = await res.json();
        if (!data.success) {
            prog.innerHTML = '<span style="color:#dc2626;">' + (data.error || 'Failed') + '</span>';
            btn.disabled = false;
            return;
        }
        prog.innerHTML = 'Done — scanned ' + data.posts_scanned + ' posts, found ' + data.images_found + ' images. Refreshing…';
        setTimeout(() => location.reload(), 1500);
    } catch (e) {
        prog.innerHTML = '<span style="color:#dc2626;">' + e.message + '</span>';
        btn.disabled = false;
    }
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
