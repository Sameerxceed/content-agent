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

ob_start();
?>

<!-- Tabs -->
<div class="flex gap-2 mb-4" style="border-bottom:2px solid var(--border);padding-bottom:8px;">
    <a href="<?= url('/dashboard/settings.php?tab=profile') ?>" class="btn btn-sm <?= $tab === 'profile' ? 'btn-primary' : 'btn-outline' ?>" style="text-decoration:none;">Profile</a>
    <a href="<?= url('/dashboard/settings.php?tab=api') ?>" class="btn btn-sm <?= $tab === 'api' ? 'btn-primary' : 'btn-outline' ?>" style="text-decoration:none;">API Keys & Integrations</a>
</div>

<?php if ($tab === 'profile'): ?>
<div style="max-width: 500px;">
    <div class="card">
        <div class="card-header">Profile</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profile">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" class="form-control" value="<?= e($user['name']) ?>" required>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control" value="<?= e($user['email']) ?>" required>
            </div>
            <div class="form-group">
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
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" class="form-control" required minlength="8">
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Change Password</button>
        </form>
    </div>
</div>

<?php elseif ($tab === 'api'): ?>
<form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="api_keys">

    <div style="max-width:700px;">
        <!-- AI -->
        <div class="card">
            <div class="card-header">🤖 AI Engine (Claude Haiku)</div>
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

        <!-- Google -->
        <div class="card">
            <div class="card-header">📊 Google Search Console</div>
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
            <div class="card-header">💼 LinkedIn</div>
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
            <div class="card-header">🐦 Twitter / X</div>
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
            <div class="card-header">📘 Facebook + Instagram</div>
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
            <div class="card-header">💳 Stripe Billing</div>
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
