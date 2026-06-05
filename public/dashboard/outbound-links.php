<?php
/**
 * Dashboard — Broken outbound link checker.
 *
 * Lists every external link in your published posts and its current
 * health. Broken outbound links erode trust + SEO. Auto-fix is not
 * possible here — we just surface them so the user can update the
 * post or remove the link.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/outbound_link_checker.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

$summary = outbound_site_summary($db, $site_id);

$filter = (string)($_GET['filter'] ?? 'broken');
$where = "ol.site_id = ? AND ol.dismissed_at IS NULL"; $args = [$site_id];
if ($filter === 'broken')         $where .= " AND ol.status = 'broken'";
if ($filter === 'timeout')        $where .= " AND ol.status = 'timeout'";
if ($filter === 'redirect_chain') $where .= " AND ol.status = 'redirect_chain'";
if ($filter === 'ok')             $where .= " AND ol.status = 'ok'";
if ($filter === 'issues')         $where .= " AND ol.status != 'ok'";

$stmt = $db->prepare("SELECT ol.*, p.title AS post_title
    FROM outbound_links ol LEFT JOIN posts p ON p.id = ol.post_id
    WHERE {$where}
    ORDER BY FIELD(ol.status, 'broken','timeout','redirect_chain','ok'), ol.id DESC
    LIMIT 200");
$stmt->execute($args);
$rows = $stmt->fetchAll();

$page_title = 'Outbound Links — ' . $site['name'];
ob_start();
?>
<style>
.ob-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:8px; margin-bottom:12px; max-width:980px; }
.ob-card { padding:11px 14px; background:#fff; border:1px solid var(--border); border-radius:6px; }
.ob-card .lbl { font-size:10px; text-transform:uppercase; letter-spacing:0.4px; color:#94a3b8; }
.ob-card .num { font-size:22px; font-weight:700; color:#0f172a; line-height:1; margin-top:3px; }
.ob-card .num.good { color:#059669; }
.ob-card .num.bad  { color:#dc2626; }
.ob-pills { display:flex; gap:0; border-bottom:1px solid var(--border); margin:14px 0 10px; flex-wrap:wrap; }
.ob-pill { padding:7px 12px; font-size:12px; color:var(--text-light); border-bottom:2px solid transparent; text-decoration:none; }
.ob-pill.active { color:var(--primary); border-bottom-color:var(--primary); font-weight:600; }
.ob-row { background:#fff; border:1px solid var(--border); border-radius:6px; padding:10px 14px; margin-bottom:6px; display:grid; grid-template-columns: 1fr auto auto; gap:10px; align-items:center; max-width:980px; }
.ob-row.broken         { border-left:3px solid #dc2626; }
.ob-row.timeout        { border-left:3px solid #f97316; }
.ob-row.redirect_chain { border-left:3px solid #fbbf24; }
.ob-row.ok             { border-left:3px solid #059669; }
.ob-url { font-family:ui-monospace, monospace; font-size:12px; color:#0f172a; word-break:break-all; }
.ob-meta { font-size:10px; color:#64748b; margin-top:3px; }
.ob-meta a { color:var(--primary); text-decoration:none; }
.ob-status { font-size:10px; padding:3px 9px; border-radius:10px; font-weight:600; white-space:nowrap; }
.ob-status.broken         { background:#fee2e2; color:#991b1b; }
.ob-status.timeout        { background:#ffedd5; color:#9a3412; }
.ob-status.redirect_chain { background:#fef3c7; color:#92400e; }
.ob-status.ok             { background:#d1fae5; color:#065f46; }
.ob-empty { color:var(--text-light); font-size:13px; padding:14px; background:#f8fafc; border-radius:6px; border:1px dashed var(--border); }
</style>

<div style="margin-bottom:8px;"><a href="<?= url('/dashboard/site-health.php?site=' . $site_id) ?>" style="font-size:12px;color:var(--primary);text-decoration:none;">&larr; Site Health</a></div>

<div class="setup-section" style="max-width:980px;">
    <h3 style="margin:0 0 3px; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--primary);">Outbound links</h3>
    <p class="desc" style="margin:0 0 10px; max-width:720px;">
        Every link in your posts that points to another site. Broken outbound links erode reader trust + page SEO.
        We can't fix them automatically (they're someone else's site) — we surface them so you can update or remove.
    </p>
    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px;">
        <button class="btn btn-accent btn-sm" onclick="runCheck(this)">⚡ Check all outbound links</button>
    </div>
    <div id="ob-progress" style="display:none; font-size:12px; color:var(--text-light); padding:8px 10px; background:#f8fafc; border-radius:4px; border:1px dashed var(--border); margin-bottom:10px;"></div>
</div>

<?php if ($summary['total'] > 0): ?>
<div class="ob-stats">
    <div class="ob-card"><div class="lbl">Links checked</div><div class="num"><?= number_format($summary['total']) ?></div></div>
    <div class="ob-card"><div class="lbl">Healthy</div><div class="num good"><?= (int)($summary['by_status']['ok'] ?? 0) ?></div></div>
    <div class="ob-card"><div class="lbl">Broken</div><div class="num <?= ($summary['by_status']['broken'] ?? 0) > 0 ? 'bad' : '' ?>"><?= (int)($summary['by_status']['broken'] ?? 0) ?></div></div>
    <div class="ob-card"><div class="lbl">Timeout</div><div class="num"><?= (int)($summary['by_status']['timeout'] ?? 0) ?></div></div>
    <div class="ob-card"><div class="lbl">Redirect chains</div><div class="num"><?= (int)($summary['by_status']['redirect_chain'] ?? 0) ?></div></div>
</div>

<div class="ob-pills">
    <?php
    $tabs = ['broken' => 'Broken', 'issues' => 'All issues', 'timeout' => 'Timeout', 'redirect_chain' => 'Redirect chains', 'ok' => 'Healthy', 'all' => 'All'];
    foreach ($tabs as $key => $label):
        $href = url('/dashboard/outbound-links.php?site=' . $site_id . '&filter=' . $key);
    ?>
        <a href="<?= $href ?>" class="ob-pill <?= $filter === $key ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if (empty($rows)): ?>
    <div class="ob-empty">No links match this filter.</div>
<?php else: foreach ($rows as $r): ?>
    <div class="ob-row <?= e($r['status']) ?>">
        <div>
            <div class="ob-url"><?= e(mb_substr($r['url'], 0, 120)) ?></div>
            <div class="ob-meta">
                <?php if ($r['anchor_text']): ?>"<?= e(mb_substr($r['anchor_text'], 0, 80)) ?>"<?php endif; ?>
                <?php if ($r['post_id']): ?> · on <a href="<?= url('/dashboard/plan-item.php?id=' . (int)$r['post_id']) ?>"><?= e(mb_substr($r['post_title'] ?? '(untitled)', 0, 50)) ?></a><?php endif; ?>
                <?php if ($r['http_code']): ?> · HTTP <?= (int)$r['http_code'] ?><?php endif; ?>
                <?php if ($r['redirect_count'] > 0): ?> · <?= (int)$r['redirect_count'] ?> redirects<?php endif; ?>
            </div>
        </div>
        <span class="ob-status <?= e($r['status']) ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span>
        <a href="<?= e($r['url']) ?>" target="_blank" rel="noopener nofollow" style="font-size:11px; color:#475569; text-decoration:none;">open ↗</a>
    </div>
<?php endforeach; endif; ?>

<?php else: ?>
<div class="ob-empty">No checks yet. Click "Check all outbound links" to scan every published post.</div>
<?php endif; ?>

<script>
const SITE_ID = <?= $site_id ?>;
async function runCheck(btn) {
    btn.disabled = true;
    const prog = document.getElementById('ob-progress');
    prog.style.display = 'block';
    prog.innerHTML = 'Checking outbound links… HEAD-pinging each external URL. ~8s timeout per link.';
    try {
        const res = await fetch('<?= url('/api/outbound-links-action.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'run', site_id: SITE_ID })
        });
        const data = await res.json();
        if (!data.success) {
            prog.innerHTML = '<span style="color:#dc2626;">' + (data.error || 'Failed') + '</span>';
            btn.disabled = false;
            return;
        }
        prog.innerHTML = 'Done — ' + data.posts_scanned + ' posts, ' + data.links_found + ' outbound links. Refreshing…';
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
