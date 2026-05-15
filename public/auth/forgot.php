<?php
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mailer.php';

auth_start();
if (auth_check()) {
    redirect('/dashboard/index.php');
}

$bp = base_path();
$message = '';
$is_error = false;
$email_v = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $message = 'Invalid form submission. Please try again.'; $is_error = true;
    } else {
        $email_v = strtolower(trim($_POST['email'] ?? ''));
        if ($email_v === '' || !filter_var($email_v, FILTER_VALIDATE_EMAIL)) {
            $message = 'Enter a valid email.'; $is_error = true;
        } else {
            $db = require __DIR__ . '/../../includes/db.php';
            $stmt = $db->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email_v]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate single-use token, 60-min TTL
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600);
                $db->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)')
                   ->execute([$user['id'], $token, $expires]);

                $app_url = config('app_url') ?: 'https://contentagent.xceedtech.in';
                $reset_url = $app_url . '/auth/reset.php?token=' . urlencode($token);

                $body = '<p>Hi ' . htmlspecialchars($user['name'] ?: 'there', ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p>We received a request to reset your ContentAgent password. Click the button below to choose a new one — the link is valid for 60 minutes.</p>'
                    . '<p style="margin:18px 0;"><a href="' . htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:10px 18px;background:#CC3300;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:13px;">Reset password</a></p>'
                    . '<p style="font-size:12px;color:#64748b;">If you didn\'t request this, ignore this email — your password stays the same.</p>'
                    . '<p style="font-size:11px;color:#94a3b8;word-break:break-all;">Or copy this link:<br>' . htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8') . '</p>';

                $html = mailer_wrap('Reset your ContentAgent password', $body);
                $result = mailer_send($email_v, 'Reset your ContentAgent password', $html);
                if (empty($result['success'])) {
                    error_log('[forgot] mailer failed: ' . ($result['error'] ?? 'unknown'));
                }
            }

            // Always show the same message to avoid leaking which emails are registered
            $message = 'If an account exists for that email, a reset link has been sent. Check your inbox in a minute.';
            $is_error = false;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset password — ContentAgent</title>
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
        .success { background: #d1fae5; color: #065f46; padding: 8px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border: 1px solid #6ee7b7; }
        .footer-text { text-align: center; font-size: 11px; color: #94a3b8; margin-top: 16px; }
    </style>
</head>
<body>
<div class="login-box">
    <div class="login-logo">
        <img src="<?= $bp ?>/dashboard/assets/img/logo.png" alt="ContentAgent">
    </div>
    <div class="login-title">Reset your password</div>

    <?php if ($message): ?>
        <div class="<?= $is_error ? 'error' : 'success' ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control" value="<?= e($email_v) ?>" required autofocus>
        </div>
        <button type="submit" class="btn-login">Send reset link</button>
    </form>

    <div style="text-align:center; font-size:12px; color:#64748b; margin-top:16px;">
        <a href="<?= $bp ?>/auth/login.php" style="color:#1B3A6B; text-decoration:none;">← Back to sign in</a>
    </div>

    <div class="footer-text">Powered by Xceed Imagination</div>
</div>
</body>
</html>
