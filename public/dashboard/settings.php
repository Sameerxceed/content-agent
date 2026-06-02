<?php
/**
 * Dashboard — Settings (Profile, API Keys, Integrations).
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
auth_require();

$db = require __DIR__ . '/../../includes/db.php';
$user_id = auth_user_id();

$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Load current config for display
$config_file = __DIR__ . '/../../config/config.php';
$current_config = require $config_file;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash_error'] = 'Invalid form submission.';
        redirect('/dashboard/settings.php');
    }

    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($name) || empty($email)) {
            $_SESSION['flash_error'] = 'Name and email are required.';
            redirect('/dashboard/settings.php');
        }

        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $_SESSION['flash_error'] = 'Email already in use.';
            redirect('/dashboard/settings.php');
        }

        $db->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $user_id]);
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        $_SESSION['flash_success'] = 'Profile updated.';
    }

    if ($post_action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password_hash'])) {
            $_SESSION['flash_error'] = 'Current password is incorrect.';
            redirect('/dashboard/settings.php');
        }
        if ($new !== $confirm) {
            $_SESSION['flash_error'] = 'New passwords do not match.';
            redirect('/dashboard/settings.php');
        }
        if (strlen($new) < 8) {
            $_SESSION['flash_error'] = 'Password must be at least 8 characters.';
            redirect('/dashboard/settings.php');
        }

        $hash = auth_hash_password($new);
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user_id]);
        $_SESSION['flash_success'] = 'Password changed.';
    }

    if ($post_action === 'api_keys') {
        // Read current config, update keys, write back
        $config = require $config_file;

        $keys_to_update = [
            'haiku_api_key', 'haiku_model',
            'google_cse_api_key', 'google_cse_cx',
            'reddit_client_id', 'reddit_client_secret',
            'google_client_id', 'google_client_secret',
            'linkedin_client_id', 'linkedin_client_secret',
            'twitter_client_id', 'twitter_client_secret',
            'facebook_app_id', 'facebook_app_secret',
            'stripe_secret_key', 'stripe_webhook_secret',
        ];

        foreach ($keys_to_update as $key) {
            $val = trim($_POST[$key] ?? '');
            if ($val !== '') {
                $config[$key] = $val;
            }
        }

        // Write config file
        $output = "<?php\n/**\n * ContentAgent Configuration\n */\n\nreturn " . var_export_short($config) . ";\n";
        file_put_contents($config_file, $output);

        $_SESSION['flash_success'] = 'API keys updated. Changes take effect immediately.';
    }

    redirect('/dashboard/settings.php');
}

$page_title = 'Settings';
$tab = $_GET['tab'] ?? 'profile';
$is_super = auth_is_super_admin();

// Customers can't open the API tab even by URL — bounce them to Profile.
if ($tab === 'api' && !$is_super) {
    $tab = 'profile';
}

// Same for the api_keys POST action.
if (($_POST['action'] ?? '') === 'api_keys' && !$is_super) {
    $_SESSION['flash_error'] = 'API keys are managed by the super-admin.';
    redirect('/dashboard/settings.php');
}

ob_start();
?>

<div style="margin-bottom:10px;">
    <a href="<?= url('/dashboard/index.php') ?>" style="font-size:13px;color:var(--primary);text-decoration:none;">&larr; Back to Dashboard</a>
</div>

<!-- Tabs -->
<div class="flex gap-2 mb-4" style="border-bottom:2px solid var(--border);padding-bottom:8px;">
    <a href="<?= url('/dashboard/settings.php?tab=profile') ?>" class="btn btn-sm <?= $tab === 'profile' ? 'btn-primary' : 'btn-outline' ?>" style="text-decoration:none;">Profile</a>
    <?php if ($is_super): ?>
    <a href="<?= url('/dashboard/settings.php?tab=api') ?>" class="btn btn-sm <?= $tab === 'api' ? 'btn-primary' : 'btn-outline' ?>" style="text-decoration:none;">API Keys & Integrations</a>
    <?php endif; ?>
</div>

