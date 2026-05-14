<?php
/**
 * Dashboard — AI SEO & Discoverability.
 * Shows llms.txt status, AI crawler access, schema coverage, and AI readiness score.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai-seo.php';
require_once __DIR__ . '/../../includes/schema-generator.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$filter_site = $_GET['site'] ?? '';
$action = $_GET['action'] ?? '';

$page_title = 'SEO Health — AI Readiness';

ob_start();

if ($filter_site) {
    $active = 'ai';
    include __DIR__ . '/_health_tabs.php';
}

// Get sites
$stmt = $db->prepare('SELECT id, name, domain FROM sites WHERE user_id = ? ORDER BY name');
$stmt->execute([$user_id]);
$sites = $stmt->fetchAll();

// Handle generate actions
// Back button for all action pages
if ($action && $filter_site) {
    echo '<div class="mb-4"><a href="' . url('/dashboard/ai-seo.php?site=' . $filter_site) . '" class="btn btn-outline btn-sm">&laquo; Back to AI SEO Audit</a></div>';
}

// All generate pages show content + auto-deploy button
if ($action && strpos($action, 'generate-') === 0 && $filter_site) {
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([(int)$filter_site, $user_id]);
    $gen_site = $stmt->fetch();

    if ($gen_site) {
        $deploy_type = str_replace('generate-', '', $action);
        $has_cms = !empty($gen_site['cms_url']) && !empty($gen_site['cms_api_key']);

        // Deploy button at top
        echo '<div class="card" style="padding:12px 16px;margin-bottom:14px;background:#f0fdf4;border-color:#86efac;">';
        if ($has_cms) {
            echo '<div class="flex justify-between items-center">';
            echo '<div><strong>Ready to deploy to ' . e($gen_site['domain']) . '</strong><br><span class="text-sm text-muted">This will automatically upload the files to your live website.</span></div>';
            echo '<button onclick="deployNow(\'' . $deploy_type . '\', ' . $gen_site['id'] . ', this)" class="btn btn-accent" style="padding:10px 24px;font-size:14px;">Deploy to Website Now</button>';
            echo '</div>';
        } else {
            echo '<div class="flex justify-between items-center">';
            echo '<div><strong>CMS not configured</strong><br><span class="text-sm text-muted">Add CMS URL and API Key in <a href="' . url('/dashboard/sites.php?action=edit&id=' . $gen_site['id']) . '">Site Settings</a> to enable auto-deploy.</span></div>';
            echo '<span class="badge badge-warning">Manual copy needed</span>';
            echo '</div>';
        }
        echo '</div>';

        echo '<script>
        async function deployNow(type, siteId, btn) {
            btn.disabled = true;
            btn.textContent = "Deploying...";
            try {
                const res = await fetch("' . url('/api/deploy-seo.php') . '", {
                    method: "POST",
                    headers: {"Content-Type": "application/json"},
                    body: JSON.stringify({site_id: siteId, type: type})
                });
                const data = await res.json();
                if (data.success) {
                    btn.textContent = "Deployed!";
                    btn.style.background = "#10b981";
                    alert("Deployed to website: " + data.deployed.join(", "));
                } else {
                    alert("Error: " + (data.message || data.error || "Unknown"));
                    btn.textContent = "Deploy to Website Now";
                    btn.disabled = false;
                }
            } catch(e) {
                alert("Request failed");
                btn.textContent = "Deploy to Website Now";
                btn.disabled = false;
            }
        }
        function copyText(id){const t=document.getElementById(id);t.select();document.execCommand("copy");alert("Copied!");}
        </script>';
    }
}

if ($action === 'generate-llms' && $filter_site && isset($gen_site)) {
    $llms_txt = generate_llms_txt($gen_site, $db);
    $llms_full = generate_llms_full_txt($gen_site, $db);

    echo '<div class="card"><div class="card-header flex justify-between items-center"><span>llms.txt</span><button onclick="copyText(\'llms-txt\')" class="btn btn-outline btn-sm">Copy</button></div>';
    echo '<textarea id="llms-txt" class="form-control" rows="15" style="font-family:monospace;font-size:12px;" onclick="this.select()">' . e($llms_txt) . '</textarea></div>';

    echo '<div class="card"><div class="card-header flex justify-between items-center"><span>llms-full.txt</span><button onclick="copyText(\'llms-full\')" class="btn btn-outline btn-sm">Copy</button></div>';
    echo '<textarea id="llms-full" class="form-control" rows="15" style="font-family:monospace;font-size:12px;" onclick="this.select()">' . e($llms_full) . '</textarea></div>';
}

if ($action === 'generate-robots' && $filter_site && isset($gen_site)) {
    $robots_allow = generate_ai_robots_txt($gen_site['domain'], true);

    echo '<div class="card"><div class="card-header flex justify-between items-center"><span>robots.txt (AI crawlers allowed)</span><button onclick="copyText(\'robots-txt\')" class="btn btn-outline btn-sm">Copy</button></div>';
    echo '<textarea id="robots-txt" class="form-control" rows="20" style="font-family:monospace;font-size:12px;" onclick="this.select()">' . e($robots_allow) . '</textarea></div>';
}

if ($action === 'generate-schema' && $filter_site && isset($gen_site)) {
    $schemas = [
        'Organization'  => schema_organization($gen_site),
        'WebSite'       => schema_website($gen_site),
    ];

    echo '<div class="card"><div class="card-header">Schema Markup</div>';
    echo '<p class="text-sm text-muted mb-2">These will be deployed as JSON files to your CMS. Add them in your site\'s <code>&lt;head&gt;</code>.</p>';

    $i = 0;
    foreach ($schemas as $label => $json) {
        $i++;
        echo '<div style="margin-bottom:14px;">';
        echo '<div class="flex justify-between items-center"><span class="text-sm" style="font-weight:600;">' . e($label) . '</span><button onclick="copyText(\'schema-' . $i . '\')" class="btn btn-outline btn-sm">Copy</button></div>';
        echo '<textarea id="schema-' . $i . '" class="form-control" rows="10" style="font-family:monospace;font-size:11px;margin-top:4px;" onclick="this.select()">' . e($json) . '</textarea>';
        echo '</div>';
    }
    echo '</div>';
}

// Show audit results if a site is selected
if ($filter_site && !$action) {
    $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
    $stmt->execute([(int)$filter_site, $user_id]);
    $site = $stmt->fetch();

    if ($site) {
        echo '<div class="alert alert-info">Running AI discoverability audit for <strong>' . e($site['name']) . '</strong>... This checks llms.txt, AI crawler access, schema, and more.</div>';

        $audit = audit_ai_discoverability($site['domain']);

        // Score
        $sc_class = 'score-bad';
        if ($audit['score'] >= 80) $sc_class = 'score-good';
        elseif ($audit['score'] >= 50) $sc_class = 'score-ok';
?>
        <div class="flex items-center gap-4 mb-4">
            <span class="score-circle <?= $sc_class ?>" style="width:56px;height:56px;font-size:20px;"><?= $audit['score'] ?></span>
            <div>
                <div style="font-weight:600;font-size:16px;">AI Readiness: <?= $audit['score'] ?>%</div>
                <div class="text-sm text-muted"><?= $audit['passed'] ?>/<?= $audit['total'] ?> checks passed</div>
            </div>
        </div>

        <!-- Action buttons -->
        <div class="card" style="padding:12px 16px;margin-bottom:14px;">
            <div class="flex justify-between items-center" style="flex-wrap:wrap;gap:8px;">
                <div class="flex gap-2" style="flex-wrap:wrap;">
                    <?php if ($audit['score'] < 100): ?>
                        <button onclick="deployNow('all', <?= $site['id'] ?>, this)" class="btn btn-accent" style="padding:8px 20px;">Fix All & Deploy to Website</button>
                    <?php else: ?>
                        <span style="color:var(--success);font-weight:600;font-size:14px;">All checks passed — nothing to fix!</span>
                    <?php endif; ?>
                    <a href="<?= url('/dashboard/ai-seo.php?site=' . $site['id'] . '&action=generate-llms') ?>" class="btn btn-outline btn-sm">Preview llms.txt</a>
                    <a href="<?= url('/dashboard/ai-seo.php?site=' . $site['id'] . '&action=generate-robots') ?>" class="btn btn-outline btn-sm">Preview robots.txt</a>
                    <a href="<?= url('/dashboard/ai-seo.php?site=' . $site['id'] . '&action=generate-schema') ?>" class="btn btn-outline btn-sm">Preview Schema</a>
                </div>
            </div>
            <div id="deploy-status" class="mt-2 text-sm"></div>
        </div>

        <script>
        async function deployNow(type, siteId, btn) {
            const orig = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Deploying...';
            document.getElementById('deploy-status').textContent = 'Generating and deploying files to ' + '<?= e($site['domain']) ?>' + '...';
            try {
                const res = await fetch('<?= url('/api/deploy-seo.php') ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({site_id: siteId, type: type})
                });
                const data = await res.json();
                if (data.success) {
                    btn.textContent = 'Deployed!';
                    btn.style.background = '#10b981';
                    document.getElementById('deploy-status').innerHTML = '<span style="color:var(--success);">Deployed: ' + data.deployed.join(', ') + '</span>';
                    setTimeout(() => location.reload(), 2000);
                } else {
                    document.getElementById('deploy-status').innerHTML = '<span style="color:var(--danger);">Error: ' + (data.message || data.error) + '</span>';
                    btn.textContent = orig;
                    btn.disabled = false;
                }
            } catch(e) {
                document.getElementById('deploy-status').textContent = 'Request failed';
                btn.textContent = orig;
                btn.disabled = false;
            }
        }
        </script>

        <!-- Results -->
        <div class="card">
            <div class="card-header">Audit Results</div>
            <table>
                <thead>
                    <tr>
                        <th>Check</th>
                        <th>Status</th>
                        <th>Detail</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit['results'] as $r): ?>
                    <tr>
                        <td style="font-weight:500;"><?= e($r['check']) ?></td>
                        <td>
                            <?php if ($r['status'] === 'pass'): ?>
                                <span class="badge badge-approved">Pass</span>
                            <?php elseif ($r['status'] === 'warning'): ?>
                                <span class="badge badge-warning">Warning</span>
                            <?php else: ?>
                                <span class="badge badge-rejected">Missing</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm"><?= e($r['detail']) ?></td>
                        <td class="text-sm">
                            <?php if ($r['status'] === 'pass'): ?>
                                <span style="color:var(--success);">OK</span>
                            <?php elseif ($r['check'] === 'llms.txt'): ?>
                                <a href="<?= url('/dashboard/ai-seo.php?site=' . $site['id'] . '&action=generate-llms') ?>" class="btn btn-accent btn-sm">Fix — Generate llms.txt</a>
                            <?php elseif ($r['check'] === 'Structured Data (Schema.org)'): ?>
                                <a href="<?= url('/dashboard/ai-seo.php?site=' . $site['id'] . '&action=generate-schema') ?>" class="btn btn-primary btn-sm">Fix — Generate Schema</a>
                            <?php elseif ($r['check'] === 'AI Crawler Access'): ?>
                                <a href="<?= url('/dashboard/ai-seo.php?site=' . $site['id'] . '&action=generate-robots') ?>" class="btn btn-primary btn-sm">Fix — Generate robots.txt</a>
                            <?php elseif ($r['fix']): ?>
                                <span class="text-muted"><?= e(truncate($r['fix'], 80)) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- AI Crawler Access Detail -->
        <?php
        $crawler_check = array_filter($audit['results'], fn($r) => $r['check'] === 'AI Crawler Access');
        $crawler_check = reset($crawler_check);
        if ($crawler_check && !empty($crawler_check['bots'])):
        ?>
        <div class="card">
            <div class="card-header">AI Crawler Access</div>
            <table>
                <thead>
                    <tr><th>Bot</th><th>Owner</th><th>Purpose</th><th>Access</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($crawler_check['bots'] as $bot => $info): ?>
                    <tr>
                        <td style="font-weight:500;font-family:monospace;font-size:12px;"><?= e($bot) ?></td>
                        <td class="text-sm"><?= e($info['owner']) ?></td>
                        <td class="text-sm text-muted"><?= e($info['purpose']) ?></td>
                        <td>
                            <?php if ($info['blocked']): ?>
                                <span class="badge badge-rejected">Blocked</span>
                            <?php else: ?>
                                <span class="badge badge-approved">Allowed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

<?php
    }
}
?>

<!-- Site selector -->
<div class="card" style="padding:10px 16px;">
    <form method="GET" class="flex gap-4 items-center">
        <select name="site" class="form-control" style="width:auto;min-width:200px;">
            <option value="">Select a site to audit</option>
            <?php foreach ($sites as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filter_site == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> — <?= e($s['domain']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Run AI Audit</button>
    </form>
</div>

<?php if (!$filter_site && !$action): ?>
<div class="card" style="padding:30px;text-align:center;">
    <div style="font-size:32px;margin-bottom:8px;">🤖</div>
    <p style="font-weight:600;font-size:15px;margin-bottom:4px;">AI-Era SEO</p>
    <p class="text-muted text-sm" style="max-width:500px;margin:0 auto;">
        Check if your site is discoverable by AI models like ChatGPT, Claude, Gemini, and Perplexity.
        Generate llms.txt, manage AI crawler access, and add schema markup — all from one place.
    </p>
</div>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';
