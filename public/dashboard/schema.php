<?php
/**
 * Dashboard — Schema Audit.
 *
 * Per-site page: shows every URL ContentAgent expected JSON-LD on, and
 * whether the schema is still there. Filters by status (broken / degraded
 * / fetch_failed / ok). Run button kicks off a fresh audit.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/schema_auditor.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

$summary = sch_site_summary($db, $site_id);

$filter = $_GET['filter'] ?? 'all';
$where = "site_id = ?"; $args = [$site_id];
if (in_array($filter, ['ok', 'degraded', 'broken', 'fetch_failed'], true)) {
    $where .= " AND last_status = ?"; $args[] = $filter;
}
$stmt = $db->prepare("SELECT id, url, expected_types, found_types, missing_types, block_count, last_status, last_checked_at
                      FROM schema_audits WHERE {$where}
                      ORDER BY FIELD(last_status, 'broken','fetch_failed','degraded','ok'), last_checked_at DESC LIMIT 200");
$stmt->execute($args);
$audits = $stmt->fetchAll();

$page_title = 'Structured Data Audit — ' . $site['name'];
ob_start();
?>
<style>
.sch-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-bottom:14px; }
.sch-card { background:#fff; border:1px solid var(--border); border-radius:6px; padding:13px 14px; }
.sch-card .label { font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-light); margin-bottom:4px; }
.sch-card .num { font-size:26px; font-weight:700; line-height:1; }
.sch-card .num.good { color:#059669; }
.sch-card .num.warn { color:#d97706; }
.sch-card .num.bad  { color:#dc2626; }
.sch-card .sub { font-size:11px; color:var(--text-light); margin-top:5px; }
.sch-pills { display:flex; gap:0; border-bottom:1px solid var(--border); margin:14px 0 10px; flex-wrap:wrap; }
.sch-pill { padding:7px 14px; font-size:12px; color:var(--text-light); border-bottom:2px solid transparent; text-decoration:none; }
.sch-pill.active { color:var(--primary); border-bottom-color:var(--primary); font-weight:600; }
.sch-row { background:#fff; border:1px solid var(--border); border-radius:6px; padding:10px 14px; margin-bottom:6px; }
.sch-row.broken       { border-left:3px solid #dc2626; }
.sch-row.degraded     { border-left:3px solid #d97706; }
.sch-row.fetch_failed { border-left:3px solid #6366f1; }
.sch-row.ok           { border-left:3px solid #059669; }
.sch-url { font-family:ui-monospace, monospace; font-size:12px; color:#475569; }
.sch-meta { font-size:11px; color:var(--text-light); margin-top:4px; }
.sch-chip { font-size:10px; padding:2px 7px; border-radius:10px; display:inline-block; margin:0 4px 2px 0; }
.sch-chip.found { background:#d1fae5; color:#065f46; }
.sch-chip.missing { background:#fee2e2; color:#991b1b; }
.sch-status { font-size:10px; padding:2px 8px; border-radius:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.4px; }
.sch-status.broken { background:#fee2e2; color:#991b1b; }
.sch-status.degraded { background:#fef3c7; color:#92400e; }
.sch-status.fetch_failed { background:#e0e7ff; color:#3730a3; }
.sch-status.ok { background:#d1fae5; color:#065f46; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/seo.php?site=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">← Back to SEO</a>
</div>

<div class="setup-section" style="max-width:980px;">
    <h3 style="margin:0 0 3px; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--primary);">Structured data audit</h3>
    <p class="desc" style="margin:0 0 8px; max-width:720px;">
        ContentAgent emits Schema.org JSON-LD (Article, FAQ, Breadcrumbs) on every published post.
        This audit fetches the live page and verifies the schema is actually there — catches
        theme bugs or plugin conflicts that silently strip JSON-LD.
    </p>
    <button class="btn btn-accent btn-sm" onclick="runAudit(this)">↻ Audit now</button>
    <div id="sch-progress" style="display:none; font-size:12px; color:var(--text-light); margin-top:8px; padding:8px 10px; background:#f8fafc; border-radius:4px; border:1px dashed var(--border);"></div>
</div>

<div class="sch-stats" style="max-width:980px;">
    <div class="sch-card">
        <div class="label">Pages tracked</div>
        <div class="num"><?= number_format($summary['total']) ?></div>
        <div class="sub"><?= $summary['last_run'] ? 'Last run ' . date('d M H:i', strtotime($summary['last_run'])) : 'Not yet run' ?></div>
    </div>
    <div class="sch-card">
        <div class="label">All schema intact</div>
        <div class="num good"><?= (int)($summary['by_status']['ok'] ?? 0) ?></div>
        <div class="sub">JSON-LD present + complete</div>
    </div>
    <div class="sch-card">
        <div class="label">Some missing</div>
        <div class="num warn"><?= (int)($summary['by_status']['degraded'] ?? 0) ?></div>
        <div class="sub">Partial — investigate</div>
    </div>
    <div class="sch-card">
        <div class="label">Broken (no JSON-LD)</div>
        <div class="num bad"><?= (int)($summary['by_status']['broken'] ?? 0) ?></div>
        <div class="sub">Schema completely stripped</div>
    </div>
    <div class="sch-card">
        <div class="label">Couldn't fetch</div>
        <div class="num"><?= (int)($summary['by_status']['fetch_failed'] ?? 0) ?></div>
        <div class="sub">404/timeout/error</div>
    </div>
</div>

<div class="sch-pills" style="max-width:980px;">
    <a class="sch-pill <?= $filter === 'all' ? 'active' : '' ?>" href="?site=<?= $site_id ?>">All (<?= $summary['total'] ?>)</a>
    <a class="sch-pill <?= $filter === 'broken' ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=broken">Broken (<?= (int)($summary['by_status']['broken'] ?? 0) ?>)</a>
    <a class="sch-pill <?= $filter === 'degraded' ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=degraded">Degraded (<?= (int)($summary['by_status']['degraded'] ?? 0) ?>)</a>
    <a class="sch-pill <?= $filter === 'fetch_failed' ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=fetch_failed">Fetch failed (<?= (int)($summary['by_status']['fetch_failed'] ?? 0) ?>)</a>
    <a class="sch-pill <?= $filter === 'ok' ? 'active' : '' ?>" href="?site=<?= $site_id ?>&filter=ok">OK (<?= (int)($summary['by_status']['ok'] ?? 0) ?>)</a>
</div>

<div style="max-width:980px;">
    <?php if (empty($audits)): ?>
        <div style="color:var(--text-light); font-size:13px; padding:16px; background:#f8fafc; border-radius:6px; border:1px dashed var(--border);">
            <?php if ($summary['total'] === 0): ?>
                No pages tracked yet. Click <strong>Audit now</strong> — we'll auto-register every published post on this site and check each one's schema.
            <?php else: ?>
                Nothing matches this filter.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($audits as $a):
            $expected = json_decode($a['expected_types'] ?? '[]', true) ?: [];
            $found    = json_decode($a['found_types'] ?? '[]', true) ?: [];
            $missing  = json_decode($a['missing_types'] ?? '[]', true) ?: [];
            $found_set = array_flip($found);
        ?>
        <div class="sch-row <?= e($a['last_status']) ?>">
            <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                <div style="flex:1; min-width:0;">
                    <div class="sch-url"><?= e(parse_url($a['url'], PHP_URL_PATH) ?: $a['url']) ?></div>
                    <div class="sch-meta">
                        <?php foreach ($expected as $t):
                            $is_found = isset($found_set[$t]);
                        ?>
                            <span class="sch-chip <?= $is_found ? 'found' : 'missing' ?>">
                                <?= $is_found ? '✓' : '✗' ?> <?= e($t) ?>
                            </span>
                        <?php endforeach; ?>
                        <span style="margin-left:6px;"><?= (int)$a['block_count'] ?> JSON-LD block<?= $a['block_count'] == 1 ? '' : 's' ?> found</span>
                        <?php if ($a['last_checked_at']): ?>
                            · checked <?= e(date('d M H:i', strtotime($a['last_checked_at']))) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="sch-status <?= e($a['last_status']) ?>"><?= e(str_replace('_', ' ', $a['last_status'])) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
const SITE_ID = <?= $site_id ?>;
async function runAudit(btn) {
    btn.disabled = true;
    const prog = document.getElementById('sch-progress');
    prog.style.display = 'block';
    prog.innerHTML = 'Auditing — fetches each tracked URL and parses JSON-LD blocks. Page will refresh on completion.';
    try {
        const res = await fetch('<?= url('/api/schema-action.php') ?>', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'run', site_id: SITE_ID})
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || ('HTTP ' + res.status));
        setTimeout(() => window.location.reload(), 60000);
    } catch (e) { prog.innerHTML = '<span style="color:#dc2626;">' + e.message + '</span>'; btn.disabled = false; }
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