<?php if ($tab === 'profile'): ?>
<div style="max-width: 500px;">
    <div class="card">
        <div class="card-header">Profile</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profile">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 12px;">
                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" class="form-control" value="<?= e($user['name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= e($user['email']) ?>" required>
                </div>
            </div>
            <div class="form-group" style="max-width:240px;">
                <label>Plan</label>
                <input type="text" class="form-control" value="<?= ucfirst($user['plan']) ?>" disabled>
            </div>
            <button type="submit" class="btn btn-primary">Save Profile</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header">Change Password</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" class="form-control" required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 12px;">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="8">
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Change Password</button>
        </form>
    </div>
</div>

<?php elseif ($tab === 'api'): ?>

<div style="background:linear-gradient(135deg, #1B3A6B 0%, #2c5282 100%); color:#fff; border-radius:8px; padding:14px 18px; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:14px;">
    <div>
        <div style="font-weight:600; font-size:14px; margin-bottom:2px;">Prefer a guided setup?</div>
        <div style="font-size:12px; opacity:0.85;">The Integrations Hub walks you through each service step-by-step with smart error fixes.</div>
    </div>
    <a href="<?= url('/dashboard/integrations.php') ?>" style="background:#fff; color:var(--primary); padding:8px 16px; border-radius:6px; font-weight:600; font-size:13px; text-decoration:none; white-space:nowrap;">Open Integrations Hub &rarr;</a>
</div>

<form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="api_keys">

    <div style="max-width:700px;">

    <?php
    $missing_required = [];
    if (empty($current_config['haiku_api_key'] ?? '')) $missing_required[] = 'AI Engine (Claude)';
    if (empty($current_config['google_cse_api_key'] ?? '') || empty($current_config['google_cse_cx'] ?? '')) $missing_required[] = 'Google Search';
    ?>
    <?php if (!empty($missing_required)): ?>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:14px;">
        <div style="font-weight:600;font-size:13px;color:#991b1b;margin-bottom:4px;">Setup Required</div>
        <div style="font-size:12px;color:#b91c1c;">The following are needed for the platform to work properly:</div>
        <ul style="font-size:12px;color:#b91c1c;margin:6px 0 0;padding-left:18px;">
            <?php foreach ($missing_required as $m): ?>
                <li><?= $m ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

        <!-- AI -->
        <div class="card" <?= empty($current_config['haiku_api_key'] ?? '') ? 'style="border:2px solid #ef4444;"' : '' ?>>
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span>🤖 AI Engine (Claude Haiku)</span>
                <?= empty($current_config['haiku_api_key'] ?? '') ? '<span style="background:#ef4444;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">Required</span>' : '<span style="background:#10b981;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">Connected</span>' ?>
            </div>
            <p class="text-sm text-muted mb-2">Powers blog writing, meta generation, alt text, content planning. Get your key at <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a></p>
            <div class="grid-2">
                <div class="form-group">
                    <label>API Key</label>
                    <input type="password" name="haiku_api_key" class="form-control" value="<?= e($current_config['haiku_api_key'] ?? '') ?>" placeholder="sk-ant-...">
                    <div class="text-sm text-muted mt-2"><?= !empty($current_config['haiku_api_key']) ? '✓ Connected' : '✗ Not set' ?></div>
                </div>
                <div class="form-group">
                    <label>Model</label>
                    <select name="haiku_model" class="form-control">
                        <option value="claude-haiku-4-5-20251001" <?= ($current_config['haiku_model'] ?? '') === 'claude-haiku-4-5-20251001' ? 'selected' : '' ?>>Claude Haiku 4.5 (cheapest)</option>
                        <option value="claude-sonnet-4-6" <?= ($current_config['haiku_model'] ?? '') === 'claude-sonnet-4-6' ? 'selected' : '' ?>>Claude Sonnet 4.6 (better quality)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Google CSE (for AI Presence) -->
        <div class="card" <?= (empty($current_config['google_cse_api_key'] ?? '') || empty($current_config['google_cse_cx'] ?? '')) ? 'style="border:2px solid #ef4444;"' : '' ?>>
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span>🔍 Google Search (for AI Presence Builder)</span>
                <?= (!empty($current_config['google_cse_api_key'] ?? '') && !empty($current_config['google_cse_cx'] ?? '')) ? '<span style="background:#10b981;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">Connected</span>' : '<span style="background:#ef4444;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">Required</span>' ?>
            </div>
            <p class="text-sm text-muted mb-2">Finds Reddit, Quora, LinkedIn conversations about your industry. Free: 100 searches/day.</p>
            <details style="margin-bottom:10px;">
                <summary style="cursor:pointer;font-size:12px;color:var(--accent);font-weight:600;">Setup Guide (2 minutes)</summary>
                <ol style="font-size:12px;color:#64748b;padding-left:18px;margin-top:6px;line-height:1.8;">
                    <li>Go to <a href="https://programmablesearchengine.google.com/controlpanel/create" target="_blank" style="color:var(--accent);">Google Programmable Search Engine</a></li>
                    <li>Name: <strong>ContentAgent</strong>, select "Search the entire web", click Create</li>
                    <li>Copy the <strong>Search Engine ID</strong> (starts with something like <code>a1b2c3...</code>)</li>
                    <li>Go to <a href="https://developers.google.com/custom-search/v1/introduction" target="_blank" style="color:var(--accent);">Custom Search API</a> and click <strong>"Get a Key"</strong></li>
                    <li>Select your project, copy the <strong>API Key</strong></li>
                    <li>Paste both below and save</li>
                </ol>
            </details>
            <div class="grid-2">
                <div class="form-group">
                    <label>API Key</label>
                    <input type="password" name="google_cse_api_key" class="form-control" value="<?= e($current_config['google_cse_api_key'] ?? '') ?>" placeholder="AIzaSy...">
                </div>
                <div class="form-group">
                    <label>Search Engine ID (cx)</label>
                    <input type="text" name="google_cse_cx" class="form-control" value="<?= e($current_config['google_cse_cx'] ?? '') ?>" placeholder="a1b2c3d4e5f6...">
                </div>
            </div>
            <div class="text-sm mt-2"><?= !empty($current_config['google_cse_api_key']) && !empty($current_config['google_cse_cx']) ? '<span style="color:var(--success)">✓ Configured — AI Presence Builder can find conversations</span>' : '<span style="color:#94a3b8">✗ Not set — AI Presence Builder will have limited results</span>' ?></div>
        </div>

        <!-- Reddit OAuth -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span>🔴 Reddit (for AI Presence Builder)</span>
                <?= !empty($current_config['reddit_client_id'] ?? '') ? '<span style="background:#10b981;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">Connected</span>' : '<span style="background:#f59e0b;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">Recommended</span>' ?>
            </div>
            <p class="text-sm text-muted mb-2">Searches Reddit discussions and lets you auto-reply. Free and unlimited.</p>
            <details style="margin-bottom:10px;">
                <summary style="cursor:pointer;font-size:12px;color:var(--accent);font-weight:600;">Setup Guide (1 minute)</summary>
                <ol style="font-size:12px;color:#64748b;padding-left:18px;margin-top:6px;line-height:1.8;">
                    <li>Go to <a href="https://www.reddit.com/prefs/apps" target="_blank" style="color:var(--accent);">Reddit App Preferences</a> (login first)</li>
                    <li>Scroll down, click <strong>"create another app..."</strong></li>
                    <li>Name: <strong>ContentAgent</strong>, Type: <strong>script</strong></li>
                    <li>Redirect URI: <code>http://localhost</code></li>
                    <li>Click Create — copy the <strong>ID</strong> (under app name) and <strong>secret</strong></li>
                    <li>Paste both below and save</li>
                </ol>
            </details>
            <div class="grid-2">
                <div class="form-group">
                    <label>Client ID</label>
                    <input type="text" name="reddit_client_id" class="form-control" value="<?= e($current_config['reddit_client_id'] ?? '') ?>" placeholder="abc123def456">
                </div>
                <div class="form-group">
                    <label>Client Secret</label>
                    <input type="password" name="reddit_client_secret" class="form-control" value="<?= e($current_config['reddit_client_secret'] ?? '') ?>" placeholder="xyz789...">
                </div>
            </div>
            <div class="text-sm mt-2"><?= !empty($current_config['reddit_client_id']) ? '<span style="color:var(--success)">✓ Configured — Reddit search enabled</span>' : '<span style="color:#94a3b8">✗ Not set — Reddit results won\'t show</span>' ?></div>
        </div>

        <!-- Google Search Console -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;"><span>📊 Google Search Console (for real keyword data)</span><?= !empty($current_config['google_client_id'] ?? '') ? '<span style="background:#10b981;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">Connected</span>' : '<span style="background:#f59e0b;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">Recommended</span>' ?></div>
            <p class="text-sm text-muted mb-2">Shows real keyword rankings, clicks, impressions. Setup: <a href="https://console.cloud.google.com" target="_blank">console.cloud.google.com</a> → Create project → Enable "Search Console API" → OAuth2 credentials.</p>
            <div class="grid-2">
                <div class="form-group">
                    <label>Client ID</label>
                    <input type="text" name="google_client_id" class="form-control" value="<?= e($current_config['google_client_id'] ?? '') ?>" placeholder="xxxxx.apps.googleusercontent.com">
                </div>
                <div class="form-group">
                    <label>Client Secret</label>
                    <input type="password" name="google_client_secret" class="form-control" value="<?= e($current_config['google_client_secret'] ?? '') ?>" placeholder="GOCSPX-...">
                </div>
            </div>
            <div class="text-sm text-muted">Redirect URI: <code><?= e(config('app_url')) ?>/api/oauth/google-callback.php</code></div>
            <div class="text-sm mt-2"><?= !empty($current_config['google_client_id']) ? '<span style="color:var(--success)">✓ Configured</span>' : '<span style="color:#94a3b8">✗ Not set</span>' ?></div>
        </div>

        <!-- LinkedIn -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;"><span>💼 LinkedIn</span><span style="background:#94a3b8;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">Optional</span></div>
            <p class="text-sm text-muted mb-2">Auto-post articles to LinkedIn. Setup: <a href="https://www.linkedin.com/developers/" target="_blank">linkedin.com/developers</a> → Create app → Request "Share on LinkedIn" product.</p>
            <div class="grid-2">
                <div class="form-group">
                    <label>Client ID</label>
                    <input type="text" name="linkedin_client_id" class="form-control" value="<?= e($current_config['linkedin_client_id'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Client Secret</label>
                    <input type="password" name="linkedin_client_secret" class="form-control" value="<?= e($current_config['linkedin_client_secret'] ?? '') ?>">
                </div>
            </div>
            <div class="text-sm text-muted">Redirect URI: <code><?= e(config('app_url')) ?>/api/oauth/linkedin-callback.php</code></div>
            <div class="text-sm mt-2"><?= !empty($current_config['linkedin_client_id']) ? '<span style="color:var(--success)">✓ Configured</span>' : '<span style="color:#94a3b8">✗ Not set</span>' ?></div>
        </div>

        <!-- Twitter -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;"><span>🐦 Twitter / X</span><span style="background:#94a3b8;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">Optional</span></div>
            <p class="text-sm text-muted mb-2">Auto-post tweets. Setup: <a href="https://developer.x.com" target="_blank">developer.x.com</a> → Create project + app → OAuth2 settings.</p>
            <div class="grid-2">
                <div class="form-group">
                    <label>Client ID</label>
                    <input type="text" name="twitter_client_id" class="form-control" value="<?= e($current_config['twitter_client_id'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Client Secret</label>
                    <input type="password" name="twitter_client_secret" class="form-control" value="<?= e($current_config['twitter_client_secret'] ?? '') ?>">
                </div>
            </div>
            <div class="text-sm text-muted">Redirect URI: <code><?= e(config('app_url')) ?>/api/oauth/twitter-callback.php</code></div>
            <div class="text-sm mt-2"><?= !empty($current_config['twitter_client_id']) ? '<span style="color:var(--success)">✓ Configured</span>' : '<span style="color:#94a3b8">✗ Not set</span>' ?></div>
        </div>

        <!-- Facebook + Instagram -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;"><span>📘 Facebook + Instagram</span><span style="background:#94a3b8;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">Optional</span></div>
            <p class="text-sm text-muted mb-2">Auto-post to Facebook Pages + Instagram Business. Setup: <a href="https://developers.facebook.com" target="_blank">developers.facebook.com</a> → Create app (Business type) → Add "Facebook Login".</p>
            <div class="grid-2">
                <div class="form-group">
                    <label>App ID</label>
                    <input type="text" name="facebook_app_id" class="form-control" value="<?= e($current_config['facebook_app_id'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>App Secret</label>
                    <input type="password" name="facebook_app_secret" class="form-control" value="<?= e($current_config['facebook_app_secret'] ?? '') ?>">
                </div>
            </div>
            <div class="text-sm text-muted">Redirect URI: <code><?= e(config('app_url')) ?>/api/oauth/facebook-callback.php</code></div>
            <div class="text-sm mt-2"><?= !empty($current_config['facebook_app_id']) ? '<span style="color:var(--success)">✓ Configured</span>' : '<span style="color:#94a3b8">✗ Not set</span>' ?></div>
        </div>

        <!-- Stripe -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;"><span>💳 Stripe Billing</span><span style="background:#94a3b8;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">Optional</span></div>
            <p class="text-sm text-muted mb-2">For SaaS billing (when ready to charge customers). Setup: <a href="https://dashboard.stripe.com" target="_blank">dashboard.stripe.com</a> → Developers → API Keys.</p>
            <div class="grid-2">
                <div class="form-group">
                    <label>Secret Key</label>
                    <input type="password" name="stripe_secret_key" class="form-control" value="<?= e($current_config['stripe_secret_key'] ?? '') ?>" placeholder="sk_live_...">
                </div>
                <div class="form-group">
                    <label>Webhook Secret</label>
                    <input type="password" name="stripe_webhook_secret" class="form-control" value="<?= e($current_config['stripe_webhook_secret'] ?? '') ?>" placeholder="whsec_...">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-accent" style="padding:10px 24px;">Save All API Keys</button>
    </div>
</form>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../templates/dashboard/layout.php';

/**
 * Export array as clean PHP code (not var_export's ugly format).
 */
function var_export_short(array $arr, int $indent = 1): string
{
    $pad = str_repeat('    ', $indent);
    $padOuter = str_repeat('    ', $indent - 1);
    $lines = ["["];

    foreach ($arr as $key => $value) {
        $k = var_export($key, true);
        if (is_array($value)) {
            $v = var_export_short($value, $indent + 1);
        } elseif (is_bool($value)) {
            $v = $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            $v = 'null';
        } elseif (is_int($value) || is_float($value)) {
            $v = $value;
        } else {
            $v = var_export($value, true);
        }
        $lines[] = "{$pad}{$k} => {$v},";
    }

    $lines[] = "{$padOuter}]";
    return implode("\n", $lines);
}
