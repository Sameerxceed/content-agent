<?php
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (auth_check()) {
    redirect('/dashboard/index.php');
}

$bp = base_path();
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$token_ok = false;
$user_row = null;

if ($token !== '') {
    $db = require __DIR__ . '/../../includes/db.php';
    $stmt = $db->prepare('
        SELECT pr.id AS pr_id, pr.user_id, pr.expires_at, pr.used_at,
               u.name, u.email, u.plan, u.is_super_admin
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.token = ?
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset) {
        $error = 'This reset link is invalid. Request a new one.';
    } elseif (!empty($reset['used_at'])) {
        $error = 'This reset link has already been used. Request a new one.';
    } elseif (strtotime($reset['expires_at']) < time()) {
        $error = 'This reset link has expired. Request a new one.';
    } else {
        $token_ok = true;
        $user_row = $reset;
    }
} else {
    $error = 'Missing token. Request a new reset link.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_ok) {
    if (!csrf_verify()) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm'] ?? '';
        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords don\'t match.';
        } else {
            $hash = auth_hash_password($password);
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
               ->execute([$hash, $user_row['user_id']]);
            $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
               ->execute([$user_row['pr_id']]);

            auth_set_session([
                'id'             => $user_row['user_id'],
                'email'          => $user_row['email'],
                'name'           => $user_row['name'],
                'plan'           => $user_row['plan'],
                'is_super_admin' => $user_row['is_super_admin'],
            ]);
            redirect('/dashboard/index.php');
            return;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose a new password — ContentAgent</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1B3A6B; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .login-box { background: #fff; border-radius: 8px; padding: 32px; width: 100%; max-width: 380px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .login-logo { text-align: center; margin-bottom: 20px; }
        .login-logo img { max-width: 160px; height: auto; }
        .login-title { text-align: center; font-size: 14px; color: #64748b; margin-bottom: 20px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: #2c3e50; margin-bottom: 4px; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; color: #2c3e50; }
        .form-control:focus { outline: none; border-color: #1B3A6B; box-shadow: 0 0 0 3px rgba(27, 58, 107, 0.1); }
        .btn-login { width: 100%; padding: 10px; background: #CC3300; color: #fff; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-login:hover { background: #a82a00; }
        .error { background: #fecaca; color: #991b1b; padding: 8px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border: 1px solid #fca5a5; }
        .footer-text { text-align: center; font-size: 11px; color: #94a3b8; margin-top: 16px; }
        .hint { font-size: 11px; color: #94a3b8; margin-top: 3px; }
    </style>
</head>
<body>
<div class="login-box">
    <div class="login-logo">
        <img src="<?= $bp ?>/dashboard/assets/img/logo.png" alt="ContentAgent">
    </div>
    <div class="login-title">Choose a new password</div>

    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($token_ok): ?>
    <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="form-group">
            <label for="password">New password</label>
            <input type="password" id="password" name="password" class="form-control" required minlength="8" autofocus>
            <div class="hint">At least 8 characters.</div>
        </div>

        <div class="form-group">
            <label for="confirm">Confirm new password</label>
            <input type="password" id="confirm" name="confirm" class="form-control" required minlength="8">
        </div>

        <button type="submit" class="btn-login">Update password &amp; sign in</button>
    </form>
    <?php else: ?>
    <div style="text-align:center; margin-top:14px;">
        <a href="<?= $bp ?>/auth/forgot.php" style="color:#1B3A6B; text-decoration:none; font-weight:600;">Request a new reset link →</a>
    </div>
    <?php endif; ?>

    <div style="text-align:center; font-size:12px; color:#64748b; margin-top:16px;">
        <a href="<?= $bp ?>/auth/login.php" style="color:#1B3A6B; text-decoration:none;">← Back to sign in</a>
    </div>

    <div class="footer-text">Powered by Xceed Imagination</div>
</div>
</body>
</html>
