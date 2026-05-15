<?php
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_start();
if (auth_check()) {
    redirect('/dashboard/index.php');
}

$bp = base_path();
$error = '';
$name_v  = '';
$email_v = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $name_v   = trim($_POST['name'] ?? '');
        $email_v  = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm'] ?? '';

        if ($name_v === '' || $email_v === '' || $password === '') {
            $error = 'Name, email, and password are all required.';
        } elseif (!filter_var($email_v, FILTER_VALIDATE_EMAIL)) {
            $error = 'That doesn\'t look like a valid email.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords don\'t match.';
        } else {
            $db = require __DIR__ . '/../../includes/db.php';

            $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email_v]);
            if ($stmt->fetch()) {
                $error = 'An account with that email already exists. Try signing in instead.';
            } else {
                $hash = auth_hash_password($password);
                try {
                    $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, plan, role, is_super_admin, is_active)
                                          VALUES (?, ?, ?, 'starter', 'owner', 0, 1)");
                    $stmt->execute([$name_v, $email_v, $hash]);
                    $new_id = (int)$db->lastInsertId();

                    auth_set_session([
                        'id'             => $new_id,
                        'email'          => $email_v,
                        'name'           => $name_v,
                        'plan'           => 'starter',
                        'is_super_admin' => 0,
                    ]);
                    redirect('/dashboard/onboarding.php');
                    return;
                } catch (PDOException $e) {
                    error_log('[signup] ' . $e->getMessage());
                    $error = 'Could not create the account. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign up — ContentAgent</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1B3A6B; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .login-box { background: #fff; border-radius: 8px; padding: 32px; width: 100%; max-width: 380px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .login-logo { text-align: center; margin-bottom: 20px; }
        .login-logo img { max-width: 160px; height: auto; }
        .login-title { text-align: center; font-size: 14px; color: #64748b; margin-bottom: 20px; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: #2c3e50; margin-bottom: 4px; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; color: #2c3e50; transition: border-color 0.15s; }
        .form-control:focus { outline: none; border-color: #1B3A6B; box-shadow: 0 0 0 3px rgba(27, 58, 107, 0.1); }
        .btn-login { width: 100%; padding: 10px; background: #CC3300; color: #fff; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.15s; margin-top: 4px; }
        .btn-login:hover { background: #a82a00; }
        .error { background: #fecaca; color: #991b1b; padding: 8px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border: 1px solid #fca5a5; }
        .hint { font-size: 11px; color: #94a3b8; margin-top: 3px; }
        .footer-text { text-align: center; font-size: 11px; color: #94a3b8; margin-top: 16px; }
    </style>
</head>
<body>
<div class="login-box">
    <div class="login-logo">
        <img src="<?= $bp ?>/dashboard/assets/img/logo.png" alt="ContentAgent">
    </div>
    <div class="login-title">Create your ContentAgent account</div>

    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="name">Your name</label>
            <input type="text" id="name" name="name" class="form-control" value="<?= e($name_v) ?>" required autofocus>
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control" value="<?= e($email_v) ?>" required>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" class="form-control" required minlength="8">
            <div class="hint">At least 8 characters.</div>
        </div>

        <div class="form-group">
            <label for="confirm">Confirm password</label>
            <input type="password" id="confirm" name="confirm" class="form-control" required minlength="8">
        </div>

        <button type="submit" class="btn-login">Create account</button>
    </form>

    <div style="text-align:center; font-size:12px; color:#64748b; margin-top:16px;">
        Already have an account?
        <a href="<?= $bp ?>/auth/login.php" style="color:#1B3A6B; text-decoration:none; font-weight:600;">Sign in</a>
    </div>

    <div class="footer-text">Powered by Xceed Imagination</div>
</div>
</body>
</html>
