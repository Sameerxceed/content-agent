<?php
/**
 * Per-site Setup — every one-time configuration in one tabbed page.
 *
 * Replaces the long single-form sites.php?action=edit. Same backend handler
 * (sites.php POST action=update), reorganised into 6 tabs:
 *   Business      — name + AI-inferred profile + focus
 *   Brand         — colors, fonts, blog path
 *   Publishing    — cadence, autonomy, CMS, digest email
 *   Channels      — per-site platform connections (read-only status + Configure)
 *   Server & feeds — server access, RSS, is-active
 *   Danger        — delete site
 *
 * URL: /dashboard/setup.php?site=N&tab=business
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/integrations/linkedin.php';
require_once __DIR__ . '/../../includes/integrations/pinterest.php';
require_once __DIR__ . '/../../includes/integrations/google.php';

auth_start();
auth_require();

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? $_GET['id'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(403); exit('Access denied'); }

$valid_tabs = ['business', 'publishing', 'channels', 'server', 'danger'];
$tab = in_array($_GET['tab'] ?? '', $valid_tabs, true) ? $_GET['tab'] : 'business';
// Legacy: old "Brand" tab merged into Business; quietly remap.
if (($_GET['tab'] ?? '') === 'brand') $tab = 'business';

// Per-site connected platforms (Channels tab) — fail-soft if integrations table missing.
// Column set matches migration 006: account_name (not account_label), no status, no last_synced_at.
$connected = [];
try {
    $stmt = $db->prepare('SELECT platform, is_active, account_name, connected_at
                          FROM integrations WHERE site_id = ?');
    $stmt->execute([$site_id]);
    foreach ($stmt->fetchAll() as $row) {
        $connected[$row['platform']] = $row;
    }
} catch (PDOException $e) {
    // integrations table not present — leave $connected empty
}

// Global integrations (Resend / OpenAI / DataForSEO etc.) are configured ONCE
// across the account, not per-site. Check integration_setup_progress for the
// resend wizard's completed status as a proxy for "newsletter is available".
$resend_ready = false;
try {
    $stmt = $db->prepare("SELECT 1 FROM integration_setup_progress WHERE user_id = ? AND integration = 'resend' AND status = 'completed' LIMIT 1");
    $stmt->execute([$user_id]);
    $resend_ready = (bool)$stmt->fetchColumn();
} catch (PDOException $e) {
    // progress table absent — leave false
}
if (!$resend_ready) {
    // Fall back to config: if RESEND_API_KEY is set, treat it as ready.
    $resend_ready = !empty(config('resend_api_key', '')) || !empty(getenv('RESEND_API_KEY'));
}

// Profile-confidence helpers (copied verbatim from sites.php so the AI-tag UX matches)
$profile_confidence = json_decode($site['profile_confidence'] ?? '{}', true) ?: [];
$profile_signals    = json_decode($site['profile_signals'] ?? '{}', true) ?: [];
$profile_inferred   = !empty($site['profile_inferred_at']);
$profile_confirmed  = !empty($site['profile_confirmed']);

$ai_tag = function(string $field) use ($profile_confidence, $profile_signals, $profile_confirmed) {
    $conf = $profile_confidence[$field] ?? null;
    if ($conf === null || $profile_confirmed) return '';
    $bg = $conf >= 0.7 ? '#d1fae5' : ($conf >= 0.4 ? '#fef3c7' : '#fee2e2');
    $fg = $conf >= 0.7 ? '#065f46' : ($conf >= 0.4 ? '#92400e' : '#991b1b');
    $tip = !empty($profile_signals[$field]) ? ' title="' . e((string)$profile_signals[$field]) . '"' : '';
    return ' <span' . $tip . ' style="font-size:10px;font-weight:500;padding:1px 6px;border-radius:8px;background:' . $bg . ';color:' . $fg . ';margin-left:6px;">&#10024; AI guess</span>';
};

$feeds         = json_decode($site['rss_feeds'] ?? '[]', true) ?: [];
$brand_colors  = json_decode($site['brand_colors'] ?? '[]', true) ?: [];

$page_title = 'Setup — ' . $site['name'];
$stepper_active = 'scan';
ob_start();
include __DIR__ . '/_site_stepper.php';
?>

<style>
.setup-shell { background:#fff; border:1px solid var(--border); border-radius:8px; overflow:hidden; }
.setup-tabs { display:flex; border-bottom:1px solid var(--border); background:#f8fafb; overflow-x:auto; }
.setup-tab { padding:12px 18px; font-size:13px; font-weight:600; color:#64748b; text-decoration:none; border-bottom:2px solid transparent; white-space:nowrap; display:flex; align-items:center; gap:6px; }
.setup-tab:hover { color:var(--primary); background:#fff; }
.setup-tab.active { color:var(--accent); border-bottom-color:var(--accent); background:#fff; }
.setup-tab .pill { font-size:10px; font-weight:600; padding:1px 7px; border-radius:10px; background:#dbeafe; color:#1e40af; }
.setup-tab.warn .pill { background:#fef3c7; color:#92400e; }
.setup-body { padding:14px 18px; }
.setup-section { margin-bottom:14px; max-width:980px; }
.setup-section + .setup-section { padding-top:12px; border-top:1px solid #f1f5f9; }
.setup-section h3 { font-size:11px; font-weight:600; color:var(--primary); margin:0 0 3px; text-transform:uppercase; letter-spacing:0.4px; }
.setup-section .desc { font-size:11px; color:#64748b; margin:0 0 8px; line-height:1.45; max-width:720px; }
.setup-section .form-group { margin-bottom:8px; }
.setup-section .form-group label { font-size:12px; }
.setup-section .form-control { padding:6px 10px; font-size:13px; max-width:420px; }
.setup-section .setup-grid-2 .form-control,
.setup-section .setup-grid-3 .form-control,
.setup-section textarea.form-control { max-width:100%; }
.setup-section textarea.form-control { font-size:12px; }
.setup-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:8px 12px; }
.setup-grid-3 { display:grid; grid-template-columns:repeat(3, 1fr); gap:8px 12px; }
@media (max-width:780px) { .setup-grid-3 { grid-template-columns:1fr 1fr; } }
@media (max-width:520px) { .setup-grid-2, .setup-grid-3 { grid-template-columns:1fr; } }
.setup-actions { margin-top:12px; padding-top:12px; border-top:1px solid #f1f5f9; display:flex; gap:8px; }
.setup-actions .btn { padding:7px 14px; font-size:12px; }
.setup-channel-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:10px; }
.setup-channel { padding:14px 16px; border:1px solid var(--border); border-radius:8px; background:#fff; display:flex; flex-direction:column; gap:6px; }
.setup-channel .head { display:flex; justify-content:space-between; align-items:center; }
.setup-channel .name { font-size:13px; font-weight:600; color:var(--primary); }
.setup-channel .badge { font-size:10px; font-weight:600; padding:2px 8px; border-radius:10px; }
.setup-channel .badge.ok   { background:#d1fae5; color:#065f46; }
.setup-channel .badge.off  { background:#f1f5f9; color:#64748b; }
.setup-channel .meta { font-size:11px; color:#64748b; line-height:1.5; flex:1; }
.setup-channel .cta { font-size:12px; font-weight:600; color:var(--accent); text-decoration:none; margin-top:6px; }
.danger-card { background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; padding:16px 18px; }
.danger-card h3 { color:#991b1b; margin:0 0 6px; font-size:13px; font-weight:600; }
.danger-card p { color:#7f1d1d; font-size:12px; line-height:1.5; margin:0 0 10px; }
</style>

<div class="setup-shell">
    <nav class="setup-tabs">
        <?php
        $tabs_def = [
            'business'   => ['label' => '🎯 Business',    'pill' => $profile_confirmed ? null : 'review', 'warn' => !$profile_confirmed],
            'publishing' => ['label' => '📋 Publishing',  'pill' => null, 'warn' => false],
            'channels'   => ['label' => '🔌 Channels',    'pill' => count($connected) ?: null, 'warn' => false],
            'server'     => ['label' => '🖥️ Server & feeds', 'pill' => null, 'warn' => false],
            'danger'     => ['label' => '⚠️ Danger',       'pill' => null, 'warn' => false],
        ];
        foreach ($tabs_def as $key => $def):
            $cls = $key === $tab ? 'active' : '';
            if ($def['warn']) $cls .= ' warn';
        ?>
            <a href="<?= url('/dashboard/setup.php?site=' . $site_id . '&tab=' . $key) ?>" class="setup-tab <?= $cls ?>">
                <?= $def['label'] ?>
                <?php if (!empty($def['pill'])): ?><span class="pill"><?= e((string)$def['pill']) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="setup-body">

    <?php if ($tab === 'business'): ?>
        <form method="POST" action="<?= url('/dashboard/sites.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $site_id ?>">
            <?php /* Preserve fields owned by other tabs so this Save doesn't blank them. Brand colors/fonts + blog_path are rendered below as real fields. */ ?>
            <input type="hidden" name="agent_mode" value="<?= e($site['agent_mode'] ?? 'manual') ?>">
            <input type="hidden" name="autonomy_mode" value="<?= e($site['autonomy_mode'] ?? 'review') ?>">
            <input type="hidden" name="posts_per_week" value="<?= (int)($site['posts_per_week'] ?? 2) ?>">
            <input type="hidden" name="cms_url" value="<?= e($site['cms_url'] ?? '') ?>">
            <input type="hidden" name="cms_api_key" value="<?= e($site['cms_api_key'] ?? '') ?>">
            <input type="hidden" name="server_type" value="<?= e($site['server_type'] ?? 'api_only') ?>">
            <input type="hidden" name="server_host" value="<?= e($site['server_host'] ?? '') ?>">
            <input type="hidden" name="server_user" value="<?= e($site['server_user'] ?? '') ?>">
            <input type="hidden" name="server_pass" value="<?= e($site['server_pass'] ?? '') ?>">
            <input type="hidden" name="server_path" value="<?= e($site['server_path'] ?? '') ?>">
            <input type="hidden" name="git_repo" value="<?= e($site['git_repo'] ?? '') ?>">
            <input type="hidden" name="hosting_panel" value="<?= e($site['hosting_panel'] ?? '') ?>">
            <input type="hidden" name="rss_feeds" value="<?= e(implode("\n", $feeds)) ?>">
            <input type="hidden" name="digest_email" value="<?= e($site['digest_email'] ?? '') ?>">
            <?php if (!empty($site['is_active'])): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>

            <div class="setup-section">
                <h3>Site name</h3>
                <p class="desc">The internal label used across ContentAgent. Customer-facing branding lives in the Brand tab.</p>
                <div class="form-group">
                    <input type="text" id="name" name="name" class="form-control" value="<?= e($site['name']) ?>">
                </div>
            </div>

            <div class="setup-section">
                <h3>Business profile</h3>
                <p class="desc">
                    <?php if ($profile_inferred): ?>
                        AI scanned your homepage + about/team pages on <?= e(format_date($site['profile_inferred_at'], 'd M Y, h:i A')) ?>. These fields drive every downstream agent — competitors, blog writer, keyword research, AEO, brand presence. If something is wrong, fix it here.
                    <?php else: ?>
                        Not analysed yet — run a scan from the SEO/AEO page to have AI fill these in automatically.
                    <?php endif; ?>
                </p>
                <?php if ($profile_inferred): ?>
                    <button type="button" onclick="reanalyseProfile(<?= $site_id ?>, this)" class="btn btn-outline btn-sm" style="font-size:11px;margin-bottom:10px;">🔄 Re-analyse with AI</button>
                <?php endif; ?>

                <div class="setup-grid-3">
                    <div class="form-group"><label for="founding_year">Founded (year)<?= $ai_tag('founding_year') ?></label>
                        <input type="number" id="founding_year" name="founding_year" class="form-control" min="1900" max="2030" value="<?= e((string)($site['founding_year'] ?? '')) ?>" placeholder="e.g. 2014"></div>
                    <div class="form-group"><label for="employee_estimate">Approx. team size<?= $ai_tag('employee_estimate') ?></label>
                        <input type="number" id="employee_estimate" name="employee_estimate" class="form-control" min="1" value="<?= e((string)($site['employee_estimate'] ?? '')) ?>" placeholder="e.g. 15"></div>
                    <div class="form-group"><label for="hq_city">HQ city<?= $ai_tag('hq_city') ?></label>
                        <input type="text" id="hq_city" name="hq_city" class="form-control" value="<?= e($site['hq_city'] ?? '') ?>" placeholder="e.g. Pune"></div>
                    <div class="form-group"><label for="hq_country">HQ country<?= $ai_tag('hq_country') ?></label>
                        <input type="text" id="hq_country" name="hq_country" class="form-control" value="<?= e($site['hq_country'] ?? '') ?>" placeholder="e.g. India"></div>
                    <div class="form-group"><label for="size_tier">Company size tier<?= $ai_tag('size_tier') ?></label>
                        <select id="size_tier" name="size_tier" class="form-control"><option value="">—</option>
                        <?php foreach (['solo'=>'Solo (1 person)','small'=>'Small (2–10)','mid'=>'Mid (11–50)','large'=>'Large (51–500)','enterprise'=>'Enterprise (500+)'] as $v=>$lbl): ?>
                            <option value="<?= $v ?>" <?= ($site['size_tier'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?></select></div>
                    <div class="form-group"><label for="business_model">Business model<?= $ai_tag('business_model') ?></label>
                        <select id="business_model" name="business_model" class="form-control"><option value="">—</option>
                        <?php foreach (['b2b'=>'B2B (sells to businesses)','b2c'=>'B2C (sells to consumers)','b2b2c'=>'B2B2C (via partners)','marketplace'=>'Marketplace','nonprofit'=>'Nonprofit'] as $v=>$lbl): ?>
                            <option value="<?= $v ?>" <?= ($site['business_model'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?></select></div>
                    <div class="form-group"><label for="offering_type">Offering<?= $ai_tag('offering_type') ?></label>
                        <select id="offering_type" name="offering_type" class="form-control"><option value="">—</option>
                        <?php foreach (['service'=>'Service','product'=>'Product','hybrid'=>'Hybrid (both)'] as $v=>$lbl): ?>
                            <option value="<?= $v ?>" <?= ($site['offering_type'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?></select></div>
                    <div class="form-group"><label for="customer_segment">Sells to<?= $ai_tag('customer_segment') ?></label>
                        <select id="customer_segment" name="customer_segment" class="form-control"><option value="">—</option>
                        <?php foreach (['consumer'=>'Consumers','smb'=>'Small businesses','midmarket'=>'Mid-market','enterprise'=>'Enterprise','mixed'=>'Mixed'] as $v=>$lbl): ?>
                            <option value="<?= $v ?>" <?= ($site['customer_segment'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?></select></div>
                    <div class="form-group"><label for="industry_category">Industry<?= $ai_tag('industry_category') ?></label>
                        <input type="text" id="industry_category" name="industry_category" class="form-control" value="<?= e($site['industry_category'] ?? '') ?>" placeholder="e.g. Healthcare, Retail, Legal"></div>
                    <div class="form-group"><label for="industry_sub">Sub-category<?= $ai_tag('industry_sub') ?></label>
                        <input type="text" id="industry_sub" name="industry_sub" class="form-control" value="<?= e($site['industry_sub'] ?? '') ?>" placeholder="e.g. Dental, Bakery, Family law"></div>
                    <div class="form-group"><label for="market_scope">Market scope<?= $ai_tag('market_scope') ?></label>
                        <select id="market_scope" name="market_scope" class="form-control"><option value="">—</option>
                        <?php foreach (['local'=>'Local (city)','regional'=>'Regional (state/region)','national'=>'National (one country)','global'=>'Global'] as $v=>$lbl): ?>
                            <option value="<?= $v ?>" <?= ($site['market_scope'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?></select></div>
                    <div class="form-group"><label for="maturity_tier">Maturity<?= $ai_tag('maturity_tier') ?></label>
                        <select id="maturity_tier" name="maturity_tier" class="form-control"><option value="">—</option>
                        <?php foreach (['bootstrapped'=>'Bootstrapped / early','established'=>'Established','category_leader'=>'Category leader','public_company'=>'Public company'] as $v=>$lbl): ?>
                            <option value="<?= $v ?>" <?= ($site['maturity_tier'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?></select></div>
                </div>

                <div style="margin-top:8px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;color:#475569;">
                        <input type="checkbox" name="profile_confirmed" value="1" <?= $profile_confirmed ? 'checked' : '' ?>>
                        I've reviewed the profile. AI agents may use it.
                    </label>
                </div>
            </div>

            <div class="setup-section">
                <h3>Business focus</h3>
                <p class="desc">Drives every AI decision — keyword research, content writing, SEO suggestions. <strong>If this is wrong, everything downstream will be wrong.</strong></p>
                <div class="setup-grid-2" style="align-items:start;">
                    <div class="form-group"><label for="business_description">What does your business sell or offer?</label>
                        <textarea id="business_description" name="business_description" class="form-control" rows="2" placeholder="In your own words — what does your business sell or do, and who for?"><?= e($site['business_description'] ?? '') ?></textarea></div>
                    <div class="form-group"><label for="topics">Main topics / products <span class="text-muted" style="font-weight:400;">(comma-separated)</span></label>
                        <textarea id="topics" name="topics" class="form-control" rows="2" placeholder="3–6 topics that describe what you sell or do"><?= e(implode(', ', json_decode($site['topics'] ?? '[]', true) ?: [])) ?></textarea></div>
                    <div class="form-group"><label for="persona">Ideal customer <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                        <textarea id="persona" name="persona" class="form-control" rows="2" placeholder="Who you're trying to reach"><?= e($site['persona'] ?? '') ?></textarea></div>
                    <div class="form-group"><label for="usp">USP <span class="text-muted" style="font-weight:400;">(what makes you different)</span></label>
                        <textarea id="usp" name="usp" class="form-control" rows="2" placeholder="What you do that competitors don't"><?= e($site['usp'] ?? '') ?></textarea></div>
                </div>
                <div class="form-group" style="margin-top:4px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;font-size:12px;">
                        <input type="checkbox" name="topics_confirmed" value="1" <?= !empty($site['topics_confirmed']) ? 'checked' : '' ?>>
                        AI can use this for content + SEO work.
                    </label>
                </div>
            </div>

            <div class="setup-section">
                <h3>Brand colors</h3>
                <p class="desc">Used by social carousels, blog theme, hero images. Leave blank if you're not sure — we'll use neutral defaults.</p>
                <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
                <?php for ($ci = 0; $ci < 3; $ci++): $cval = $brand_colors[$ci] ?? ''; ?>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <input type="color" name="brand_color_<?= $ci ?>" value="<?= e($cval ?: '#cccccc') ?>" style="width:36px;height:36px;border:1px solid #ddd;border-radius:4px;cursor:pointer;padding:0;<?= $cval ? '' : 'opacity:0.5;' ?>">
                        <input type="text" name="brand_color_hex_<?= $ci ?>" value="<?= e($cval) ?>" class="form-control" style="width:100px;font-size:12px;font-family:monospace;" placeholder="#hex (optional)" oninput="this.previousElementSibling.value=this.value;this.previousElementSibling.style.opacity=this.value?'1':'0.5';">
                    </div>
                <?php endfor; ?>
                </div>
            </div>

            <div class="setup-section">
                <h3>Brand fonts &amp; blog path</h3>
                <p class="desc">Fonts are used in generated images and embeds. Blog path is the URL prefix where posts live (used to construct canonical URLs).</p>
                <div class="setup-grid-2">
                    <div class="form-group"><label for="brand_fonts">Brand fonts (comma-separated)</label>
                        <input type="text" id="brand_fonts" name="brand_fonts" class="form-control" value="<?= e(implode(', ', json_decode($site['brand_fonts'] ?? '[]', true) ?: [])) ?>" placeholder="e.g. Space Grotesk, Inter"></div>
                    <div class="form-group"><label for="blog_path">Blog path</label>
                        <input type="text" id="blog_path" name="blog_path" class="form-control" value="<?= e($site['blog_path'] ?? '/blog') ?>" placeholder="/blog"></div>
                </div>
            </div>

            <div class="setup-actions">
                <button type="submit" class="btn btn-primary">Save</button>
                <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>

        <script>
        async function reanalyseProfile(siteId, btn) {
            if (!confirm('Re-run the AI scan? This overwrites the inferred fields (any you have NOT manually confirmed) and takes 10-20 seconds.')) return;
            const orig = btn.textContent; btn.disabled = true; btn.textContent = 'Analysing...';
            try {
                const res = await fetch('<?= url('/api/business-profile-reanalyse.php') ?>', {
                    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({site_id: siteId})
                });
                const data = await res.json();
                if (data.success) { location.reload(); return; }
                alert('Failed: ' + (data.error || 'unknown'));
                btn.disabled = false; btn.textContent = orig;
            } catch(e) { alert('Error: ' + e.message); btn.disabled = false; btn.textContent = orig; }
        }
        </script>

    <?php elseif ($tab === 'publishing'): ?>
        <form method="POST" action="<?= url('/dashboard/sites.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $site_id ?>">
            <?php /* Preserve other tabs */ ?>
            <input type="hidden" name="name" value="<?= e($site['name']) ?>">
            <input type="hidden" name="blog_path" value="<?= e($site['blog_path'] ?? '/blog') ?>">
            <input type="hidden" name="server_type" value="<?= e($site['server_type'] ?? 'api_only') ?>">
            <input type="hidden" name="server_host" value="<?= e($site['server_host'] ?? '') ?>">
            <input type="hidden" name="server_user" value="<?= e($site['server_user'] ?? '') ?>">
            <input type="hidden" name="server_pass" value="<?= e($site['server_pass'] ?? '') ?>">
            <input type="hidden" name="server_path" value="<?= e($site['server_path'] ?? '') ?>">
            <input type="hidden" name="git_repo" value="<?= e($site['git_repo'] ?? '') ?>">
            <input type="hidden" name="hosting_panel" value="<?= e($site['hosting_panel'] ?? '') ?>">
            <input type="hidden" name="rss_feeds" value="<?= e(implode("\n", $feeds)) ?>">
            <input type="hidden" name="business_description" value="<?= e($site['business_description'] ?? '') ?>">
            <input type="hidden" name="topics" value="<?= e(implode(', ', json_decode($site['topics'] ?? '[]', true) ?: [])) ?>">
            <input type="hidden" name="persona" value="<?= e($site['persona'] ?? '') ?>">
            <input type="hidden" name="usp" value="<?= e($site['usp'] ?? '') ?>">
            <?php if (!empty($site['topics_confirmed'])): ?><input type="hidden" name="topics_confirmed" value="1"><?php endif; ?>
            <?php if ($profile_confirmed): ?><input type="hidden" name="profile_confirmed" value="1"><?php endif; ?>
            <input type="hidden" name="founding_year" value="<?= e((string)($site['founding_year'] ?? '')) ?>">
            <input type="hidden" name="employee_estimate" value="<?= e((string)($site['employee_estimate'] ?? '')) ?>">
            <input type="hidden" name="hq_city" value="<?= e($site['hq_city'] ?? '') ?>">
            <input type="hidden" name="hq_country" value="<?= e($site['hq_country'] ?? '') ?>">
            <input type="hidden" name="size_tier" value="<?= e($site['size_tier'] ?? '') ?>">
            <input type="hidden" name="business_model" value="<?= e($site['business_model'] ?? '') ?>">
            <input type="hidden" name="offering_type" value="<?= e($site['offering_type'] ?? '') ?>">
            <input type="hidden" name="customer_segment" value="<?= e($site['customer_segment'] ?? '') ?>">
            <input type="hidden" name="industry_category" value="<?= e($site['industry_category'] ?? '') ?>">
            <input type="hidden" name="industry_sub" value="<?= e($site['industry_sub'] ?? '') ?>">
            <input type="hidden" name="market_scope" value="<?= e($site['market_scope'] ?? '') ?>">
            <input type="hidden" name="maturity_tier" value="<?= e($site['maturity_tier'] ?? '') ?>">
            <input type="hidden" name="brand_fonts" value="<?= e(implode(', ', json_decode($site['brand_fonts'] ?? '[]', true) ?: [])) ?>">
            <?php for ($_ci = 0; $_ci < 3; $_ci++): $_cval = $brand_colors[$_ci] ?? ''; ?>
                <input type="hidden" name="brand_color_hex_<?= $_ci ?>" value="<?= e($_cval) ?>">
            <?php endfor; ?>
            <?php if (!empty($site['is_active'])): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>

            <div class="setup-section">
                <h3>Cadence &amp; autonomy</h3>
                <p class="desc">How fast the autopilot publishes and how much approval it asks for.</p>
                <div class="setup-grid-2">
                    <div class="form-group"><label for="posts_per_week">Publishing cadence</label>
                        <select id="posts_per_week" name="posts_per_week" class="form-control">
                            <?php $_pw = (int)($site['posts_per_week'] ?? 2); ?>
                            <option value="1" <?= $_pw === 1 ? 'selected' : '' ?>>1 post/week (13/quarter)</option>
                            <option value="2" <?= $_pw === 2 ? 'selected' : '' ?>>2 posts/week (26/quarter) — recommended</option>
                            <option value="3" <?= $_pw === 3 ? 'selected' : '' ?>>3 posts/week (39/quarter)</option>
                        </select></div>
                    <div class="form-group"><label for="autonomy_mode">Autopilot autonomy</label>
                        <select id="autonomy_mode" name="autonomy_mode" class="form-control">
                            <?php $_am = (string)($site['autonomy_mode'] ?? 'review'); ?>
                            <option value="review" <?= $_am === 'review' ? 'selected' : '' ?>>Review each draft (default)</option>
                            <option value="manual" <?= $_am === 'manual' ? 'selected' : '' ?>>Manual (no autopilot drafting)</option>
                        </select></div>
                </div>
                <?php /* agent_mode kept as hidden input — legacy news-flow column, no UI */ ?>
                <input type="hidden" name="agent_mode" value="<?= e($site['agent_mode'] ?? 'manual') ?>">
            </div>

            <div class="setup-section">
                <h3>Push destination — your CMS</h3>
                <p class="desc">Optional. When set, every approved post is also pushed to your own CMS via this API. Failure here doesn't block — ContentAgent always hosts a working copy.</p>

                <?php
                    // Detect Shopify from platform column OR cms_url OR the token prefix
                    // — handles brand-new sites where the wizard hasn't filled in
                    // `platform` yet but the URL/token is unambiguous.
                    $platform      = strtolower((string)($site['platform'] ?? ''));
                    $current_token = (string)($site['cms_api_key'] ?? '');
                    $cms_url_lc    = strtolower((string)($site['cms_url'] ?? ''));
                    $domain_lc     = strtolower((string)($site['domain'] ?? ''));
                    $name_lc       = strtolower((string)($site['name'] ?? ''));
                    $is_shopify    = $platform === 'shopify'
                        || str_contains($cms_url_lc, 'myshopify.com')
                        || str_contains($domain_lc, 'myshopify.com')
                        || str_contains($name_lc, 'myshopify.com')
                        || str_starts_with($current_token, 'shpat_')
                        || str_starts_with($current_token, 'atkn_')
                        || str_starts_with($current_token, 'shpca_');
                    $oauth_configured = (bool)config('shopify_client_id') && (bool)config('shopify_client_secret');
                    $token_kind = '';
                    if (str_starts_with($current_token, 'atkn_'))      $token_kind = 'atkn (Dev Dashboard — GraphQL)';
                    elseif (str_starts_with($current_token, 'shpat_')) $token_kind = 'shpat (Legacy custom app — REST)';
                    elseif (str_starts_with($current_token, 'shpca_')) $token_kind = 'shpca (CLI custom app — REST)';
                ?>

                <?php if ($is_shopify): ?>
                    <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:14px 16px; margin-bottom:14px;">
                        <div style="display:flex; justify-content:space-between; gap:14px; align-items:flex-start; flex-wrap:wrap;">
                            <div style="flex:1; min-width:260px;">
                                <strong style="color:#0c4a6e;">Shopify connection</strong>
                                <?php if ($current_token): ?>
                                    <div style="font-size:12px; color:#0f172a; margin-top:6px;">
                                        ✓ Token saved
                                        <?php if ($token_kind): ?> <span style="color:#475569;">(<?= e($token_kind) ?>)</span><?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div style="font-size:12px; color:#475569; margin-top:6px;">
                                        Not connected yet. Use OAuth (recommended) or paste a token from your Shopify admin.
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <?php if ($oauth_configured): ?>
                                    <?php /* NOT a real <form> — we're nested inside the Setup save-form and HTML
                                            disallows nested forms (browser drops the inner one). Use JS to navigate. */ ?>
                                    <div style="display:flex; gap:6px; align-items:center;">
                                        <input type="text" id="shopify_oauth_shop" placeholder="my-store.myshopify.com" required
                                               value="<?= e(parse_url((string)($site['cms_url'] ?? ''), PHP_URL_HOST) ?: '') ?>"
                                               style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; width:220px;">
                                        <button type="button" class="btn btn-primary" style="font-size:12px; padding:7px 14px;"
                                                onclick="(function(){
                                                    var s = document.getElementById('shopify_oauth_shop').value.trim();
                                                    if(!s){ alert('Enter your Shopify shop domain'); return; }
                                                    window.location.href = '<?= url('/api/oauth/shopify-install.php') ?>?site_id=<?= $site_id ?>&shop=' + encodeURIComponent(s);
                                                })();">
                                            <?= $current_token ? 'Reconnect Shopify' : 'Connect Shopify' ?>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span style="font-size:11px; color:#92400e; background:#fef3c7; padding:5px 9px; border-radius:6px;">
                                        OAuth not configured server-side — paste a token below instead.
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <details style="margin-top:10px; font-size:12px;">
                            <summary style="cursor:pointer; color:#0c4a6e;">Why two paths? (token types explained)</summary>
                            <div style="padding:10px 0 0 0; color:#334155; line-height:1.6;">
                                <strong>OAuth (recommended)</strong> — one click, no token to copy. Works for every Shopify store; we get a permanent <code>shpat_</code> token under the hood.<br>
                                <strong>Manual paste — <code>shpat_</code></strong> token from legacy Custom Apps (Shopify admin → Apps → "Develop apps"). Uses Shopify's REST Admin API.<br>
                                <strong>Manual paste — <code>atkn_</code></strong> token from the new Dev Dashboard (introduced Jan 2026). We auto-route these through Shopify's GraphQL Admin API; no extra config needed.<br>
                                <em>If OAuth fails or your store is in development mode, paste any of the above into the API key field and save.</em>
                            </div>
                        </details>
                    </div>
                <?php endif; ?>

                <div class="setup-grid-2">
                    <div class="form-group"><label for="cms_url">CMS URL</label>
                        <input type="text" id="cms_url" name="cms_url" class="form-control" value="<?= e($site['cms_url'] ?? '') ?>" placeholder="<?= $is_shopify ? 'https://my-store.myshopify.com' : 'https://cms.yourdomain.com' ?>"></div>
                    <div class="form-group"><label for="cms_api_key">CMS API key</label>
                        <input type="text" id="cms_api_key" name="cms_api_key" class="form-control" value="<?= e($site['cms_api_key'] ?? '') ?>" placeholder="<?= $is_shopify ? 'shpat_… or atkn_…' : 'your-api-key' ?>"></div>
                </div>
            </div>

            <?php
                require_once __DIR__ . '/../../includes/channel_schedule.php';
                $current_offsets = channel_schedule_get($site);
                $offset_channels = [
                    'cms'        => ['name' => 'CMS (blog article)', 'icon' => '&#9998;',  'desc' => 'Your website / blog'],
                    'schema'     => ['name' => 'Schema',             'icon' => '&#10070;', 'desc' => 'JSON-LD for SEO'],
                    'llms'       => ['name' => 'llms.txt',           'icon' => '&#129302;','desc' => 'AI crawler discovery'],
                    'linkedin'   => ['name' => 'LinkedIn',           'icon' => '&#128279;','desc' => 'Personal or company page'],
                    'twitter'    => ['name' => 'Twitter / X',        'icon' => '&#128038;','desc' => 'Thread on your handle'],
                    'pinterest'  => ['name' => 'Pinterest',          'icon' => '&#128204;','desc' => 'Pin to your chosen board'],
                    'newsletter' => ['name' => 'Newsletter',         'icon' => '&#9993;',  'desc' => 'Sent to subscribers'],
                ];
                // Presets — clicking populates every offset in one go. B2B
                // typically skips Pinterest (-1 = "don't publish here"); B2C
                // benefits most from Pinterest (visual / buyer intent).
                $presets = [
                    'b2b' => [
                        'label' => 'B2B SaaS (recommended)',
                        'desc'  => 'Blog leads, social next day, newsletter mid-week — gives each channel its own moment',
                        'offsets' => ['cms' => 0, 'schema' => 0, 'llms' => 0, 'linkedin' => 1, 'twitter' => 1, 'pinterest' => -1, 'newsletter' => 2],
                    ],
                    'b2c' => [
                        'label' => 'B2C / ecommerce',
                        'desc'  => 'Blog + schema today, Pinterest tomorrow for visual discovery, social same day',
                        'offsets' => ['cms' => 0, 'schema' => 0, 'llms' => 0, 'linkedin' => 0, 'twitter' => 0, 'pinterest' => 1, 'newsletter' => 0],
                    ],
                    'solo' => [
                        'label' => 'Solo creator',
                        'desc'  => 'Twitter first to build buzz, blog next day, Pinterest + newsletter mid-week',
                        'offsets' => ['twitter' => 0, 'cms' => 1, 'schema' => 1, 'llms' => 1, 'linkedin' => 1, 'pinterest' => 2, 'newsletter' => 3],
                    ],
                ];
                // Group channels by their current offset day for the visual timeline.
                $by_day = [];
                foreach ($offset_channels as $ch => $meta) {
                    $d = (int)($current_offsets[$ch] ?? 0);
                    $by_day[$d][] = $ch;
                }
                ksort($by_day);
                $max_day = max(array_keys($by_day) ?: [0]);
                $min_day = min(array_keys($by_day) ?: [0]);
            ?>
            <style>
            .cps-section { margin-top:14px; }
            .cps-section h3 { margin:0 0 4px; }
            .cps-section .desc { margin:0 0 14px; font-size:13px; color:#64748b; }
            .cps-presets { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
            .cps-preset { padding:9px 14px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer; text-align:left; min-width:180px; flex:1; max-width:280px; transition:all 0.15s; }
            .cps-preset:hover { border-color:#7c3aed; background:#faf5ff; }
            .cps-preset .pn { font-size:13px; font-weight:600; color:#0f172a; }
            .cps-preset .pd { font-size:11px; color:#64748b; margin-top:2px; line-height:1.4; }
            .cps-timeline { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px; margin-bottom:16px; overflow-x:auto; }
            .cps-tl-label { font-size:10px; text-transform:uppercase; letter-spacing:0.5px; color:#64748b; margin-bottom:8px; }
            .cps-tl-rail { position:relative; display:flex; gap:4px; min-height:78px; padding:6px 0; }
            .cps-tl-day { flex:1; min-width:90px; display:flex; flex-direction:column; align-items:center; padding:4px 0; border-left:1px dashed #cbd5e1; position:relative; }
            .cps-tl-day:first-child { border-left:0; }
            .cps-tl-daylabel { font-size:11px; font-weight:600; color:#475569; margin-bottom:6px; white-space:nowrap; }
            .cps-tl-daylabel .reltext { font-size:9px; color:#94a3b8; font-weight:400; display:block; line-height:1.2; margin-top:1px; }
            .cps-tl-chips { display:flex; flex-direction:column; gap:3px; width:100%; padding:0 6px; }
            .cps-tl-chip { font-size:10px; padding:3px 7px; background:#fff; border:1px solid #c7d2fe; border-radius:10px; color:#3730a3; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .cps-tl-empty { font-size:10px; color:#cbd5e1; font-style:italic; }
            .cps-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:10px; }
            .cps-card { padding:11px 14px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; display:flex; align-items:center; gap:10px; }
            .cps-card .ico { font-size:18px; line-height:1; }
            .cps-card .meta { flex:1; min-width:0; }
            .cps-card .nm { font-size:13px; font-weight:600; color:#0f172a; line-height:1.2; }
            .cps-card .sub { font-size:10px; color:#94a3b8; margin-top:2px; }
            .cps-card .pick { display:flex; align-items:center; gap:5px; }
            .cps-card input[type=number] { width:54px; padding:4px 6px; font-size:12px; border:1px solid #cbd5e1; border-radius:4px; font-family:ui-monospace, monospace; text-align:right; }
            .cps-card .unit { font-size:10px; color:#64748b; }
            </style>

            <div class="setup-section cps-section">
                <h3>Channel publish schedule</h3>
                <p class="desc">Stagger each channel by days. Default leads with the blog, then LinkedIn the next day, newsletter mid-week — gives each channel its own moment instead of dumping everything at once.</p>

                <!-- Presets — one click populates all 6 offsets -->
                <div class="cps-tl-label">Quick presets</div>
                <div class="cps-presets">
                  <?php foreach ($presets as $pk => $p): ?>
                    <button type="button" class="cps-preset" onclick='cpsApplyPreset(<?= json_encode($p['offsets']) ?>)'>
                      <div class="pn"><?= e($p['label']) ?></div>
                      <div class="pd"><?= e($p['desc']) ?></div>
                    </button>
                  <?php endforeach; ?>
                </div>

                <!-- Visual timeline of where each channel currently lands -->
                <div class="cps-timeline">
                    <div class="cps-tl-label">Timeline preview</div>
                    <div class="cps-tl-rail" id="cps-timeline-rail">
                        <?php
                            // Render 8 days (Day 0 → +7) so the user sees future spread room.
                            $rel_text = function($d) {
                                if ($d === 0) return 'same day';
                                if ($d === 1) return 'next day';
                                if ($d === -1) return 'day before';
                                return ($d > 0 ? '+' : '') . $d . ' days';
                            };
                            for ($d = 0; $d <= max(7, $max_day); $d++):
                                $chips = $by_day[$d] ?? [];
                        ?>
                          <div class="cps-tl-day" data-day="<?= $d ?>">
                            <div class="cps-tl-daylabel">Day <?= ($d > 0 ? '+' : '') . $d ?><span class="reltext"><?= $rel_text($d) ?></span></div>
                            <div class="cps-tl-chips">
                              <?php if (empty($chips)): ?>
                                <span class="cps-tl-empty">—</span>
                              <?php else: foreach ($chips as $ch): ?>
                                <span class="cps-tl-chip"><?= $offset_channels[$ch]['icon'] ?? '' ?> <?= e($offset_channels[$ch]['name']) ?></span>
                              <?php endforeach; endif; ?>
                            </div>
                          </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Per-channel offsets — edit + the timeline above updates live -->
                <div class="cps-tl-label">Per-channel offset (days after blog Day 0)</div>
                <div class="cps-grid">
                  <?php foreach ($offset_channels as $ch => $meta): ?>
                    <div class="cps-card">
                      <div class="ico"><?= $meta['icon'] ?></div>
                      <div class="meta">
                        <div class="nm"><?= e($meta['name']) ?></div>
                        <div class="sub"><?= e($meta['desc']) ?></div>
                      </div>
                      <div class="pick">
                        <input type="number" name="channel_offset_<?= e($ch) ?>" id="cps_offset_<?= e($ch) ?>"
                               value="<?= (int)($current_offsets[$ch] ?? 0) ?>" min="-7" max="14" step="1"
                               data-channel="<?= e($ch) ?>" onchange="cpsRefreshTimeline()">
                        <span class="unit">day(s)</span>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
            </div>

            <script>
            const CPS_CHANNELS = <?= json_encode(array_map(fn($m) => ['name' => $m['name'], 'icon' => $m['icon']], $offset_channels)) ?>;

            function cpsApplyPreset(offsets) {
              for (const ch in offsets) {
                const el = document.getElementById('cps_offset_' + ch);
                if (el) el.value = offsets[ch];
              }
              cpsRefreshTimeline();
            }

            function cpsRefreshTimeline() {
              const byDay = {};
              for (const ch in CPS_CHANNELS) {
                const el = document.getElementById('cps_offset_' + ch);
                if (!el) continue;
                const d = parseInt(el.value || 0, 10);
                if (!byDay[d]) byDay[d] = [];
                byDay[d].push(ch);
              }
              const days = Object.keys(byDay).map(Number);
              const maxDay = Math.max(7, ...(days.length ? days : [0]));
              const relText = (d) => d === 0 ? 'same day' : d === 1 ? 'next day' : d === -1 ? 'day before' : (d > 0 ? '+' : '') + d + ' days';

              const rail = document.getElementById('cps-timeline-rail');
              let html = '';
              for (let d = Math.min(0, ...(days.length ? days : [0])); d <= maxDay; d++) {
                const chips = byDay[d] || [];
                html += '<div class="cps-tl-day" data-day="' + d + '">';
                html += '<div class="cps-tl-daylabel">Day ' + (d > 0 ? '+' + d : d) + '<span class="reltext">' + relText(d) + '</span></div>';
                html += '<div class="cps-tl-chips">';
                if (chips.length === 0) {
                  html += '<span class="cps-tl-empty">—</span>';
                } else {
                  chips.forEach(ch => {
                    const c = CPS_CHANNELS[ch];
                    html += '<span class="cps-tl-chip">' + c.icon + ' ' + c.name + '</span>';
                  });
                }
                html += '</div></div>';
              }
              rail.innerHTML = html;
            }
            </script>

            <div class="setup-section">
                <h3>Weekly digest recipient</h3>
                <p class="desc">Optional. When set, weekly digest emails go here instead of the account login email.</p>
                <div class="form-group">
                    <input type="email" id="digest_email" name="digest_email" class="form-control" value="<?= e($site['digest_email'] ?? '') ?>" placeholder="reports@yourcompany.com">
                </div>
            </div>

            <div class="setup-actions">
                <button type="submit" class="btn btn-primary">Save publishing settings</button>
                <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>

    <?php elseif ($tab === 'channels'): ?>
        <div class="setup-section" style="margin-bottom:14px;">
            <h3>Channels connected to this site</h3>
            <p class="desc">
                Per-site authentication for distribution channels (LinkedIn page, Twitter handle, GSC property, Newsletter list). Platform-wide API keys (OpenAI, DataForSEO, etc.) live on the <a href="<?= url('/dashboard/integrations.php') ?>" style="color:var(--accent);">global Integrations page</a>.
            </p>
        </div>

        <div class="setup-channel-grid">
            <?php
            $channel_meta = [
                'cms'                    => ['name' => 'CMS (your website)', 'desc' => 'Push posts + legal pages to your CMS.', 'configure' => '/dashboard/setup.php?site=' . $site_id . '&tab=publishing', 'cta' => 'Configure'],
                'google_search_console'  => ['name' => 'Google Search Console', 'desc' => 'Track impressions, clicks, position from Google. Also unlocks Merchant Center diagnostics if you sell on Google Shopping.', 'configure' => google_get_auth_url($site_id), 'connect_url' => google_get_auth_url($site_id), 'reconnect_url' => google_get_auth_url($site_id), 'disconnect_action' => 'google_search_console', 'cta' => 'Connect Google'],
                'linkedin'               => ['name' => 'LinkedIn',  'desc' => 'Post to your LinkedIn page or personal profile.', 'configure' => '/dashboard/linkedin-author.php?site=' . $site_id, 'connect_url' => linkedin_get_auth_url($site_id), 'cta' => 'Connect LinkedIn'],
                'twitter'                => ['name' => 'Twitter / X', 'desc' => 'Auto-post threads to your X account.', 'configure' => '/dashboard/integrations.php#twitter', 'cta' => 'Connect Twitter'],
                'pinterest'              => ['name' => 'Pinterest', 'desc' => 'Auto-pin each blog post to your chosen board — high-buyer-intent traffic for visual products.', 'configure' => '/dashboard/pinterest-board.php?site=' . $site_id, 'connect_url' => url('/api/oauth/pinterest-install.php?site_id=' . $site_id), 'reconnect_url' => url('/api/oauth/pinterest-install.php?site_id=' . $site_id), 'disconnect_action' => 'pinterest', 'cta' => 'Connect Pinterest'],
                'newsletter'             => ['name' => 'Newsletter (Resend)', 'desc' => 'Send blog drops to your subscriber list.', 'configure' => '/dashboard/integrations.php#resend', 'cta' => 'Configure'],
            ];
            foreach ($channel_meta as $key => $meta):
                $row = $connected[$key] ?? null;
                $is_on = $row && !empty($row['is_active']);
                // Per-channel "effectively connected" checks: each channel has its
                // own way of being configured. CMS is per-site columns on `sites`;
                // newsletter is a global Resend wizard; everything else is OAuth in
                // the per-site `integrations` table.
                if ($key === 'cms') {
                    $effective_on = !empty($site['cms_url']) && !empty($site['cms_api_key']);
                } elseif ($key === 'newsletter') {
                    $effective_on = $resend_ready;
                } else {
                    $effective_on = $is_on;
                }
            ?>
                <div class="setup-channel">
                    <div class="head">
                        <span class="name"><?= e($meta['name']) ?></span>
                        <span class="badge <?= $effective_on ? 'ok' : 'off' ?>"><?= $effective_on ? '✓ Connected' : 'Not connected' ?></span>
                    </div>
                    <div class="meta">
                        <?= e($meta['desc']) ?>
                        <?php if ($row && !empty($row['account_name'])): ?>
                            <br><strong style="color:#0f172a;">Account:</strong> <?= e($row['account_name']) ?>
                        <?php elseif ($key === 'cms' && $effective_on): ?>
                            <br><strong style="color:#0f172a;">URL:</strong> <?= e($site['cms_url']) ?>
                        <?php elseif ($key === 'newsletter' && $effective_on): ?>
                            <br><strong style="color:#0f172a;">Provider:</strong> Resend (account-wide)
                        <?php endif; ?>
                    </div>
                    <?php
                        // For channels with an OAuth start URL (LinkedIn, Google), use it
                        // when not yet connected — `configure` is the post-connect manage
                        // page for everything else.
                        $cta_href = (!$effective_on && !empty($meta['connect_url']))
                            ? $meta['connect_url']
                            : url($meta['configure']);
                    ?>
                    <a class="cta" href="<?= e($cta_href) ?>"><?= $effective_on ? 'Manage →' : $meta['cta'] . ' →' ?></a>
                    <?php
                        // When already connected via OAuth, give explicit Reconnect +
                        // Disconnect controls. Reconnect re-fires the consent flow with
                        // the current scope list — used after scope additions like the
                        // new GMC `content` scope.
                        if ($effective_on && !empty($meta['reconnect_url'])):
                    ?>
                        <div style="display:flex; gap:8px; align-items:center; margin-top:8px; padding-top:8px; border-top:1px solid #f1f5f9;">
                            <a href="<?= e($meta['reconnect_url']) ?>" style="font-size:11px; color:#0c4a6e; text-decoration:none;" title="Re-run OAuth consent. Use this after we add new scopes (e.g. when Merchant Center support went live).">
                                &#x21bb; Reconnect (re-grant permissions)
                            </a>
                            <?php if (!empty($meta['disconnect_action'])): ?>
                                <span style="color:#cbd5e1;">·</span>
                                <form method="POST" action="<?= url('/api/oauth/disconnect.php') ?>" style="display:inline;" onsubmit="return confirm('Disconnect Google for this site? Your data is preserved — you can reconnect any time.')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="site_id" value="<?= $site_id ?>">
                                    <input type="hidden" name="platform" value="<?= e($meta['disconnect_action']) ?>">
                                    <input type="hidden" name="return_to" value="/dashboard/setup.php?site=<?= $site_id ?>&tab=channels">
                                    <button type="submit" style="background:none; border:0; color:#dc2626; font-size:11px; cursor:pointer; padding:0;">Disconnect</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="setup-section" style="margin-top:18px;">
            <h3>Always-on outputs (no setup needed)</h3>
            <p class="desc">These ship with every published post automatically — no per-site configuration required.</p>
            <div class="setup-channel-grid">
                <div class="setup-channel">
                    <div class="head"><span class="name">Schema.org JSON-LD</span><span class="badge ok">✓ Auto</span></div>
                    <div class="meta">Article + FAQPage + BreadcrumbList embedded in every post for SEO + AI crawlers.</div>
                </div>
                <div class="setup-channel">
                    <div class="head"><span class="name">llms.txt</span><span class="badge ok">✓ Auto</span></div>
                    <div class="meta">Auto-regenerated on every publish so AI search engines can discover your content.</div>
                </div>
                <div class="setup-channel">
                    <div class="head"><span class="name">ContentAgent-hosted pages</span><span class="badge ok">✓ Auto</span></div>
                    <div class="meta">Legal docs (privacy, terms, cookies) hosted by us — works for every customer regardless of CMS.</div>
                </div>
            </div>
        </div>

    <?php elseif ($tab === 'server'): ?>
        <form method="POST" action="<?= url('/dashboard/sites.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $site_id ?>">
            <?php /* Preserve other tabs */ ?>
            <input type="hidden" name="name" value="<?= e($site['name']) ?>">
            <input type="hidden" name="agent_mode" value="<?= e($site['agent_mode'] ?? 'manual') ?>">
            <input type="hidden" name="blog_path" value="<?= e($site['blog_path'] ?? '/blog') ?>">
            <input type="hidden" name="autonomy_mode" value="<?= e($site['autonomy_mode'] ?? 'review') ?>">
            <input type="hidden" name="posts_per_week" value="<?= (int)($site['posts_per_week'] ?? 2) ?>">
            <input type="hidden" name="cms_url" value="<?= e($site['cms_url'] ?? '') ?>">
            <input type="hidden" name="cms_api_key" value="<?= e($site['cms_api_key'] ?? '') ?>">
            <input type="hidden" name="digest_email" value="<?= e($site['digest_email'] ?? '') ?>">
            <input type="hidden" name="business_description" value="<?= e($site['business_description'] ?? '') ?>">
            <input type="hidden" name="topics" value="<?= e(implode(', ', json_decode($site['topics'] ?? '[]', true) ?: [])) ?>">
            <input type="hidden" name="persona" value="<?= e($site['persona'] ?? '') ?>">
            <input type="hidden" name="usp" value="<?= e($site['usp'] ?? '') ?>">
            <?php if (!empty($site['topics_confirmed'])): ?><input type="hidden" name="topics_confirmed" value="1"><?php endif; ?>
            <?php if ($profile_confirmed): ?><input type="hidden" name="profile_confirmed" value="1"><?php endif; ?>
            <input type="hidden" name="founding_year" value="<?= e((string)($site['founding_year'] ?? '')) ?>">
            <input type="hidden" name="employee_estimate" value="<?= e((string)($site['employee_estimate'] ?? '')) ?>">
            <input type="hidden" name="hq_city" value="<?= e($site['hq_city'] ?? '') ?>">
            <input type="hidden" name="hq_country" value="<?= e($site['hq_country'] ?? '') ?>">
            <input type="hidden" name="size_tier" value="<?= e($site['size_tier'] ?? '') ?>">
            <input type="hidden" name="business_model" value="<?= e($site['business_model'] ?? '') ?>">
            <input type="hidden" name="offering_type" value="<?= e($site['offering_type'] ?? '') ?>">
            <input type="hidden" name="customer_segment" value="<?= e($site['customer_segment'] ?? '') ?>">
            <input type="hidden" name="industry_category" value="<?= e($site['industry_category'] ?? '') ?>">
            <input type="hidden" name="industry_sub" value="<?= e($site['industry_sub'] ?? '') ?>">
            <input type="hidden" name="market_scope" value="<?= e($site['market_scope'] ?? '') ?>">
            <input type="hidden" name="maturity_tier" value="<?= e($site['maturity_tier'] ?? '') ?>">
            <input type="hidden" name="brand_fonts" value="<?= e(implode(', ', json_decode($site['brand_fonts'] ?? '[]', true) ?: [])) ?>">
            <?php for ($_ci = 0; $_ci < 3; $_ci++): $_cval = $brand_colors[$_ci] ?? ''; ?>
                <input type="hidden" name="brand_color_hex_<?= $_ci ?>" value="<?= e($_cval) ?>">
            <?php endfor; ?>

            <div class="setup-section">
                <h3>News feeds <span style="font-weight:400;color:#94a3b8;font-size:11px;">(optional)</span></h3>
                <p class="desc">Paste any RSS feeds you want ContentAgent to monitor for news relevant to your business. Feed items get filtered by your topics and surface in the News Scraper. Leave blank to disable.</p>
                <div class="form-group">
                    <textarea id="rss_feeds" name="rss_feeds" class="form-control" rows="5" placeholder="One feed URL per line"><?= e(implode("\n", $feeds)) ?></textarea>
                </div>
            </div>

            <div class="setup-section">
                <h3>Site status</h3>
                <p class="desc">Pause all automation for this site without deleting anything.</p>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                        <input type="checkbox" name="is_active" value="1" <?= !empty($site['is_active']) ? 'checked' : '' ?>>
                        Site is active (autopilot runs, scheduled posts go out)
                    </label>
                </div>
            </div>

            <div class="setup-section">
                <details style="background:#f8fafb;border:1px solid var(--border);border-radius:6px;padding:10px 14px;">
                    <summary style="cursor:pointer;font-size:13px;font-weight:600;color:#475569;">Advanced: direct server access</summary>
                    <p style="font-size:12px;color:#64748b;margin:10px 0 12px;line-height:1.55;">
                        Only needed if you want ContentAgent to write files (robots.txt, sitemap.xml) straight to your server instead of using a snippet. <strong>Most customers don't need this.</strong>
                    </p>
                    <div class="form-group"><label for="server_type">Access type</label>
                        <select id="server_type" name="server_type" class="form-control">
                            <option value="api_only" <?= ($site['server_type'] ?? '') === 'api_only' ? 'selected' : '' ?>>API Only (default — uses CMS API + JS snippet)</option>
                            <option value="ftp"    <?= ($site['server_type'] ?? '') === 'ftp' ? 'selected' : '' ?>>FTP</option>
                            <option value="sftp"   <?= ($site['server_type'] ?? '') === 'sftp' ? 'selected' : '' ?>>SFTP</option>
                            <option value="ssh"    <?= ($site['server_type'] ?? '') === 'ssh' ? 'selected' : '' ?>>SSH</option>
                            <option value="cpanel" <?= ($site['server_type'] ?? '') === 'cpanel' ? 'selected' : '' ?>>cPanel</option>
                        </select></div>
                    <div class="setup-grid-2">
                        <div class="form-group"><label for="server_host">Host / IP</label>
                            <input type="text" id="server_host" name="server_host" class="form-control" value="<?= e($site['server_host'] ?? '') ?>" placeholder="ftp.yourdomain.com or IP"></div>
                        <div class="form-group"><label for="server_path">Web root path</label>
                            <input type="text" id="server_path" name="server_path" class="form-control" value="<?= e($site['server_path'] ?? '') ?>" placeholder="/public_html"></div>
                        <div class="form-group"><label for="server_user">Username</label>
                            <input type="text" id="server_user" name="server_user" class="form-control" value="<?= e($site['server_user'] ?? '') ?>"></div>
                        <div class="form-group"><label for="server_pass">Password</label>
                            <input type="password" id="server_pass" name="server_pass" class="form-control" value="<?= e($site['server_pass'] ?? '') ?>"></div>
                    </div>
                </details>
            </div>

            <div class="setup-actions">
                <button type="submit" class="btn btn-primary">Save</button>
                <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>

    <?php elseif ($tab === 'danger'): ?>
        <div class="danger-card">
            <h3>⚠ Delete this site</h3>
            <p>
                Deleting <strong><?= e($site['name']) ?></strong> is <strong>permanent</strong>. Every post, keyword, audit, alert, subscriber, AEO query, competitor record, integration connection, content plan, and legal document for this site will be wiped. <strong>There is no undo.</strong>
            </p>
            <p>To confirm, type the site's exact name below: <code style="background:#fff;padding:2px 8px;border-radius:3px;font-weight:600;"><?= e($site['name']) ?></code></p>
            <form method="POST" action="<?= url('/dashboard/sites.php') ?>" onsubmit="return _confirmDelete(this, <?= json_encode($site['name']) ?>)" style="display:flex; gap:8px; align-items:center;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $site_id ?>">
                <input type="text" name="confirm_name" class="form-control" style="max-width:260px;" placeholder="Type site name here" autocomplete="off">
                <button type="submit" class="btn btn-danger btn-sm">Delete site and all data</button>
            </form>
        </div>
        <script>
        function _confirmDelete(form, expected) {
            var typed = form.querySelector('input[name=confirm_name]').value.trim();
            if (typed.toLowerCase() !== expected.toLowerCase()) {
                alert('Type the site name exactly to confirm. Expected: ' + expected);
                return false;
            }
            return confirm('Final check: permanently delete "' + expected + '" and ALL its data?');
        }
        </script>
    <?php endif; ?>

    </div>
</div>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
