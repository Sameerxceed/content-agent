<?php
/**
 * Dashboard — Google Merchant Center diagnostics.
 *
 * Per-product issue surface from the GMC Content API. Lists products with
 * unresolved issues sorted by severity, with the issue codes + descriptions
 * Google returned for each.
 *
 * Requires: Google OAuth connected (same integration as GSC, scope
 * https://www.googleapis.com/auth/content), and a merchant_id set.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/gmc_api.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

// Is Google OAuth connected at all?
$stmt = $db->prepare("SELECT id, account_id FROM integrations
    WHERE site_id = ? AND platform = 'google_search_console' AND is_active = 1");
$stmt->execute([$site_id]);
$google = $stmt->fetch();

// merchant_id is stored alongside the GSC integration in a JSON field in
// settings (sites.notes). Could also be a separate integrations row if
// merchant + GSC are distinct accounts.
$notes = json_decode($site['notes'] ?? '{}', true) ?: [];
$merchant_id = (string)($notes['gmc_merchant_id'] ?? '');

$summary = $merchant_id ? gmc_site_summary($db, $site_id) : null;

$filter = (string)($_GET['filter'] ?? 'with_issues');
$rows = [];
if ($merchant_id) {
    $where = "p.site_id = ? AND p.merchant_id = ?"; $args = [$site_id, $merchant_id];
    if ($filter === 'with_issues') $where .= " AND p.issue_count > 0";
    if ($filter === 'clean')       $where .= " AND p.issue_count = 0";

    $stmt = $db->prepare("SELECT p.* FROM gmc_products p
        WHERE {$where}
        ORDER BY p.issue_count DESC, p.id
        LIMIT 100");
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
}

$page_title = 'Merchant Center — ' . $site['name'];
ob_start();
?>
<style>
.gmc-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:8px; margin-bottom:12px; max-width:980px; }
.gmc-card { padding:11px 14px; background:#fff; border:1px solid var(--border); border-radius:6px; }
.gmc-card .lbl { font-size:10px; text-transform:uppercase; letter-spacing:0.4px; color:#94a3b8; }
.gmc-card .num { font-size:22px; font-weight:700; color:#0f172a; line-height:1; margin-top:3px; }
.gmc-card .num.bad { color:#dc2626; } .gmc-card .num.warn { color:#d97706; } .gmc-card .num.good { color:#059669; }
.gmc-row { background:#fff; border:1px solid var(--border); border-radius:6px; padding:10px 14px; margin-bottom:6px; max-width:980px; }
.gmc-row .top { display:grid; grid-template-columns: 50px 1fr auto; gap:12px; align-items:center; }
.gmc-row img { width:50px; height:50px; object-fit:cover; border-radius:3px; background:#f1f5f9; }
.gmc-row .title { font-size:13px; font-weight:600; color:#0f172a; }
.gmc-row .meta { font-size:11px; color:#64748b; margin-top:2px; }
.gmc-row .meta code { font-size:10px; }
.gmc-row .badge { font-size:10px; padding:3px 9px; border-radius:10px; background:#fee2e2; color:#991b1b; font-weight:600; white-space:nowrap; }
.gmc-row .badge.good { background:#d1fae5; color:#065f46; }
.gmc-issues { margin-top:8px; padding-top:8px; border-top:1px solid #f1f5f9; display:flex; flex-direction:column; gap:5px; }
.gmc-issue { font-size:11px; display:grid; grid-template-columns: 60px 1fr; gap:8px; line-height:1.45; }
.gmc-issue .sev { font-size:9px; padding:1px 6px; border-radius:10px; font-weight:600; text-transform:uppercase; height:14px; align-self:start; text-align:center; }
.gmc-issue .sev.error      { background:#fee2e2; color:#991b1b; }
.gmc-issue .sev.warning    { background:#fef3c7; color:#92400e; }
.gmc-issue .sev.suggestion { background:#f1f5f9; color:#475569; }
.gmc-issue .desc { color:#0f172a; }
.gmc-issue .code { font-family:ui-monospace,monospace; color:#94a3b8; font-size:10px; }
.gmc-empty { color:var(--text-light); font-size:13px; padding:14px; background:#f8fafc; border-radius:6px; border:1px dashed var(--border); max-width:980px; }
.gmc-setup { padding:14px 16px; background:#fef9c3; border:1px solid #fde68a; border-radius:8px; max-width:760px; font-size:13px; color:#713f12; line-height:1.6; }
.gmc-setup ol { margin:8px 0; padding-left:18px; }
.gmc-pills { display:flex; gap:0; border-bottom:1px solid var(--border); margin:14px 0 10px; }
.gmc-pill { padding:7px 12px; font-size:12px; color:var(--text-light); border-bottom:2px solid transparent; text-decoration:none; }
.gmc-pill.active { color:var(--primary); border-bottom-color:var(--primary); font-weight:600; }
</style>

<div style="margin-bottom:8px;"><a href="<?= url('/dashboard/site-health.php?site=' . $site_id) ?>" style="font-size:12px;color:var(--primary);text-decoration:none;">&larr; Site Health</a></div>

<div class="setup-section" style="max-width:980px;">
    <h3 style="margin:0 0 3px; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--primary);">Merchant Center diagnostics</h3>
    <p class="desc" style="margin:0 0 10px; max-width:720px;">
        Per-product issues from Google Merchant Center (missing GTIN, invalid prices, image rejections, policy violations).
        See which products are disapproved, why, and link straight to the product in your store to fix.
    </p>
</div>

<?php if (!$google): ?>
    <div class="gmc-setup">
        <strong>Connect Google first.</strong>
        Go to <a href="<?= url('/dashboard/integrations.php') ?>" style="color:#7c3aed;">Integrations</a> and connect Google.
        The same OAuth flow grants both Search Console (Track tab) and Merchant Center (this page) access — one click, two products covered.
    </div>
<?php elseif (!$merchant_id): ?>
    <div class="gmc-setup">
        <strong>Set your Merchant Center ID.</strong>
        <ol>
            <li>Open <a href="https://merchants.google.com/" target="_blank" rel="noopener" style="color:#7c3aed;">merchants.google.com</a> and copy the Merchant ID from the top-right of the screen.</li>
            <li>Save it below — we'll start the first sync immediately. Subsequent syncs run nightly via cron.</li>
        </ol>
        <form method="post" action="<?= url('/api/gmc-action.php') ?>" style="margin-top:12px; display:flex; gap:8px; align-items:center;">
            <input type="hidden" name="action" value="set_merchant_id">
            <input type="hidden" name="site_id" value="<?= $site_id ?>">
            <input type="text" name="merchant_id" placeholder="123456789" required pattern="[0-9]+" style="padding:7px 10px; border:1px solid var(--border); border-radius:4px; font-family:ui-monospace,monospace; flex:1; max-width:240px;">
            <button class="btn btn-primary btn-sm" type="submit">Save + first sync</button>
        </form>
    </div>
<?php else: ?>

    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px;">
        <button class="btn btn-accent btn-sm" onclick="runAudit(this)">⚡ Re-sync products + diagnostics</button>
        <span style="font-size:11px; color:var(--text-light); padding:6px 4px;">
            Merchant <code><?= e($merchant_id) ?></code>
            <?php if ($summary['last_fetched']): ?> · last synced <?= e(date('d M H:i', strtotime($summary['last_fetched']))) ?><?php endif; ?>
        </span>
    </div>
    <div id="gmc-progress" style="display:none; font-size:12px; color:var(--text-light); padding:8px 10px; background:#f8fafc; border-radius:4px; border:1px dashed var(--border); margin-bottom:10px;"></div>

    <?php if ($summary['products'] > 0): ?>
    <div class="gmc-stats">
        <div class="gmc-card"><div class="lbl">Products synced</div><div class="num"><?= number_format($summary['products']) ?></div></div>
        <div class="gmc-card"><div class="lbl">With issues</div><div class="num <?= $summary['with_issues'] > 0 ? 'warn' : 'good' ?>"><?= number_format($summary['with_issues']) ?></div></div>
        <div class="gmc-card"><div class="lbl">Errors</div><div class="num <?= $summary['errors'] > 0 ? 'bad' : '' ?>"><?= number_format($summary['errors']) ?></div></div>
        <div class="gmc-card"><div class="lbl">Warnings</div><div class="num <?= $summary['warnings'] > 0 ? 'warn' : '' ?>"><?= number_format($summary['warnings']) ?></div></div>
        <div class="gmc-card"><div class="lbl">Suggestions</div><div class="num"><?= number_format($summary['suggestions']) ?></div></div>
    </div>

    <div class="gmc-pills">
        <?php $tabs = ['with_issues' => 'With issues', 'clean' => 'Clean', 'all' => 'All']; foreach ($tabs as $key => $label):
            $href = url('/dashboard/gmc.php?site=' . $site_id . '&filter=' . $key); ?>
            <a href="<?= $href ?>" class="gmc-pill <?= $filter === $key ? 'active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <?php
    // Pre-fetch issues for the displayed products in one query.
    $issues_by_product = [];
    if ($rows) {
        $ids = array_column($rows, 'product_id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM gmc_issues
            WHERE site_id = ? AND merchant_id = ? AND product_id IN ({$placeholders}) AND resolved_at IS NULL
            ORDER BY FIELD(severity, 'error','warning','suggestion'), id");
        $stmt->execute(array_merge([$site_id, $merchant_id], $ids));
        foreach ($stmt->fetchAll() as $iss) {
            $issues_by_product[$iss['product_id']][] = $iss;
        }
    }
    ?>

    <?php if (empty($rows)): ?>
        <div class="gmc-empty">No products match this filter.</div>
    <?php else: foreach ($rows as $p): $issues = $issues_by_product[$p['product_id']] ?? []; ?>
        <div class="gmc-row">
            <div class="top">
                <img src="<?= e($p['image_link'] ?? '') ?>" loading="lazy" onerror="this.style.opacity='0.3'">
                <div>
                    <div class="title"><?= e(mb_substr($p['title'] ?? '(no title)', 0, 90)) ?></div>
                    <div class="meta">
                        <code><?= e($p['product_id']) ?></code>
                        <?php if ($p['price']): ?> · <?= e($p['price']) ?><?php endif; ?>
                        <?php if ($p['availability']): ?> · <?= e($p['availability']) ?><?php endif; ?>
                        <?php if ($p['link']): ?> · <a href="<?= e($p['link']) ?>" target="_blank" rel="noopener" style="color:#7c3aed;">view live</a><?php endif; ?>
                    </div>
                </div>
                <span class="badge <?= (int)$p['issue_count'] === 0 ? 'good' : '' ?>">
                    <?= (int)$p['issue_count'] === 0 ? 'OK' : (int)$p['issue_count'] . ' issue' . ((int)$p['issue_count'] === 1 ? '' : 's') ?>
                </span>
            </div>
            <?php if (!empty($issues)): ?>
            <div class="gmc-issues">
                <?php foreach ($issues as $iss): ?>
                <div class="gmc-issue">
                    <span class="sev <?= e($iss['severity']) ?>"><?= e($iss['severity']) ?></span>
                    <div>
                        <div class="desc"><?= e($iss['description'] ?? $iss['issue_code']) ?></div>
                        <div class="code"><?= e($iss['issue_code']) ?><?php if ($iss['destination']): ?> · <?= e($iss['destination']) ?><?php endif; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div style="margin-top:6px; display:flex; gap:6px; align-items:center;">
                    <button class="btn btn-outline btn-sm" style="font-size:11px; padding:4px 10px;" onclick="suggestFix(this, '<?= e($p['product_id']) ?>')">&#129504; Suggest fix with AI</button>
                    <span style="font-size:10px; color:#94a3b8;">Claude reads the product + issues and proposes corrected fields</span>
                </div>
                <div class="gmc-fix-result" data-pid="<?= e($p['product_id']) ?>" style="display:none; margin-top:8px;"></div>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>

    <?php else: ?>
        <div class="gmc-empty">No products synced yet. Click "Re-sync" above to fetch your catalogue + diagnostics.</div>
    <?php endif; ?>
<?php endif; ?>

<style>
.gmc-fix-card { padding:10px 12px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; font-size:12px; }
.gmc-fix-card h4 { margin:0 0 6px; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:#0c4a6e; }
.gmc-fix-row { display:grid; grid-template-columns: 110px 1fr; gap:6px; padding:4px 0; border-top:1px solid #e0f2fe; }
.gmc-fix-row .field { font-family:ui-monospace,monospace; font-size:11px; color:#0c4a6e; font-weight:600; }
.gmc-fix-row .change { font-size:11px; line-height:1.5; }
.gmc-fix-row .old { color:#9ca3af; text-decoration:line-through; }
.gmc-fix-row .new { color:#065f46; font-weight:600; }
.gmc-fix-row .reason { color:#475569; margin-top:2px; }
.gmc-unfixable { color:#92400e; font-size:11px; margin-top:8px; padding-top:6px; border-top:1px solid #e0f2fe; }
</style>
<script>
const SITE_ID = <?= $site_id ?>;
async function suggestFix(btn, productId) {
    btn.disabled = true;
    btn.innerHTML = '⏳ Thinking…';
    const wrap = document.querySelector('.gmc-fix-result[data-pid="' + productId + '"]');
    wrap.style.display = 'block';
    wrap.innerHTML = '<div style="padding:8px; font-size:11px; color:#64748b;">Claude is reading the product + issues…</div>';
    try {
        const res = await fetch('<?= url('/api/gmc-action.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'suggest_fix', site_id: SITE_ID, product_id: productId })
        });
        const data = await res.json();
        if (!data.success) {
            wrap.innerHTML = '<div style="color:#dc2626; font-size:11px;">' + (data.error || 'Failed') + '</div>';
            btn.disabled = false; btn.innerHTML = '&#129504; Suggest fix with AI';
            return;
        }
        let html = '<div class="gmc-fix-card"><h4>Proposed fixes</h4>';
        if (!data.fixes || data.fixes.length === 0) {
            html += '<div style="font-size:11px; color:#64748b;">No safe field-level fixes available. See "needs human action" below.</div>';
        } else {
            data.fixes.forEach(f => {
                html += '<div class="gmc-fix-row">';
                html += '<div class="field">' + (f.field || '') + '</div>';
                html += '<div class="change">';
                if (f.old_value) html += '<div class="old">' + escapeHtml(String(f.old_value).slice(0, 200)) + '</div>';
                html += '<div class="new">→ ' + escapeHtml(String(f.new_value || '').slice(0, 200)) + '</div>';
                if (f.reason) html += '<div class="reason">' + escapeHtml(f.reason) + '</div>';
                html += '</div></div>';
            });
        }
        if (data.unfixable && data.unfixable.length > 0) {
            html += '<div class="gmc-unfixable"><strong>Needs human action:</strong><ul style="margin:4px 0 0; padding-left:18px;">';
            data.unfixable.forEach(u => {
                html += '<li><code>' + escapeHtml(u.issue_code || '') + '</code> — ' + escapeHtml(u.reason || '') + '</li>';
            });
            html += '</ul></div>';
        }
        html += '</div>';
        wrap.innerHTML = html;
        btn.innerHTML = '&#10003; Suggestions ready';
    } catch (e) {
        wrap.innerHTML = '<div style="color:#dc2626; font-size:11px;">' + e.message + '</div>';
        btn.disabled = false; btn.innerHTML = '&#129504; Suggest fix with AI';
    }
}
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function runAudit(btn) {
    btn.disabled = true;
    const prog = document.getElementById('gmc-progress');
    prog.style.display = 'block';
    prog.innerHTML = 'Syncing catalogue + per-product diagnostics from Google Merchant Center… can take several minutes for large feeds.';
    try {
        const res = await fetch('<?= url('/api/gmc-action.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'audit', site_id: SITE_ID })
        });
        const data = await res.json();
        if (!data.success) {
            prog.innerHTML = '<span style="color:#dc2626;">' + (data.error || 'Failed') + '</span>';
            btn.disabled = false;
            return;
        }
        prog.innerHTML = 'Done — synced ' + data.products_synced + ' products, ' + data.issues_found + ' issues found. Refreshing…';
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
