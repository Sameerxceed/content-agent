<?php
/**
 * Dashboard — Sites management.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/site_delete.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();
$action = $_GET['action'] ?? 'list';

// ── Handle form submissions ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash_error'] = 'Invalid form submission.';
        redirect('/dashboard/sites.php');
    }

    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'add') {
        $url = trim($_POST['url'] ?? '');
        $name = trim($_POST['name'] ?? '');

        if (empty($url)) {
            $_SESSION['flash_error'] = 'Please enter a website URL.';
            redirect('/dashboard/sites.php?action=add');
        }

        // Clean domain
        $parsed = parse_url($url);
        $domain = $parsed['host'] ?? $url;
        $domain = preg_replace('/^www\./', '', $domain);
        $name = $name ?: $domain;

        $stmt = $db->prepare('INSERT INTO sites (user_id, name, domain) VALUES (?, ?, ?)');
        $stmt->execute([$user_id, $name, $domain]);
        $new_id = $db->lastInsertId();

        $_SESSION['flash_success'] = "Site '{$name}' added!";
        redirect('/dashboard/site.php?id=' . $new_id);
    }

    if ($post_action === 'update') {
        $id = (int)$_POST['id'];

        // Verify access (owner OR super-admin)
        if (!auth_can_access_site($db, $id)) {
            $_SESSION['flash_error'] = 'Site not found.';
            redirect('/dashboard/sites.php');
        }

        $topics_arr = array_filter(array_map('trim', explode(',', $_POST['topics'] ?? '')));

        // Brand colors from color pickers
        $brand_colors = [];
        for ($ci = 0; $ci < 3; $ci++) {
            $hex = trim($_POST["brand_color_hex_{$ci}"] ?? '');
            if ($hex && preg_match('/^#[0-9a-fA-F]{3,8}$/', $hex)) {
                $brand_colors[] = $hex;
            }
        }

        // Brand fonts
        $brand_fonts = array_filter(array_map('trim', explode(',', $_POST['brand_fonts'] ?? '')));

        // Business profile fields — clamp enums to allowed values so a tampered
        // POST can't insert an arbitrary string into an ENUM column.
        $enum = function(string $key, array $allowed): ?string {
            $v = strtolower(trim($_POST[$key] ?? ''));
            return ($v !== '' && in_array($v, $allowed, true)) ? $v : null;
        };
        $int_or_null = function(string $key, int $min, int $max): ?int {
            $v = trim($_POST[$key] ?? '');
            if ($v === '' || !is_numeric($v)) return null;
            $n = (int)$v;
            return ($n >= $min && $n <= $max) ? $n : null;
        };

        $bp_size_tier        = $enum('size_tier',        ['solo','small','mid','large','enterprise']);
        $bp_business_model   = $enum('business_model',   ['b2b','b2c','b2b2c','nonprofit','marketplace']);
        $bp_offering_type    = $enum('offering_type',    ['service','product','hybrid']);
        $bp_customer_segment = $enum('customer_segment', ['consumer','smb','midmarket','enterprise','mixed']);
        $bp_market_scope     = $enum('market_scope',     ['local','regional','national','global']);
        $bp_maturity_tier    = $enum('maturity_tier',    ['bootstrapped','established','category_leader','public_company']);
        $bp_founding_year    = $int_or_null('founding_year', 1900, 2030);
        $bp_employee_est     = $int_or_null('employee_estimate', 1, 1000000);

        $stmt = $db->prepare('UPDATE sites SET name = ?, agent_mode = ?, blog_path = ?, topics = ?, topics_confirmed = ?,
            business_description = ?, persona = ?, usp = ?,
            founding_year = ?, hq_city = ?, hq_country = ?, size_tier = ?, employee_estimate = ?,
            business_model = ?, offering_type = ?, industry_category = ?, industry_sub = ?,
            customer_segment = ?, market_scope = ?, maturity_tier = ?, profile_confirmed = ?,
            rss_feeds = ?, cms_url = ?, cms_api_key = ?, server_type = ?, server_host = ?, server_user = ?, server_pass = ?, server_path = ?, git_repo = ?, hosting_panel = ?,
            brand_colors = ?, brand_fonts = ?, is_active = ?, digest_email = ?,
            autonomy_mode = ?, posts_per_week = ?
            WHERE id = ?');
        $stmt->execute([
            trim($_POST['name']),
            $_POST['agent_mode'] ?? 'manual',
            trim($_POST['blog_path'] ?? '/blog'),
            json_encode(array_values($topics_arr)),
            isset($_POST['topics_confirmed']) ? 1 : 0,
            trim($_POST['business_description'] ?? '') ?: null,
            trim($_POST['persona'] ?? '') ?: null,
            trim($_POST['usp'] ?? '') ?: null,
            $bp_founding_year,
            trim($_POST['hq_city'] ?? '') ?: null,
            trim($_POST['hq_country'] ?? '') ?: null,
            $bp_size_tier,
            $bp_employee_est,
            $bp_business_model,
            $bp_offering_type,
            trim($_POST['industry_category'] ?? '') ?: null,
            trim($_POST['industry_sub'] ?? '') ?: null,
            $bp_customer_segment,
            $bp_market_scope,
            $bp_maturity_tier,
            isset($_POST['profile_confirmed']) ? 1 : 0,
            json_encode(array_filter(array_map('trim', explode("\n", $_POST['rss_feeds'] ?? '')))),
            trim($_POST['cms_url'] ?? '') ?: null,
            trim($_POST['cms_api_key'] ?? '') ?: null,
            $_POST['server_type'] ?? 'api_only',
            trim($_POST['server_host'] ?? '') ?: null,
            trim($_POST['server_user'] ?? '') ?: null,
            trim($_POST['server_pass'] ?? '') ?: null,
            trim($_POST['server_path'] ?? '') ?: null,
            trim($_POST['git_repo'] ?? '') ?: null,
            trim($_POST['hosting_panel'] ?? '') ?: null,
            json_encode($brand_colors),
            json_encode(array_values($brand_fonts)),
            isset($_POST['is_active']) ? 1 : 0,
            trim($_POST['digest_email'] ?? '') ?: null,
            in_array($_POST['autonomy_mode'] ?? '', ['review', 'hands_off', 'manual'], true) ? $_POST['autonomy_mode'] : 'review',
            max(1, min(7, (int)($_POST['posts_per_week'] ?? 2))),
            $id,
        ]);

        $_SESSION['flash_success'] = 'Site settings updated.';
        redirect('/dashboard/site.php?id=' . $id);
    }

    if ($post_action === 'delete') {
        $id = (int)$_POST['id'];

        if (!auth_can_access_site($db, $id)) {
            $_SESSION['flash_error'] = 'Site not found.';
            redirect('/dashboard/sites.php');
        }

        // Typed-name confirmation prevents accidental clicks.
        $typed   = trim($_POST['confirm_name'] ?? '');
        $stmt    = $db->prepare('SELECT name FROM sites WHERE id = ?');
        $stmt->execute([$id]);
        $expected = $stmt->fetchColumn();
        if ($expected !== false && strcasecmp($typed, $expected) !== 0) {
            $_SESSION['flash_error'] = 'You must type the site name exactly to confirm deletion.';
            redirect('/dashboard/setup.php?site=' . $id . '&tab=danger');
        }

        $result = site_delete_cascade($db, $id);
        if (!empty($result['success'])) {
            $_SESSION['flash_success'] = "Site '" . $result['site_name'] . "' deleted ("
                . $result['total_rows'] . " rows across "
                . count($result['counts']) . " tables).";
            redirect('/dashboard/index.php');
        } else {
            $_SESSION['flash_error'] = 'Delete failed: ' . ($result['error'] ?? 'unknown');
            redirect('/dashboard/setup.php?site=' . $id . '&tab=danger');
        }
    }
}

// ── Page content ────────────────────────────────────────
$page_title = 'Sites';

ob_start();

if ($action === 'add'):
?>
    <div class="card" style="max-width: 500px;">
        <div class="card-header">Add New Site</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">

            <div class="form-group">
                <label for="url">Website URL</label>
                <input type="text" id="url" name="url" class="form-control" placeholder="example.com or https://example.com" required autofocus>
            </div>

            <div class="form-group">
                <label for="name">Site Name (optional)</label>
                <input type="text" id="name" name="name" class="form-control" placeholder="My Website">
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-accent">Add Site</button>
                <a href="<?= url('/dashboard/sites.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>

<?php elseif ($action === 'view' && isset($_GET['id'])):
    redirect('/dashboard/site.php?id=' . (int)$_GET['id']);
    exit;
?>

<?php elseif ($action === 'edit' && isset($_GET['id'])):
    // The long single-form edit page has been replaced by /dashboard/setup.php
    // which organises the same fields into tabs. Backwards-compat redirect so any
    // bookmarks, dashboard links, or emails pointing at the old URL still work.
    redirect('/dashboard/setup.php?site=' . (int)$_GET['id']);
    exit;
?>

<?php elseif (false): /* dead branch — preserved below for git-history reference; never executes */
    $site = auth_get_accessible_site($db, (int)$_GET['id']);

    if (!$site):
        echo '<div class="alert alert-error">Site not found.</div>';
    else:
        $feeds = json_decode($site['rss_feeds'] ?? '[]', true) ?: [];
        $site_id = (int)$site['id'];
        $stepper_active = 'scan';
        include __DIR__ . '/_site_stepper.php';
