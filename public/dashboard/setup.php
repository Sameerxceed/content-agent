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

auth_start();
auth_require();

$db      = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$site_id = (int)($_GET['site'] ?? $_GET['id'] ?? 0);
if (!$site_id) { redirect('/dashboard/index.php'); }

$site = auth_get_accessible_site($db, $site_id);
if (!$site) { http_response_code(403); exit('Access denied'); }

$valid_tabs = ['business', 'brand', 'publishing', 'channels', 'server', 'danger'];
$tab = in_array($_GET['tab'] ?? '', $valid_tabs, true) ? $_GET['tab'] : 'business';

// Per-site connected platforms (Channels tab) — fail-soft if integrations table missing.
$connected = [];
try {
    $stmt = $db->prepare('SELECT platform, is_active, status, account_label, connected_at, last_synced_at
                          FROM integrations WHERE site_id = ?');
    $stmt->execute([$site_id]);
    foreach ($stmt->fetchAll() as $row) {
        $connected[$row['platform']] = $row;
    }
} catch (PDOException $e) {
    // integrations table not present — leave $connected empty
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
.setup-body { padding:20px 24px; }
.setup-section { margin-bottom:22px; }
.setup-section + .setup-section { padding-top:18px; border-top:1px solid #f1f5f9; }
.setup-section h3 { font-size:13px; font-weight:600; color:var(--primary); margin:0 0 4px; text-transform:uppercase; letter-spacing:0.4px; }
.setup-section .desc { font-size:12px; color:#64748b; margin:0 0 12px; line-height:1.55; max-width:680px; }
.setup-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.setup-actions { margin-top:18px; padding-top:18px; border-top:1px solid #f1f5f9; display:flex; gap:8px; }
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
            'brand'      => ['label' => '🎨 Brand',       'pill' => null, 'warn' => false],
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
            <?php /* Preserve fields owned by other tabs so this Save doesn't blank them */ ?>
            <input type="hidden" name="agent_mode" value="<?= e($site['agent_mode'] ?? 'manual') ?>">
            <input type="hidden" name="blog_path" value="<?= e($site['blog_path'] ?? '/blog') ?>">
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
            <input type="hidden" name="brand_fonts" value="<?= e(implode(', ', json_decode($site['brand_fonts'] ?? '[]', true) ?: [])) ?>">
            <?php for ($_ci = 0; $_ci < 3; $_ci++): $_cval = $brand_colors[$_ci] ?? ''; ?>
                <input type="hidden" name="brand_color_hex_<?= $_ci ?>" value="<?= e($_cval) ?>">
            <?php endfor; ?>
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

                <div class="setup-grid-2">
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
                        <input type="text" id="industry_category" name="industry_category" class="form-control" value="<?= e($site['industry_category'] ?? '') ?>" placeholder="e.g. Tech consulting"></div>
                    <div class="form-group"><label for="industry_sub">Sub-category<?= $ai_tag('industry_sub') ?></label>
                        <input type="text" id="industry_sub" name="industry_sub" class="form-control" value="<?= e($site['industry_sub'] ?? '') ?>" placeholder="e.g. AI/ML services"></div>
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
                <div class="form-group"><label for="business_description">What does your business sell or offer?</label>
                    <textarea id="business_description" name="business_description" class="form-control" rows="2" placeholder="Describe what your business actually sells or offers, in your own words."><?= e($site['business_description'] ?? '') ?></textarea></div>
                <div class="form-group"><label for="topics">Main topics / products (comma-separated)</label>
                    <input type="text" id="topics" name="topics" class="form-control" value="<?= e(implode(', ', json_decode($site['topics'] ?? '[]', true) ?: [])) ?>" placeholder="e.g. software development, AI, web design">
                    <div class="text-sm text-muted" style="margin-top:4px;">3–6 short phrases work best.</div></div>
                <div class="form-group"><label for="persona">Who is your ideal customer? <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                    <textarea id="persona" name="persona" class="form-control" rows="2" placeholder="e.g. UK-based marketing managers at 50-200 person SaaS companies"><?= e($site['persona'] ?? '') ?></textarea></div>
                <div class="form-group"><label for="usp">What makes you different from competitors? <span class="text-muted" style="font-weight:400;">(your USP)</span></label>
                    <textarea id="usp" name="usp" class="form-control" rows="2" placeholder="e.g. Only platform that integrates GSC with AI-driven content briefs"><?= e($site['usp'] ?? '') ?></textarea></div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;font-size:12px;">
                        <input type="checkbox" name="topics_confirmed" value="1" <?= !empty($site['topics_confirmed']) ? 'checked' : '' ?>>
                        AI can use this for content + SEO work.
                    </label>
                </div>
            </div>

            <div class="setup-actions">
                <button type="submit" class="btn btn-primary">Save business settings</button>
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

    <?php elseif ($tab === 'brand'): ?>
        <form method="POST" action="<?= url('/dashboard/sites.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $site_id ?>">
            <?php /* Preserve fields owned by other tabs */ ?>
            <input type="hidden" name="name" value="<?= e($site['name']) ?>">
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
            <?php if (!empty($site['is_active'])): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>

            <div class="setup-section">
                <h3>Brand colors</h3>
                <p class="desc">Used by social carousels, blog theme, hero images. Pick up to 3 — primary, accent, secondary.</p>
                <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
                <?php for ($ci = 0; $ci < 3; $ci++): $cval = $brand_colors[$ci] ?? ''; ?>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <input type="color" name="brand_color_<?= $ci ?>" value="<?= e($cval ?: '#1B3A6B') ?>" style="width:36px;height:36px;border:1px solid #ddd;border-radius:4px;cursor:pointer;padding:0;">
                        <input type="text" name="brand_color_hex_<?= $ci ?>" value="<?= e($cval) ?>" class="form-control" style="width:100px;font-size:12px;font-family:monospace;" placeholder="#hex" oninput="this.previousElementSibling.value=this.value">
                    </div>
                <?php endfor; ?>
                </div>
            </div>

            <div class="setup-section">
                <h3>Brand fonts</h3>
                <p class="desc">Used in image generation and embeds. Comma-separated, in order of preference.</p>
                <div class="form-group">
                    <input type="text" id="brand_fonts" name="brand_fonts" class="form-control" value="<?= e(implode(', ', json_decode($site['brand_fonts'] ?? '[]', true) ?: [])) ?>" placeholder="e.g. Space Grotesk, Inter">
                </div>
            </div>

            <div class="setup-section">
                <h3>Blog path</h3>
                <p class="desc">The URL prefix where your blog posts live, used to construct canonical URLs.</p>
                <div class="form-group">
                    <input type="text" id="blog_path" name="blog_path" class="form-control" value="<?= e($site['blog_path'] ?? '/blog') ?>" placeholder="/blog">
                </div>
            </div>

            <div class="setup-actions">
                <button type="submit" class="btn btn-primary">Save brand settings</button>
                <a href="<?= url('/dashboard/site.php?id=' . $site_id) ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>

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
                            <option value="review"    <?= $_am === 'review'    ? 'selected' : '' ?>>Review-each (default)</option>
                            <option value="hands_off" <?= $_am === 'hands_off' ? 'selected' : '' ?> disabled>Hands-off (v2)</option>
                            <option value="manual"    <?= $_am === 'manual'    ? 'selected' : '' ?>>Manual (no autopilot)</option>
                        </select></div>
                </div>
                <div class="form-group" style="margin-top:8px;"><label for="agent_mode">Auto-publish mode (legacy news flow)</label>
                    <select id="agent_mode" name="agent_mode" class="form-control">
                        <option value="manual" <?= ($site['agent_mode'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual (approve before publishing)</option>
                        <option value="auto" <?= ($site['agent_mode'] ?? '') === 'auto' ? 'selected' : '' ?>>Auto (publish immediately)</option>
                    </select></div>
            </div>

            <div class="setup-section">
                <h3>Push destination — your CMS</h3>
                <p class="desc">Optional. When set, every approved post is also pushed to your own CMS via this API. Failure here doesn't block — ContentAgent always hosts a working copy.</p>
                <div class="setup-grid-2">
                    <div class="form-group"><label for="cms_url">CMS URL</label>
                        <input type="text" id="cms_url" name="cms_url" class="form-control" value="<?= e($site['cms_url'] ?? '') ?>" placeholder="https://cms.yourdomain.com"></div>
                    <div class="form-group"><label for="cms_api_key">CMS API key</label>
                        <input type="text" id="cms_api_key" name="cms_api_key" class="form-control" value="<?= e($site['cms_api_key'] ?? '') ?>" placeholder="your-api-key"></div>
                </div>
            </div>

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
                'google_search_console'  => ['name' => 'Google Search Console', 'desc' => 'Track impressions, clicks, position from Google.', 'configure' => '/dashboard/integrations.php#gsc', 'cta' => 'Connect via OAuth'],
                'linkedin'               => ['name' => 'LinkedIn',  'desc' => 'Post to your LinkedIn page or personal profile.', 'configure' => '/dashboard/linkedin-author.php?site=' . $site_id, 'cta' => 'Connect LinkedIn'],
                'twitter'                => ['name' => 'Twitter / X', 'desc' => 'Auto-post threads to your X account.', 'configure' => '/dashboard/integrations.php#twitter', 'cta' => 'Connect Twitter'],
                'newsletter'             => ['name' => 'Newsletter (Resend)', 'desc' => 'Send blog drops to your subscriber list.', 'configure' => '/dashboard/integrations.php#resend', 'cta' => 'Configure'],
            ];
            foreach ($channel_meta as $key => $meta):
                $row = $connected[$key] ?? null;
                $is_on = $row && !empty($row['is_active']);
                $cms_ok = $key === 'cms' && !empty($site['cms_url']) && !empty($site['cms_api_key']);
                $effective_on = $is_on || $cms_ok;
            ?>
                <div class="setup-channel">
                    <div class="head">
                        <span class="name"><?= e($meta['name']) ?></span>
                        <span class="badge <?= $effective_on ? 'ok' : 'off' ?>"><?= $effective_on ? '✓ Connected' : 'Not connected' ?></span>
                    </div>
                    <div class="meta">
                        <?= e($meta['desc']) ?>
                        <?php if ($row && !empty($row['account_label'])): ?>
                            <br><strong style="color:#0f172a;">Account:</strong> <?= e($row['account_label']) ?>
                        <?php elseif ($cms_ok): ?>
                            <br><strong style="color:#0f172a;">URL:</strong> <?= e($site['cms_url']) ?>
                        <?php endif; ?>
                    </div>
                    <a class="cta" href="<?= url($meta['configure']) ?>"><?= $effective_on ? 'Reconfigure →' : $meta['cta'] . ' →' ?></a>
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
                <h3>Server access (for direct code pushes)</h3>
                <p class="desc">Used by ContentAgent to push SEO fixes, redirects, schema, llms.txt directly to your server. Leave blank if you're happy with the CMS-API + JS-snippet route.</p>
                <div class="form-group"><label for="server_type">Access type</label>
                    <select id="server_type" name="server_type" class="form-control">
                        <option value="api_only" <?= ($site['server_type'] ?? '') === 'api_only' ? 'selected' : '' ?>>API Only (CMS API + JS Snippet)</option>
                        <option value="ftp"    <?= ($site['server_type'] ?? '') === 'ftp' ? 'selected' : '' ?>>FTP</option>
                        <option value="sftp"   <?= ($site['server_type'] ?? '') === 'sftp' ? 'selected' : '' ?>>SFTP</option>
                        <option value="ssh"    <?= ($site['server_type'] ?? '') === 'ssh' ? 'selected' : '' ?>>SSH</option>
                        <option value="cpanel" <?= ($site['server_type'] ?? '') === 'cpanel' ? 'selected' : '' ?>>cPanel</option>
                        <option value="git"    <?= ($site['server_type'] ?? '') === 'git' ? 'selected' : '' ?>>Git (push to repo)</option>
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
                    <div class="form-group"><label for="git_repo">Git repo URL</label>
                        <input type="text" id="git_repo" name="git_repo" class="form-control" value="<?= e($site['git_repo'] ?? '') ?>" placeholder="https://github.com/user/repo.git"></div>
                    <div class="form-group"><label for="hosting_panel">Hosting panel</label>
                        <select id="hosting_panel" name="hosting_panel" class="form-control">
                            <option value="">None</option>
                            <?php foreach (['cpanel','plesk','vercel','netlify','aws','digitalocean','linode'] as $hp): ?>
                                <option value="<?= $hp ?>" <?= ($site['hosting_panel'] ?? '') === $hp ? 'selected' : '' ?>><?= e(ucfirst($hp)) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                </div>
            </div>

            <div class="setup-section">
                <h3>RSS news feeds</h3>
                <p class="desc">News from these feeds gets filtered by your topics and surfaces in the News Scraper agent.</p>
                <div class="form-group">
                    <textarea id="rss_feeds" name="rss_feeds" class="form-control" rows="5" placeholder="https://techcrunch.com/feed/&#10;https://www.wired.com/feed/rss"><?= e(implode("\n", $feeds)) ?></textarea>
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

            <div class="setup-actions">
                <button type="submit" class="btn btn-primary">Save server &amp; feed settings</button>
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
