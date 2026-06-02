<?php
/**
 * Legal docs — review and approve generated compliance pages.
 *
 * Day 1 (this version): list view shows detected state.
 * Day 2: per-doc Generate button → Claude → review draft → Approve.
 * Day 3: Approve → CMS push.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/legal_docs.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';

$site_id = (int)($_GET['site'] ?? 0);
$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(403); exit('Access denied'); }

// Run detection on every page load — cheap (5 HEAD requests) and ensures
// the list reflects the live state of the customer's website.
if (!isset($_GET['skip_detect'])) {
    legal_docs_detect_missing($db, $site_id);
}

$docs = legal_docs_list($db, $site_id);

// Make sure every required doc type has a placeholder row even if detection
// hasn't run yet (e.g. user landed here before scanning the site).
$required = legal_docs_required_types($site);
foreach ($required as $type => $relevance) {
    if (!isset($docs[$type])) {
        $docs[$type] = [
            'doc_type'  => $type,
            'status'    => 'unknown',
            'relevance' => $relevance,
            'found_url' => null,
        ];
    }
}

$doc_meta = [
    'privacy'    => ['icon' => '🔐', 'name' => 'Privacy Policy',     'why' => 'Required globally for any site collecting an email or cookie. DPDP, GDPR, CCPA all mandate it.'],
    'terms'      => ['icon' => '📜', 'name' => 'Terms of Service',  'why' => 'Defines what users can and can\'t do on your site. Sets liability boundaries.'],
    'cookies'    => ['icon' => '🍪', 'name' => 'Cookie Policy',     'why' => 'EU + UK require explicit consent. Most analytics + ads tools drop cookies; you need the policy.'],
    'refund'     => ['icon' => '💸', 'name' => 'Refund Policy',     'why' => 'Required for any site that takes payment. Sets expectations + limits chargebacks.'],
    'disclaimer' => ['icon' => '⚖️', 'name' => 'Disclaimer',         'why' => 'Limits your liability when giving advice (finance, legal, medical, tax, consulting).'],
];

$status_styles = [
    'unknown'    => ['label' => 'Not scanned yet', 'bg' => '#f1f5f9', 'fg' => '#64748b'],
    'missing'    => ['label' => 'Missing',          'bg' => '#fee2e2', 'fg' => '#991b1b'],
    'generating' => ['label' => 'Generating…',      'bg' => '#dbeafe', 'fg' => '#1e40af'],
    'drafted'    => ['label' => 'Draft ready',      'bg' => '#fef3c7', 'fg' => '#92400e'],
    'approved'   => ['label' => 'Approved',         'bg' => '#dcfce7', 'fg' => '#166534'],
    'published'  => ['label' => 'Published ✓',      'bg' => '#dcfce7', 'fg' => '#166534'],
    'failed'     => ['label' => 'Failed',           'bg' => '#fee2e2', 'fg' => '#991b1b'],
];

$page_title = 'Legal documents — ' . $site['name'];
$stepper_active = 'grow';
ob_start();
include __DIR__ . '/_site_stepper.php';
?>

<style>
.ld-grid { display:flex; flex-direction:column; gap:10px; margin-top:14px; }
.ld-row { background:#fff; border:1px solid var(--border); border-radius:8px; padding:16px 18px; display:grid; grid-template-columns: 1fr auto; gap:14px; align-items:center; }
.ld-row .head { display:flex; align-items:center; gap:14px; margin-bottom:6px; }
.ld-row .head .icon { font-size:22px; }
.ld-row .head .name { font-size:15px; font-weight:600; color:var(--primary); }
.ld-row .head .badge { font-size:11px; font-weight:600; padding:3px 10px; border-radius:10px; }
.ld-row .desc { font-size:12px; color:var(--text-light); line-height:1.5; max-width:780px; }
.ld-row .status-line { font-size:11px; color:#64748b; margin-top:4px; }
.ld-row .status-line code { background:#f1f5f9; padding:1px 6px; border-radius:3px; font-size:11px; }
.ld-actions { display:flex; flex-direction:column; gap:6px; align-items:flex-end; }
.ld-btn { font-size:12px; font-weight:600; padding:8px 14px; border-radius:6px; border:0; cursor:pointer; text-decoration:none; text-align:center; }
.ld-btn.primary  { background:var(--accent); color:#fff; }
.ld-btn.outline  { background:#fff; color:var(--primary); border:1px solid var(--border); }
.ld-btn[disabled] { background:#e2e8f0; color:#94a3b8; cursor:not-allowed; }
.ld-intro { background:linear-gradient(135deg,#1B3A6B 0%,#2c5282 100%); color:#fff; border:0; border-radius:8px; padding:16px 20px; margin-bottom:8px; }
.ld-intro h2 { margin:0 0 6px; font-size:16px; font-weight:600; }
.ld-intro p  { margin:0; font-size:12px; opacity:0.9; line-height:1.55; max-width:760px; }
.ld-summary { display:flex; gap:18px; font-size:12px; color:#64748b; margin-top:8px; }
.ld-summary strong { color:#0f172a; font-size:13px; }
</style>

<?php
// Build summary counts
$missing = 0; $present = 0; $drafted = 0;
foreach ($docs as $d) {
    if ($d['status'] === 'missing')   $missing++;
    elseif ($d['status'] === 'published') $present++;
    elseif (in_array($d['status'], ['drafted','approved'], true)) $drafted++;
}
?>

<div class="ld-intro">
    <h2>Legal documents</h2>
    <p>Every website needs a Privacy Policy and Terms of Service to be legally compliant. Most need a Cookie Policy too. ContentAgent detects what's missing on your site, drafts each document tailored to your business + jurisdiction, then publishes them — so you stay on the right side of DPDP, GDPR, and CCPA without paying a lawyer.</p>
    <div class="ld-summary">
        <span><strong><?= $present ?></strong> already on your site</span>
        <span><strong><?= $drafted ?></strong> drafted, awaiting review</span>
        <span><strong style="color:<?= $missing ? '#dc2626' : '#0f172a' ?>;"><?= $missing ?></strong> missing</span>
    </div>
</div>

<div class="ld-grid">
    <?php foreach ($docs as $type => $d):
        $meta   = $doc_meta[$type] ?? ['icon' => '📄', 'name' => ucfirst($type), 'why' => ''];
        $status = $d['status'] ?? 'unknown';
        $style  = $status_styles[$status] ?? $status_styles['unknown'];
    ?>
    <div class="ld-row">
        <div>
            <div class="head">
                <span class="icon"><?= $meta['icon'] ?></span>
                <span class="name"><?= e($meta['name']) ?></span>
                <span class="badge" style="background:<?= $style['bg'] ?>;color:<?= $style['fg'] ?>;"><?= $style['label'] ?></span>
                <?php if (($d['relevance'] ?? 'required') !== 'required'): ?>
                    <span style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.4px;font-weight:600;"><?= e($d['relevance']) ?></span>
                <?php endif; ?>
            </div>
            <div class="desc"><?= e($meta['why']) ?></div>
            <?php if ($status === 'published' && !empty($d['found_url'])): ?>
                <div class="status-line">Live at <a href="<?= e($d['found_url']) ?>" target="_blank" style="color:#0891b2;"><?= e($d['found_url']) ?></a></div>
            <?php elseif ($status === 'missing'): ?>
                <div class="status-line">Scanner checked: <code><?= e(implode(' · ', json_decode($d['expected_paths'] ?? '[]', true) ?: [])) ?></code> — none returned 200.</div>
            <?php elseif ($status === 'drafted'): ?>
                <div class="status-line">Drafted <?= e(format_date($d['generated_at'] ?? '')) ?>. Review and approve to publish.</div>
            <?php endif; ?>
        </div>
        <div class="ld-actions">
            <?php if ($status === 'missing' || $status === 'unknown' || $status === 'failed'): ?>
                <button class="ld-btn primary" onclick="generateDoc('<?= e($type) ?>', this)">✨ Generate</button>
                <?php if ($status === 'failed' && !empty($d['last_error'])): ?>
                    <div style="font-size:10px;color:#dc2626;max-width:200px;text-align:right;"><?= e(substr((string)$d['last_error'], 0, 120)) ?></div>
                <?php endif; ?>
            <?php elseif ($status === 'generating'): ?>
                <button class="ld-btn primary" disabled>⏳ Drafting…</button>
            <?php elseif ($status === 'drafted'): ?>
                <a class="ld-btn outline" href="<?= url('/dashboard/legal-edit.php?site=' . $site_id . '&type=' . e($type)) ?>">Review draft</a>
                <button class="ld-btn primary" onclick="publishDoc('<?= e($type) ?>', this)">Publish to site →</button>
            <?php elseif ($status === 'approved'): ?>
                <button class="ld-btn primary" onclick="publishDoc('<?= e($type) ?>', this)">Publish to site →</button>
            <?php elseif ($status === 'published'): ?>
                <?php $live = $d['published_url'] ?? $d['found_url'] ?? ''; ?>
                <a class="ld-btn outline" href="<?= e($live) ?>" target="_blank">View on site ↗</a>
                <button class="ld-btn outline" onclick="regenerateDoc('<?= e($type) ?>', this)" title="Replace with a freshly-generated version">🔄 Regenerate</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($missing > 0): ?>
<div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end;">
    <button class="ld-btn primary" style="padding:10px 22px;" onclick="generateAndPublishAll(this)">
        ✨ Generate all missing &amp; publish to site
    </button>
</div>
<?php endif; ?>

<div style="margin-top:18px;font-size:11px;color:#94a3b8;line-height:1.6;">
    <strong>About the generated documents:</strong> ContentAgent uses your business profile + the data your site actually collects (cookies, forms, third-party tools) to draft accurate policies. They cover DPDP (India), GDPR (EU/UK), and CCPA (California) by default. <em>Important: these are AI-generated baseline documents. For material legal exposure — fundraising, M&amp;A, regulated industries — review with a qualified lawyer.</em>
</div>

<script>
const SITE_ID = <?= (int)$site_id ?>;

async function callAction(payload) {
    const r = await fetch('<?= url('/api/legal-action.php') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    try { return await r.json(); } catch (e) { return { success:false, error:'Bad response' }; }
}

async function generateDoc(type, btn) {
    if (!confirm('Generate the ' + type + ' document via Claude? Takes 30-60 seconds.')) return;
    btn.disabled = true; btn.textContent = '⏳ Drafting…';
    const r = await callAction({ action:'generate', site_id:SITE_ID, doc_type:type });
    if (r.success) location.reload();
    else { alert('Failed: ' + (r.error || 'unknown')); btn.disabled = false; btn.textContent = '✨ Generate'; }
}

async function publishDoc(type, btn) {
    if (!confirm('Publish the ' + type + ' document to your live website?')) return;
    btn.disabled = true; btn.textContent = '⏳ Pushing…';
    const r = await callAction({ action:'publish', site_id:SITE_ID, doc_type:type });
    if (r.success) {
        alert('✓ Published at ' + (r.published_url || 'your site'));
        location.reload();
    } else { alert('Failed: ' + (r.error || 'unknown')); btn.disabled = false; btn.textContent = 'Publish to site →'; }
}

async function regenerateDoc(type, btn) {
    if (!confirm('Replace the live ' + type + ' document with a freshly-generated version? The old one will be overwritten on your site.')) return;
    btn.disabled = true; btn.textContent = '⏳ Regenerating…';
    const r = await callAction({ action:'generate_and_publish', site_id:SITE_ID, doc_type:type });
    if (r.success) location.reload();
    else { alert('Failed: ' + (r.error || 'unknown')); btn.disabled = false; btn.textContent = '🔄 Regenerate'; }
}

async function generateAndPublishAll(btn) {
    const missing = <?= json_encode(array_values(array_filter($legal_missing_types ?? array_keys(array_filter($docs, fn($d) => ($d['status'] ?? '') === 'missing'))))) ?>;
    const types = missing.length > 0
        ? missing
        : <?= json_encode(array_keys(array_filter($docs, fn($d) => ($d['status'] ?? '') === 'missing'))) ?>;
    if (types.length === 0) { alert('Nothing missing!'); return; }
    if (!confirm('Generate and publish ' + types.length + ' missing documents? Each one takes about a minute. Total: ' + types.length + ' minutes.')) return;
    btn.disabled = true;
    let i = 0;
    for (const t of types) {
        i++;
        btn.textContent = '⏳ ' + i + '/' + types.length + ' — ' + t + '…';
        const r = await callAction({ action:'generate_and_publish', site_id:SITE_ID, doc_type:t });
        if (!r.success) { alert('Failed on ' + t + ': ' + (r.error || 'unknown') + '. Continuing with the rest.'); }
    }
    location.reload();
}
</script>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
