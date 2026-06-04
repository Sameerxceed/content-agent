<?php
/**
 * Dashboard — Sitemap & IndexNow.
 *
 * Two related setup flows in one page:
 *  1. Sitemap download — drop-in sitemap.xml if customer's CMS can't generate one
 *  2. IndexNow setup — per-site key + verification step + push button
 *
 * Once setup is complete, every publish via ContentAgent auto-pings IndexNow,
 * cutting search-engine indexation latency from days/weeks to minutes.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sitemap_indexnow.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$site_id = (int)($_GET['site'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(404); exit('Site not found or access denied.'); }

$host = preg_replace('#^https?://#i', '', (string)$site['domain']);
$key  = indexnow_key_for_site($db, $site);
$verify = indexnow_verify_key($host, $key);

$page_title = 'Sitemap & IndexNow — ' . $site['name'];
ob_start();
?>
<style>
.in-section { background:#fff; border:1px solid var(--border); border-radius:6px; padding:16px 18px; margin-bottom:14px; }
.in-section h4 { margin:0 0 6px; font-size:13px; color:var(--primary); }
.in-section p  { margin:0 0 8px; font-size:12px; color:#475569; line-height:1.6; }
.in-step { background:#f8fafc; border:1px solid var(--border); border-radius:5px; padding:10px 12px; margin:6px 0; font-size:12px; color:#0f172a; }
.in-key { font-family:ui-monospace, monospace; font-size:12px; background:#e0e7ff; padding:4px 8px; border-radius:4px; color:#312e81; word-break:break-all; }
.in-pill { font-size:11px; padding:3px 9px; border-radius:10px; font-weight:600; display:inline-block; }
.in-pill.ok   { background:#d1fae5; color:#065f46; }
.in-pill.miss { background:#fee2e2; color:#991b1b; }
</style>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/seo.php?site=' . $site_id) ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">← Back to SEO</a>
</div>

<div class="setup-section" style="max-width:880px;">
    <h3 style="margin:0 0 3px; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; color:var(--primary);">Sitemap &amp; IndexNow</h3>
    <p class="desc" style="margin:0; max-width:720px;">
        IndexNow is an open protocol (Bing, Yandex, Cloudflare, Naver — Google joining) that lets you tell search
        engines about new or updated URLs <em>immediately</em>, cutting indexation from days/weeks down to minutes.
        Same approach feeds AI engines like Claude and ChatGPT that pull from indexed search content.
    </p>
</div>

<div class="in-section" style="max-width:880px;">
    <h4>1. Sitemap (optional drop-in)</h4>
    <p>
        Most CMSs auto-generate <code>sitemap.xml</code>. If yours doesn't, or you want a freshly-built one based on
        our current URL inventory (<?= number_format((int)$db->query("SELECT COUNT(*) FROM current_site_urls WHERE site_id={$site_id}")->fetchColumn()) ?> known URLs), download below and upload to your web root.
    </p>
    <a class="btn btn-outline btn-sm" href="<?= url('/api/indexnow-action.php?action=sitemap_xml&site_id=' . $site_id) ?>">↓ sitemap.xml</a>
</div>

<div class="in-section" style="max-width:880px;">
    <h4>2. Set up IndexNow — one-time per site</h4>
    <p>This step proves to the search engines that you own <strong><?= e($host) ?></strong>. You upload a small text file containing your key; we verify; pushes work from then on.</p>
    <div class="in-step">
        <strong>Your IndexNow key for this site:</strong><br>
        <span class="in-key"><?= e($key) ?></span>
    </div>
    <div class="in-step">
        <strong>Create a file</strong> named <code><?= e($key) ?>.txt</code> containing only the key string above (no whitespace, no quotes).
    </div>
    <div class="in-step">
        <strong>Upload it</strong> to the root of <?= e($host) ?> so it's reachable at:<br>
        <a href="https://<?= e($host) ?>/<?= e($key) ?>.txt" target="_blank" class="in-key">https://<?= e($host) ?>/<?= e($key) ?>.txt</a>
    </div>
    <div style="display:flex; gap:8px; align-items:center; margin-top:10px;">
        <button class="btn btn-accent btn-sm" onclick="verifyKey(this)">↻ Verify key file</button>
        <?php if ($verify['verified']): ?>
            <span class="in-pill ok">✓ Verified — key file is reachable</span>
        <?php else: ?>
            <span class="in-pill miss">✗ Not found yet (HTTP <?= (int)$verify['http'] ?>)</span>
        <?php endif; ?>
    </div>
</div>

<div class="in-section" style="max-width:880px;">
    <h4>3. Push every known URL to IndexNow</h4>
    <p>Once the key is verified, this submits every URL we know about (your live inventory + recently-published posts) to IndexNow. Search engines will recrawl them in minutes.</p>
    <button class="btn btn-primary btn-sm" onclick="pushAll(this)" <?= $verify['verified'] ? '' : 'disabled' ?>>⚡ Push all URLs now</button>
    <?php if (!$verify['verified']): ?>
        <span style="margin-left:8px; font-size:11px; color:#92400e;">Verify the key file first ↑</span>
    <?php else: ?>
        <span style="margin-left:8px; font-size:11px; color:var(--text-light);">After this, every new publish via ContentAgent auto-pings IndexNow.</span>
    <?php endif; ?>
    <div id="in-result" style="margin-top:10px; font-size:12px;"></div>
</div>

<script>
const SITE_ID = <?= $site_id ?>;
const API = '<?= url('/api/indexnow-action.php') ?>';

async function call(action, body = {}) {
    const res = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action, site_id: SITE_ID, ...body})});
    const data = await res.json();
    if (!res.ok || (!data.success && data.error)) throw new Error(data.error || ('HTTP ' + res.status));
    return data;
}

async function verifyKey(btn) {
    btn.disabled = true;
    try { await call('verify'); window.location.reload(); }
    catch (e) { alert(e.message); btn.disabled = false; }
}

async function pushAll(btn) {
    if (!confirm('Push every known URL for this site to IndexNow now?')) return;
    btn.disabled = true; btn.textContent = 'Pushing…';
    const result = document.getElementById('in-result');
    try {
        const r = await call('push_all');
        result.innerHTML = '<span style="color:#059669;">✓ Pushed ' + r.pushed.toLocaleString() + ' URLs. Search engines will crawl in minutes.</span>';
        btn.textContent = '⚡ Push all URLs now';
        btn.disabled = false;
    } catch (e) { result.innerHTML = '<span style="color:#dc2626;">' + e.message + '</span>'; btn.textContent = '⚡ Push all URLs now'; btn.disabled = false; }
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