?>
    <?php
        // Business profile state for the new top section
        $profile_confidence = json_decode($site['profile_confidence'] ?? '{}', true) ?: [];
        $profile_signals    = json_decode($site['profile_signals'] ?? '{}', true) ?: [];
        $profile_inferred   = !empty($site['profile_inferred_at']);
        $profile_confirmed  = !empty($site['profile_confirmed']);

        // Tiny helper: render an "AI guess" pill next to a field's label
        // when that field has an inferred confidence value > 0.
        $ai_tag = function(string $field) use ($profile_confidence, $profile_signals, $profile_confirmed) {
            $conf = $profile_confidence[$field] ?? null;
            if ($conf === null || $profile_confirmed) return '';
            $bg = $conf >= 0.7 ? '#d1fae5' : ($conf >= 0.4 ? '#fef3c7' : '#fee2e2');
            $fg = $conf >= 0.7 ? '#065f46' : ($conf >= 0.4 ? '#92400e' : '#991b1b');
            $tip = !empty($profile_signals[$field]) ? ' title="' . e((string)$profile_signals[$field]) . '"' : '';
            return ' <span' . $tip . ' style="font-size:10px;font-weight:500;padding:1px 6px;border-radius:8px;background:' . $bg . ';color:' . $fg . ';margin-left:6px;">&#10024; AI guess</span>';
        };
    ?>
    <div class="card" style="max-width: 600px;">
        <div class="card-header">Edit: <?= e($site['name']) ?></div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $site['id'] ?>">

            <div class="form-group">
                <label for="name">Site Name</label>
                <input type="text" id="name" name="name" class="form-control" value="<?= e($site['name']) ?>">
            </div>

            <!-- 🎯 Business Profile — the single source of truth every AI agent reads from -->
            <div id="business-profile" style="margin-top:6px;padding:14px 16px;border:1px solid <?= $profile_confirmed ? '#86efac' : '#fcd34d' ?>;border-radius:8px;background:<?= $profile_confirmed ? '#f0fdf4' : '#fffbeb' ?>;margin-bottom:14px;">
                <div style="display:flex;justify-content:space-between;align-items:start;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:600;font-size:14px;color:<?= $profile_confirmed ? '#065f46' : '#92400e' ?>;">
                            <?= $profile_confirmed ? '&#10003; Business profile confirmed' : '&#9888; We figured out your business &mdash; please confirm' ?>
                        </div>
                        <div style="font-size:11px;color:#64748b;margin-top:2px;">
                            <?php if ($profile_inferred): ?>
                                AI scanned your homepage + about/team pages on <?= format_date($site['profile_inferred_at'], 'd M Y, h:i A') ?>.
                            <?php else: ?>
                                Run a scan from the SEO/AEO page to have AI fill these in automatically.
                            <?php endif; ?>
                            These fields drive every downstream agent (competitors, blog writer, keyword research, AEO, brand presence). If something is wrong, fix it here.
                        </div>
                    </div>
                    <?php if ($profile_inferred): ?>
                    <button type="button" onclick="reanalyseProfile(<?= (int)$site['id'] ?>, this)" class="btn btn-outline btn-sm" style="white-space:nowrap;font-size:11px;">&#128260; Re-analyse with AI</button>
                    <?php endif; ?>
                </div>

                <div class="grid-2" style="margin-top:8px;">
                    <div class="form-group">
                        <label for="founding_year">Founded (year)<?= $ai_tag('founding_year') ?></label>
                        <input type="number" id="founding_year" name="founding_year" class="form-control" min="1900" max="2030" value="<?= e((string)($site['founding_year'] ?? '')) ?>" placeholder="e.g. 2014">
                    </div>
                    <div class="form-group">
                        <label for="employee_estimate">Approx. team size<?= $ai_tag('employee_estimate') ?></label>
                        <input type="number" id="employee_estimate" name="employee_estimate" class="form-control" min="1" value="<?= e((string)($site['employee_estimate'] ?? '')) ?>" placeholder="e.g. 15">
                    </div>
                    <div class="form-group">
                        <label for="hq_city">HQ city<?= $ai_tag('hq_city') ?></label>
                        <input type="text" id="hq_city" name="hq_city" class="form-control" value="<?= e($site['hq_city'] ?? '') ?>" placeholder="e.g. Pune">
                    </div>
                    <div class="form-group">
                        <label for="hq_country">HQ country<?= $ai_tag('hq_country') ?></label>
                        <input type="text" id="hq_country" name="hq_country" class="form-control" value="<?= e($site['hq_country'] ?? '') ?>" placeholder="e.g. India">
                    </div>
                    <div class="form-group">
                        <label for="size_tier">Company size tier<?= $ai_tag('size_tier') ?></label>
                        <select id="size_tier" name="size_tier" class="form-control">
                            <option value="">—</option>
                            <?php foreach ([
                                'solo'       => 'Solo (1 person)',
                                'small'      => 'Small (2–10)',
                                'mid'        => 'Mid (11–50)',
                                'large'      => 'Large (51–500)',
                                'enterprise' => 'Enterprise (500+)',
                            ] as $v => $lbl): ?>
                                <option value="<?= $v ?>" <?= ($site['size_tier'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="business_model">Business model<?= $ai_tag('business_model') ?></label>
                        <select id="business_model" name="business_model" class="form-control">
                            <option value="">—</option>
                            <?php foreach ([
                                'b2b'         => 'B2B (sells to businesses)',
                                'b2c'         => 'B2C (sells to consumers)',
                                'b2b2c'       => 'B2B2C (via partners)',
                                'marketplace' => 'Marketplace',
                                'nonprofit'   => 'Nonprofit',
                            ] as $v => $lbl): ?>
                                <option value="<?= $v ?>" <?= ($site['business_model'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="offering_type">Offering<?= $ai_tag('offering_type') ?></label>
                        <select id="offering_type" name="offering_type" class="form-control">
                            <option value="">—</option>
                            <?php foreach (['service' => 'Service', 'product' => 'Product', 'hybrid' => 'Hybrid (both)'] as $v => $lbl): ?>
                                <option value="<?= $v ?>" <?= ($site['offering_type'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="customer_segment">Sells to<?= $ai_tag('customer_segment') ?></label>
                        <select id="customer_segment" name="customer_segment" class="form-control">
                            <option value="">—</option>
                            <?php foreach ([
                                'consumer'   => 'Consumers',
                                'smb'        => 'Small businesses',
                                'midmarket'  => 'Mid-market',
                                'enterprise' => 'Enterprise',
                                'mixed'      => 'Mixed',
                            ] as $v => $lbl): ?>
                                <option value="<?= $v ?>" <?= ($site['customer_segment'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="industry_category">Industry<?= $ai_tag('industry_category') ?></label>
                        <input type="text" id="industry_category" name="industry_category" class="form-control" value="<?= e($site['industry_category'] ?? '') ?>" placeholder="e.g. Tech consulting">
                    </div>
                    <div class="form-group">
                        <label for="industry_sub">Sub-category<?= $ai_tag('industry_sub') ?></label>
                        <input type="text" id="industry_sub" name="industry_sub" class="form-control" value="<?= e($site['industry_sub'] ?? '') ?>" placeholder="e.g. AI/ML services">
                    </div>
                    <div class="form-group">
                        <label for="market_scope">Market scope<?= $ai_tag('market_scope') ?></label>
                        <select id="market_scope" name="market_scope" class="form-control">
                            <option value="">—</option>
                            <?php foreach ([
                                'local'    => 'Local (city)',
                                'regional' => 'Regional (state/region)',
                                'national' => 'National (one country)',
                                'global'   => 'Global',
                            ] as $v => $lbl): ?>
                                <option value="<?= $v ?>" <?= ($site['market_scope'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="maturity_tier">Maturity<?= $ai_tag('maturity_tier') ?></label>
                        <select id="maturity_tier" name="maturity_tier" class="form-control">
                            <option value="">—</option>
                            <?php foreach ([
                                'bootstrapped'    => 'Bootstrapped / early',
                                'established'     => 'Established',
                                'category_leader' => 'Category leader',
                                'public_company'  => 'Public company',
                            ] as $v => $lbl): ?>
                                <option value="<?= $v ?>" <?= ($site['maturity_tier'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-top:6px;padding-top:10px;border-top:1px solid rgba(0,0,0,0.06);">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;font-size:13px;">
                        <input type="checkbox" name="profile_confirmed" value="1" <?= $profile_confirmed ? 'checked' : '' ?>>
                        <span>I've reviewed the above. AI agents may now use this profile to ground every decision (competitors, blog writing, keyword targeting, etc.).</span>
                    </label>
                </div>
            </div>

            <script>
            async function reanalyseProfile(siteId, btn) {
                if (!confirm('Re-run the AI scan? This overwrites the inferred fields (any you have NOT manually confirmed) and takes 10-20 seconds.')) return;
                const orig = btn.textContent;
                btn.disabled = true; btn.textContent = 'Analysing...';
                try {
                    const res = await fetch('<?= url('/api/business-profile-reanalyse.php') ?>', {
                        method:'POST', headers:{'Content-Type':'application/json'},
                        body: JSON.stringify({site_id: siteId})
                    });
                    const data = await res.json();
                    if (data.success) { location.reload(); return; }
                    alert('Failed: ' + (data.error || 'unknown'));
                    btn.disabled = false; btn.textContent = orig;
                } catch(e) {
                    alert('Error: ' + e.message);
                    btn.disabled = false; btn.textContent = orig;
                }
            }
            </script>

            <div style="padding:12px 0;border-bottom:1px solid var(--border);margin-bottom:14px;">
                <div class="text-sm" style="font-weight:600;margin-bottom:8px;">Brand Colors</div>
                <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
                    <?php
                    $brand_colors = json_decode($site['brand_colors'] ?? '[]', true) ?: [];
                    for ($ci = 0; $ci < 3; $ci++):
                        $cval = $brand_colors[$ci] ?? '';
                    ?>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <input type="color" name="brand_color_<?= $ci ?>" value="<?= e($cval ?: '#1B3A6B') ?>" style="width:36px;height:36px;border:1px solid #ddd;border-radius:4px;cursor:pointer;padding:0;">
                        <input type="text" name="brand_color_hex_<?= $ci ?>" value="<?= e($cval) ?>" class="form-control" style="width:90px;font-size:12px;font-family:monospace;" placeholder="#hex" oninput="this.previousElementSibling.value=this.value">
                    </div>
                    <?php endfor; ?>
                    <span class="text-sm text-muted">Primary, Accent, Secondary</span>
                </div>
                <div class="text-sm text-muted mt-2">Used for carousels, blog theme, and social posts. Override scanner-detected colors here.</div>
            </div>

            <div class="form-group">
                <label for="brand_fonts">Brand Fonts (comma-separated)</label>
                <input type="text" id="brand_fonts" name="brand_fonts" class="form-control" value="<?= e(implode(', ', json_decode($site['brand_fonts'] ?? '[]', true) ?: [])) ?>" placeholder="e.g. Space Grotesk, Inter">
            </div>

            <div class="form-group">
                <label for="agent_mode">Agent Mode</label>
                <select id="agent_mode" name="agent_mode" class="form-control">
                    <option value="manual" <?= $site['agent_mode'] === 'manual' ? 'selected' : '' ?>>Manual (approve posts before publishing)</option>
                    <option value="auto" <?= $site['agent_mode'] === 'auto' ? 'selected' : '' ?>>Auto (publish immediately)</option>
                </select>
            </div>

            <div class="form-group">
                <label for="blog_path">Blog Path</label>
                <input type="text" id="blog_path" name="blog_path" class="form-control" value="<?= e($site['blog_path']) ?>">
            </div>

            <!-- Content Plan settings — drive the autopilot cadence + autonomy mode -->
            <div style="margin-top:14px;padding:12px 14px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:6px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#6d28d9;font-weight:600;margin-bottom:8px;">📋 Content Plan settings</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div class="form-group" style="margin:0;">
                        <label for="posts_per_week" style="font-size:12px;color:#5b21b6;">Publishing cadence</label>
                        <select id="posts_per_week" name="posts_per_week" class="form-control" style="font-size:13px;">
                            <?php $_pw = (int)($site['posts_per_week'] ?? 2); ?>
                            <option value="1" <?= $_pw === 1 ? 'selected' : '' ?>>1 post/week (13/quarter)</option>
                            <option value="2" <?= $_pw === 2 ? 'selected' : '' ?>>2 posts/week (26/quarter) — recommended</option>
                            <option value="3" <?= $_pw === 3 ? 'selected' : '' ?>>3 posts/week (39/quarter)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label for="autonomy_mode" style="font-size:12px;color:#5b21b6;">Autopilot autonomy</label>
                        <select id="autonomy_mode" name="autonomy_mode" class="form-control" style="font-size:13px;">
                            <?php $_am = (string)($site['autonomy_mode'] ?? 'review'); ?>
                            <option value="review"    <?= $_am === 'review'    ? 'selected' : '' ?>>Review-each (default — explicit approval per post)</option>
                            <option value="hands_off" <?= $_am === 'hands_off' ? 'selected' : '' ?> disabled>Hands-off (auto-approve after 24h) — coming in v2</option>
                            <option value="manual"    <?= $_am === 'manual'    ? 'selected' : '' ?>>Manual (no autopilot drafting)</option>
                        </select>
                    </div>
                </div>
                <div style="font-size:11px;color:#6b21a8;margin-top:8px;line-height:1.5;">
                    Used by the Content Plan autopilot. Cadence controls how many items per week the plan schedules.
                    Autonomy decides whether drafts wait for your approval (Review-each) or never get drafted at all (Manual).
                </div>
            </div>

            <div id="focus" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
                <div class="text-sm" style="font-weight:600;margin-bottom:10px;">🎯 Business Focus</div>
                <p class="text-sm text-muted mb-2">This drives every AI decision — keyword research, content writing, SEO suggestions. <strong>If this is wrong, everything downstream will be wrong.</strong></p>

                <div class="form-group">
                    <label for="business_description">What does your business sell or offer?</label>
                    <textarea id="business_description" name="business_description" class="form-control" rows="2" placeholder="Describe what your business actually sells or offers, in your own words."><?= e($site['business_description'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="topics">Main topics / products (comma-separated)</label>
                    <input type="text" id="topics" name="topics" class="form-control" value="<?= e(implode(', ', json_decode($site['topics'] ?? '[]', true) ?: [])) ?>" placeholder="e.g. software development, AI, web design">
                    <div class="text-sm text-muted mt-2">3–6 short phrases work best. Also used for news scraping relevance.</div>
                </div>

                <div class="form-group">
                    <label for="persona">Who is your ideal customer? <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                    <textarea id="persona" name="persona" class="form-control" rows="2" placeholder="e.g. UK-based marketing managers at 50-200 person SaaS companies"><?= e($site['persona'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="usp">What makes you different from competitors? <span class="text-muted" style="font-weight:400;">(your USP)</span></label>
                    <textarea id="usp" name="usp" class="form-control" rows="2" placeholder="e.g. Only platform that integrates GSC with AI-driven content briefs"><?= e($site['usp'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;">
                        <input type="checkbox" name="topics_confirmed" value="1" <?= !empty($site['topics_confirmed']) ? 'checked' : '' ?>>
                        <span>I've reviewed the above and confirm AI can use it for content + SEO work</span>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="rss_feeds">RSS News Feeds (one per line)</label>
                <textarea id="rss_feeds" name="rss_feeds" class="form-control" rows="5" placeholder="https://techcrunch.com/feed/&#10;https://www.wired.com/feed/rss"><?= e(implode("\n", $feeds)) ?></textarea>
                <div class="text-sm text-muted mt-2">News from these feeds will be filtered by your topics/keywords.</div>
            </div>

            <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
                <div class="text-sm" style="font-weight:600;margin-bottom:10px;">🔌 CMS / Content API</div>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="cms_url">CMS URL</label>
                        <input type="text" id="cms_url" name="cms_url" class="form-control" value="<?= e($site['cms_url'] ?? '') ?>" placeholder="https://cms.yourdomain.com">
                    </div>
                    <div class="form-group">
                        <label for="cms_api_key">CMS API Key</label>
                        <input type="text" id="cms_api_key" name="cms_api_key" class="form-control" value="<?= e($site['cms_api_key'] ?? '') ?>" placeholder="your-api-key">
                    </div>
                </div>
            </div>

            <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
                <div class="text-sm" style="font-weight:600;margin-bottom:10px;">🖥️ Server Access (for direct code changes)</div>
                <p class="text-sm text-muted mb-2">ContentAgent uses this to push SEO fixes, redirects, schema, llms.txt directly to your server. Leave blank to use the JS snippet approach instead.</p>

                <div class="form-group">
                    <label for="server_type">Access Type</label>
                    <select id="server_type" name="server_type" class="form-control">
                        <option value="api_only" <?= ($site['server_type'] ?? '') === 'api_only' ? 'selected' : '' ?>>API Only (CMS API + JS Snippet)</option>
                        <option value="ftp" <?= ($site['server_type'] ?? '') === 'ftp' ? 'selected' : '' ?>>FTP</option>
                        <option value="sftp" <?= ($site['server_type'] ?? '') === 'sftp' ? 'selected' : '' ?>>SFTP</option>
                        <option value="ssh" <?= ($site['server_type'] ?? '') === 'ssh' ? 'selected' : '' ?>>SSH</option>
                        <option value="cpanel" <?= ($site['server_type'] ?? '') === 'cpanel' ? 'selected' : '' ?>>cPanel</option>
                        <option value="git" <?= ($site['server_type'] ?? '') === 'git' ? 'selected' : '' ?>>Git (push to repo)</option>
                    </select>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="server_host">Host / IP</label>
                        <input type="text" id="server_host" name="server_host" class="form-control" value="<?= e($site['server_host'] ?? '') ?>" placeholder="ftp.yourdomain.com or IP address">
                    </div>
                    <div class="form-group">
                        <label for="server_path">Web Root Path</label>
                        <input type="text" id="server_path" name="server_path" class="form-control" value="<?= e($site['server_path'] ?? '') ?>" placeholder="/public_html or /var/www/html">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="server_user">Username</label>
                        <input type="text" id="server_user" name="server_user" class="form-control" value="<?= e($site['server_user'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="server_pass">Password</label>
                        <input type="password" id="server_pass" name="server_pass" class="form-control" value="<?= e($site['server_pass'] ?? '') ?>">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="git_repo">Git Repo URL (for git deploy)</label>
                        <input type="text" id="git_repo" name="git_repo" class="form-control" value="<?= e($site['git_repo'] ?? '') ?>" placeholder="https://github.com/user/repo.git">
                    </div>
                    <div class="form-group">
                        <label for="hosting_panel">Hosting Panel</label>
                        <select id="hosting_panel" name="hosting_panel" class="form-control">
                            <option value="">None</option>
                            <option value="cpanel" <?= ($site['hosting_panel'] ?? '') === 'cpanel' ? 'selected' : '' ?>>cPanel</option>
                            <option value="plesk" <?= ($site['hosting_panel'] ?? '') === 'plesk' ? 'selected' : '' ?>>Plesk</option>
                            <option value="vercel" <?= ($site['hosting_panel'] ?? '') === 'vercel' ? 'selected' : '' ?>>Vercel</option>
                            <option value="netlify" <?= ($site['hosting_panel'] ?? '') === 'netlify' ? 'selected' : '' ?>>Netlify</option>
                            <option value="aws" <?= ($site['hosting_panel'] ?? '') === 'aws' ? 'selected' : '' ?>>AWS</option>
                            <option value="digitalocean" <?= ($site['hosting_panel'] ?? '') === 'digitalocean' ? 'selected' : '' ?>>DigitalOcean</option>
                            <option value="linode" <?= ($site['hosting_panel'] ?? '') === 'linode' ? 'selected' : '' ?>>Linode</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="digest_email">Weekly digest recipient (optional)</label>
                <input type="email" id="digest_email" name="digest_email" class="form-control" value="<?= e($site['digest_email'] ?? '') ?>" placeholder="reports@yourcompany.com">
                <div class="text-sm text-muted" style="margin-top:4px;">When set, the weekly digest emails go here instead of the owner's login email.</div>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_active" value="1" <?= $site['is_active'] ? 'checked' : '' ?>>
                    Site is active
                </label>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= url('/dashboard/site.php?id=' . $site['id']) ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>

        <div class="card" style="margin-top:20px; border:1px solid #fca5a5; background:#fef2f2;">
            <div class="card-header" style="color:#991b1b; border-bottom-color:#fca5a5;">⚠ Danger zone</div>
            <p style="font-size:13px; color:#7f1d1d; margin-bottom:10px;">
                Deleting this site is <strong>permanent</strong>. Every post, keyword, audit, alert, subscriber, AEO query,
                competitor record, and integration connection for this site will be wiped. There is no undo.
            </p>
            <p style="font-size:12px; color:#7f1d1d; margin-bottom:10px;">
                To confirm, type the site's exact name below: <code style="background:#fff;padding:1px 6px;border-radius:3px;font-weight:600;"><?= e($site['name']) ?></code>
            </p>
            <form method="POST" onsubmit="return _confirmDelete(this, <?= json_encode($site['name']) ?>)" style="display:flex; gap:8px; align-items:center;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $site['id'] ?>">
                <input type="text" name="confirm_name" class="form-control" style="max-width:260px;" placeholder="Type site name here" autocomplete="off">
                <button type="submit" class="btn btn-danger btn-sm">Delete site and all data</button>
            </form>
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
        </div>
    </div>
    <?php endif; ?>

<?php else:
    // List all sites — rich card view
    if (auth_is_super_admin()) {
    $stmt = $db->query('SELECT * FROM sites ORDER BY created_at DESC');
} else {
    $stmt = $db->prepare('SELECT * FROM sites WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$user_id]);
}
    $sites = $stmt->fetchAll();

    $topbar_actions = '<a href="' . url('/dashboard/onboarding.php') . '" class="btn btn-accent">+ Add Site</a>';
?>
    <?php if (empty($sites)): ?>
        <div class="card" style="text-align: center; padding: 40px;">
            <p class="text-muted mb-4">No sites added yet.</p>
            <a href="<?= url('/dashboard/onboarding.php') ?>" class="btn btn-accent">Add Your First Site</a>
        </div>
    <?php else: ?>
        <?php foreach ($sites as $s):
            // Fetch all data for this site
            $stmt = $db->prepare('SELECT status, COUNT(*) as cnt FROM posts WHERE site_id = ? GROUP BY status');
            $stmt->execute([$s['id']]);
            $pc = []; foreach ($stmt->fetchAll() as $r) $pc[$r['status']] = $r['cnt'];

            $stmt = $db->prepare('SELECT * FROM seo_audits WHERE site_id = ? ORDER BY run_at DESC LIMIT 1');
            $stmt->execute([$s['id']]);
            $audit = $stmt->fetch();

            // SEO score history (all audits)
            $stmt = $db->prepare('SELECT score, total_issues, critical, warnings, pages_crawled, DATE_FORMAT(run_at, "%d %b") as label, run_at FROM seo_audits WHERE site_id = ? ORDER BY run_at ASC');
            $stmt->execute([$s['id']]);
            $audit_history = $stmt->fetchAll();

            // Issues fixed count
            $stmt = $db->prepare('SELECT COUNT(*) FROM seo_issues WHERE site_id = ? AND status IN ("fix_proposed","fix_applied","resolved")');
            $stmt->execute([$s['id']]);
            $issues_fixed = $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COUNT(*) FROM seo_issues WHERE audit_id = (SELECT a2.id FROM seo_audits a2 WHERE a2.site_id = ? ORDER BY a2.run_at DESC LIMIT 1) AND status = "open"');
            $stmt->execute([$s['id']]);
            $issues_open = $stmt->fetchColumn();

            // This week vs last week
            $stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE site_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)');
            $stmt->execute([$s['id']]);
            $posts_this_week = $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE site_id = ? AND created_at BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)');
            $stmt->execute([$s['id']]);
            $posts_last_week = $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COUNT(*) FROM keywords WHERE site_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)');
            $stmt->execute([$s['id']]);
            $kw_this_week = $stmt->fetchColumn();

            // First and latest audit for comparison
            $first_audit = !empty($audit_history) ? $audit_history[0] : null;
            $latest_audit = !empty($audit_history) ? $audit_history[count($audit_history) - 1] : null;
            $score_change = ($first_audit && $latest_audit && count($audit_history) > 1) ? $latest_audit['score'] - $first_audit['score'] : null;

            $stmt = $db->prepare('SELECT COUNT(*) FROM keywords WHERE site_id = ?');
            $stmt->execute([$s['id']]);
            $kw_count = $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COUNT(*) FROM seo_issues WHERE audit_id = (SELECT a2.id FROM seo_audits a2 WHERE a2.site_id = ? ORDER BY a2.run_at DESC LIMIT 1) AND status = "open"');
            $stmt->execute([$s['id']]);
            $open_issues = $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT * FROM posts WHERE site_id = ? ORDER BY created_at DESC LIMIT 5');
            $stmt->execute([$s['id']]);
            $recent_posts = $stmt->fetchAll();

            $stmt = $db->prepare('SELECT * FROM agent_log WHERE site_id = ? ORDER BY created_at DESC LIMIT 5');
            $stmt->execute([$s['id']]);
            $recent_activity = $stmt->fetchAll();

            $stmt = $db->prepare('SELECT * FROM keywords WHERE site_id = ? ORDER BY priority DESC LIMIT 8');
            $stmt->execute([$s['id']]);
            $top_kws = $stmt->fetchAll();

            $colors = json_decode($s['brand_colors'] ?? '[]', true) ?: [];
            $fonts = json_decode($s['brand_fonts'] ?? '[]', true) ?: [];
            $topics = json_decode($s['topics'] ?? '[]', true) ?: [];
        ?>

        <!-- Site Card: <?= e($s['name']) ?> -->
        <div style="margin-bottom: 24px;">
            <!-- Header -->
            <div class="card" style="margin-bottom: 2px; border-bottom: 3px solid var(--primary); border-radius: var(--radius) var(--radius) 0 0;">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-4">
                        <?php if ($audit): ?>
                            <?php
                            $sc = 'score-bad';
                            if ($audit['score'] >= 80) $sc = 'score-good';
                            elseif ($audit['score'] >= 50) $sc = 'score-ok';
                            ?>
                            <span class="score-circle <?= $sc ?>" style="width:48px;height:48px;font-size:18px;"><?= $audit['score'] ?></span>
                        <?php else: ?>
                            <span class="score-circle" style="width:48px;height:48px;font-size:12px;background:#f1f5f9;color:#94a3b8;">N/A</span>
                        <?php endif; ?>
                        <div>
                            <div style="font-size: 16px; font-weight: 600; color: var(--primary);"><?= e($s['name']) ?></div>
                            <div class="text-sm text-muted">
                                <?= e($s['domain']) ?>
                                <span class="badge badge-info" style="margin-left: 4px;"><?= e($s['platform'] ?: 'unknown') ?></span>
                                <span class="badge badge-<?= $s['is_active'] ? 'approved' : 'rejected' ?>"><?= $s['is_active'] ? 'Active' : 'Off' ?></span>
                                <span class="badge badge-<?= $s['agent_mode'] === 'auto' ? 'approved' : 'draft' ?>"><?= $s['agent_mode'] ?> mode</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <a href="<?= url('/api/export.php?site_id=' . $s['id'] . '&type=full') ?>" class="btn btn-outline btn-sm" title="Download full Excel report">Export CSV</a>
                        <a href="<?= url('/dashboard/setup.php?site=' . $s['id']) ?>" class="btn btn-outline btn-sm">Setup</a>
                        <a href="<?= url('/dashboard/seo-audit.php?site=' . $s['id']) ?>" class="btn btn-outline btn-sm">Full Audit</a>
                    </div>
                </div>
            </div>

            <!-- Agent Actions -->
            <div class="card" style="margin-bottom: 2px; padding: 12px 16px;">
                <div class="flex gap-2" style="flex-wrap: wrap;">
                    <a href="<?= url('/dashboard/agent-run.php?agent=scanner&site=' . $s['id']) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">Scan Site</a>
                    <a href="<?= url('/dashboard/agent-run.php?agent=seo-auditor&site=' . $s['id']) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">SEO Audit</a>
                    <a href="<?= url('/dashboard/agent-run.php?agent=auto-fixer&site=' . $s['id']) ?>" class="btn btn-sm" style="background:#ef4444;color:#fff;text-decoration:none;">Auto-Fix Issues</a>
                    <a href="<?= url('/dashboard/agent-run.php?agent=keyword-research&site=' . $s['id']) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">Find Keywords</a>
                    <a href="<?= url('/dashboard/write.php?site=' . $s['id'] . '&step=propose') ?>" class="btn btn-accent btn-sm" style="text-decoration:none;">AI Content Planner</a>
                    <a href="<?= url('/dashboard/agent-run.php?agent=news-scraper&site=' . $s['id']) ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">Scrape News</a>
                    <a href="<?= url('/dashboard/agent-run.php?agent=evaluator&site=' . $s['id']) ?>" class="btn btn-outline btn-sm" style="text-decoration:none;">Evaluate Strategy</a>
                </div>
            </div>

            <!-- Stats row -->
            <div class="stats-grid" style="margin-bottom: 2px;">
                <a href="<?= url('/dashboard/posts.php?site=' . $s['id'] . '&status=published') ?>" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                    <div class="stat-label">Published</div>
                    <div class="stat-value"><?= $pc['published'] ?? 0 ?></div>
                </a>
                <a href="<?= url('/dashboard/posts.php?site=' . $s['id'] . '&status=draft') ?>" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                    <div class="stat-label">Drafts</div>
                    <div class="stat-value"><?= $pc['draft'] ?? 0 ?></div>
                </a>
                <a href="<?= url('/dashboard/keywords.php?site=' . $s['id']) ?>" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                    <div class="stat-label">Keywords</div>
                    <div class="stat-value"><?= $kw_count ?></div>
                </a>
                <a href="<?= url('/dashboard/seo-audit.php?site=' . $s['id']) ?>" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                    <div class="stat-label">SEO Issues</div>
                    <div class="stat-value" style="color: <?= $open_issues > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= $open_issues ?></div>
                </a>
                <a href="<?= url('/dashboard/posts.php?site=' . $s['id']) ?>" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                    <div class="stat-label">Total Posts</div>
                    <div class="stat-value"><?= array_sum($pc) ?></div>
                </a>
            </div>

            <!-- Progress Tracker -->
            <div class="card" style="margin-bottom: 2px;">
                <div class="card-header flex justify-between items-center">
                    <span>Progress Tracker</span>
                    <span class="text-sm text-muted">
                        <?php if ($score_change !== null): ?>
                            Since first audit:
                            <span style="color: <?= $score_change >= 0 ? 'var(--success)' : 'var(--danger)' ?>; font-weight: 600;">
                                <?= $score_change >= 0 ? '+' : '' ?><?= $score_change ?> points
                            </span>
                        <?php else: ?>
                            Run audits weekly to track progress
                        <?php endif; ?>
                    </span>
                </div>

                <?php if (!empty($audit_history)): ?>
                <!-- SEO Score Timeline -->
                <div style="margin-bottom: 14px;">
                    <div class="text-sm text-muted mb-2" style="font-weight:600;">SEO Score Over Time</div>
                    <div style="display: flex; align-items: flex-end; gap: 4px; height: 80px; padding: 0 4px;">
                        <?php foreach ($audit_history as $ah):
                            $bar_height = max(8, ($ah['score'] / 100) * 70);
                            $bar_color = '#ef4444';
                            if ($ah['score'] >= 80) $bar_color = '#10b981';
                            elseif ($ah['score'] >= 50) $bar_color = '#f59e0b';
                        ?>
                        <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px;">
                            <span style="font-size: 11px; font-weight: 700; color: <?= $bar_color ?>;"><?= $ah['score'] ?></span>
                            <div style="width: 100%; max-width: 50px; height: <?= $bar_height ?>px; background: <?= $bar_color ?>; border-radius: 3px 3px 0 0; transition: height 0.3s;"></div>
                            <span style="font-size: 9px; color: #94a3b8;"><?= $ah['label'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Issues Progress Bar -->
                <div style="margin-bottom: 14px;">
                    <div class="text-sm text-muted mb-2" style="font-weight:600;">Issues: <?= $issues_fixed ?> fixed, <?= $issues_open ?> open</div>
                    <?php
                    $total_tracked = $issues_fixed + $issues_open;
                    $fix_pct = $total_tracked > 0 ? round(($issues_fixed / $total_tracked) * 100) : 0;
                    ?>
                    <div style="height: 10px; background: #fecaca; border-radius: 5px; overflow: hidden;">
                        <div style="height: 100%; width: <?= $fix_pct ?>%; background: #10b981; border-radius: 5px; transition: width 0.3s;"></div>
                    </div>
                    <div class="flex justify-between mt-2" style="font-size: 11px; color: #94a3b8;">
                        <span><?= $fix_pct ?>% resolved</span>
                        <span><?= $total_tracked ?> total tracked</span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- This Week Summary -->
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; padding-top: 10px; border-top: 1px solid var(--border);">
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: var(--primary);"><?= $posts_this_week ?></div>
                        <div style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">Posts this week</div>
                        <?php if ($posts_last_week > 0): ?>
                            <div style="font-size: 10px; color: <?= $posts_this_week >= $posts_last_week ? 'var(--success)' : 'var(--danger)' ?>;">
                                vs <?= $posts_last_week ?> last week
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: var(--primary);"><?= $kw_this_week ?></div>
                        <div style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">New keywords</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: var(--success);"><?= $issues_fixed ?></div>
                        <div style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">Issues fixed</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: <?= $audit ? ($audit['score'] >= 50 ? 'var(--success)' : 'var(--danger)') : '#94a3b8' ?>;"><?= $audit ? $audit['score'] . '%' : '—' ?></div>
                        <div style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">SEO Score</div>
                    </div>
                </div>
            </div>

            <div class="grid-2" style="margin-bottom: 2px;">
                <!-- Recent Posts -->
                <div class="card" style="margin-bottom: 0;">
                    <div class="card-header flex justify-between items-center">
                        <span>Recent Posts</span>
                        <a href="<?= url('/dashboard/posts.php?site=' . $s['id']) ?>" class="text-sm" style="color:var(--accent);text-decoration:none;">View all</a>
                    </div>
                    <?php if (empty($recent_posts)): ?>
                        <p class="text-muted text-sm">No posts yet.</p>
                    <?php else: ?>
                        <?php foreach ($recent_posts as $rp): ?>
                        <div style="padding: 6px 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <a href="<?= url('/dashboard/posts.php?action=edit&id=' . $rp['id']) ?>" style="color:var(--text);text-decoration:none;font-size:13px;"><?= e(truncate($rp['title'], 45)) ?></a>
                            </div>
                            <div class="flex gap-2 items-center">
                                <span class="badge badge-<?= $rp['type'] === 'news' ? 'info' : 'draft' ?>" style="font-size:10px;"><?= $rp['type'] ?></span>
                                <span class="badge badge-<?= $rp['status'] ?>" style="font-size:10px;"><?= $rp['status'] ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Top Keywords -->
                <div class="card" style="margin-bottom: 0;">
                    <div class="card-header flex justify-between items-center">
                        <span>Top Keywords</span>
                        <a href="<?= url('/dashboard/keywords.php?site=' . $s['id']) ?>" class="text-sm" style="color:var(--accent);text-decoration:none;">View all</a>
                    </div>
                    <?php if (empty($top_kws)): ?>
                        <p class="text-muted text-sm">No keywords yet.</p>
                    <?php else: ?>
                        <?php foreach ($top_kws as $kw): ?>
                        <div style="padding: 4px 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; font-size: 13px;">
                            <span><?= e(truncate($kw['keyword'], 40)) ?></span>
                            <div class="flex gap-2">
                                <span style="color:<?= $kw['priority'] >= 70 ? 'var(--success)' : ($kw['priority'] >= 40 ? 'var(--warning)' : '#aaa') ?>;font-weight:600;font-size:12px;">P:<?= $kw['priority'] ?></span>
                                <?php if ($kw['difficulty']): ?>
                                    <span style="color:<?= $kw['difficulty'] >= 70 ? 'var(--danger)' : ($kw['difficulty'] >= 40 ? 'var(--warning)' : 'var(--success)') ?>;font-size:12px;">D:<?= $kw['difficulty'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid-2" style="margin-bottom: 2px;">
                <!-- Brand Profile -->
                <div class="card" style="margin-bottom: 0;">
                    <div class="card-header">Brand Profile</div>
                    <div style="font-size: 13px;">
                        <div style="margin-bottom: 6px;">
                            <span class="text-muted">Tone:</span> <?= e($s['brand_tone'] ?? 'Not scanned') ?>
                        </div>
                        <div style="margin-bottom: 6px;">
                            <span class="text-muted">Colors:</span>
                            <?php foreach ($colors as $c): ?>
                                <span style="display:inline-block;width:18px;height:18px;background:<?= e($c) ?>;border-radius:3px;margin-right:3px;vertical-align:middle;border:1px solid #ddd;"></span>
                            <?php endforeach; ?>
                            <?php if (empty($colors)): ?>—<?php endif; ?>
                        </div>
                        <div style="margin-bottom: 6px;">
                            <span class="text-muted">Fonts:</span> <?= e(implode(', ', $fonts) ?: '—') ?>
                        </div>
                        <div>
                            <span class="text-muted">Topics:</span> <?= e(implode(', ', $topics) ?: '—') ?>
                        </div>
                    </div>
                </div>

                <!-- Agent Activity -->
                <div class="card" style="margin-bottom: 0;">
                    <div class="card-header">Recent Agent Activity</div>
                    <?php if (empty($recent_activity)): ?>
                        <p class="text-muted text-sm">No activity yet. Run an agent below.</p>
                    <?php else: ?>
                        <?php foreach ($recent_activity as $al): ?>
                        <div style="padding: 4px 0; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; font-size: 12px;">
                            <span>
                                <span class="badge badge-<?= $al['status'] === 'success' ? 'approved' : 'rejected' ?>" style="font-size:10px;"><?= $al['status'] ?></span>
                                <?= e($al['action']) ?>
                            </span>
                            <span class="text-muted"><?= format_date($al['created_at'], 'H:i, d M') ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Snippet Setup -->
            <div class="card" style="margin-bottom: 0;">
                <div class="card-header">📦 SEO Snippet — Embed on your site</div>
                <p class="text-sm text-muted mb-2">Add this one line to your website's <code>&lt;head&gt;</code>. ContentAgent will automatically fix canonical tags, meta titles, OG tags, schema, and handle redirects.</p>
                <div style="background:#1a1a1a;border-radius:6px;padding:12px 16px;font-family:monospace;font-size:12px;color:#10b981;position:relative;cursor:pointer;" onclick="navigator.clipboard.writeText(this.innerText.trim());this.querySelector('.copy-msg').style.display='block';setTimeout(()=>this.querySelector('.copy-msg').style.display='none',2000)">
                    <span class="copy-msg" style="display:none;position:absolute;top:4px;right:8px;font-size:10px;color:#fff;background:#10b981;padding:2px 8px;border-radius:3px;">Copied!</span>
                    &lt;script src="<?= e(config('app_url')) ?>/snippet/contentagent.js" data-site="<?= e($s['domain']) ?>"&gt;&lt;/script&gt;
                </div>
                <div class="text-sm text-muted mt-2">
                    <?php
                    $stmt_seo = $db->prepare('SELECT COUNT(*) FROM page_seo WHERE site_id = ?');
                    $stmt_seo->execute([$s['id']]);
                    $seo_rules = $stmt_seo->fetchColumn();
                    $stmt_redir = $db->prepare('SELECT COUNT(*) FROM redirects WHERE site_id = ?');
                    $stmt_redir->execute([$s['id']]);
                    $redirect_rules = $stmt_redir->fetchColumn();
                    ?>
                    Active: <strong><?= $seo_rules ?></strong> SEO rules, <strong><?= $redirect_rules ?></strong> redirects.
                    <?php if ($seo_rules == 0): ?>
                        Click "🤖 Auto-Fix All Issues" above to generate rules.
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php endforeach; ?>

        <!-- Agent buttons are now links to agent-run.php -->
    <?php endif; ?>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
